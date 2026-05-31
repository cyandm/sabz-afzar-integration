<?php

if (!defined('ABSPATH')) {
    exit;
}

class SAI_Order_Sync
{
    private const META_HIST_FACTOR_SYNCED = '_sai_hist_factor_synced';
    private const META_HIST_FACTOR_ID     = '_sai_hist_factor_id';
    private const META_PERSON_ID          = '_sai_person_id';
    private const USER_META_PERSON_ID     = '_sai_person_id';

    private $api_service;

    public function __construct()
    {
        $this->api_service = new SAI_API_Service();

        add_action('woocommerce_order_status_processing', [$this, 'sync_order_to_api'], 10, 1);
    }

    private function log(string $message, array $data = []): void
    {
        $log_entry = '[SabzAfzar Integration] ' . $message;
        if (!empty($data)) {
            $log_entry .= ': ' . print_r($data, true);
        }
        error_log($log_entry);
    }

    private function is_customer_sync_enabled(): bool
    {
        return get_option('sai_enable_customer_sync', 'yes') === 'yes';
    }

    private function is_factor_creation_enabled(): bool
    {
        return get_option('sai_enable_factor_creation', 'yes') === 'yes';
    }

    /**
     * @param mixed $response
     */
    private function extract_api_id($response): ?int
    {
        if (is_array($response)) {
            if (isset($response['PersonId'])) {
                return (int) $response['PersonId'];
            }
            if (isset($response['Id'])) {
                return (int) $response['Id'];
            }
        }

        if (is_numeric($response)) {
            return (int) $response;
        }

        return null;
    }

    /**
     * فقط ارقام؛ بدون تغییر طول یا حذف صفر
     */
    private function sanitize_mobile_digits(string $mobile): string
    {
        return preg_replace('/\D+/', '', $mobile) ?? '';
    }

    /**
     * mobileNo در AddCustomer: بدون صفر اول
     * 09302365110 → 9302365110 | 9302365110 → 9302365110
     */
    private function mobile_without_leading_zero(string $mobile): string
    {
        $digits = $this->sanitize_mobile_digits($mobile);

        if ($digits === '') {
            return '';
        }

        if (strlen($digits) === 11 && isset($digits[0]) && $digits[0] === '0') {
            $digits = substr($digits, 1);
        }

        return ctype_digit($digits) ? $digits : '';
    }

    /**
     * زمان واقعی (UTC unix) برای تاریخ/ساعت — نه current_time('timestamp') که آفست را دوبار می‌زند.
     */
    private function get_issue_unix_timestamp(WC_Order $order): int
    {
        $created = $order->get_date_created();
        if ($created instanceof WC_DateTime) {
            return $created->getTimestamp();
        }

        return time();
    }

    /**
     * تبدیل تاریخ میلادی به شمسی (فرمت Y/m/d مطابق مستندات API)
     * تاریخ میلادی از timezone سایت وردپرس (مثلاً تهران)
     */
    private function to_jalali_date(int $utc_timestamp): string
    {
        $g_y = (int) wp_date('Y', $utc_timestamp);
        $g_m = (int) wp_date('n', $utc_timestamp);
        $g_d = (int) wp_date('j', $utc_timestamp);

        $g_days_in_month = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $j_days_in_month = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];

        $gy = $g_y - 1600;
        $gm = $g_m - 1;
        $gd = $g_d - 1;

        $g_day_no = (365 * $gy) + intdiv($gy + 3, 4) - intdiv($gy + 99, 100) + intdiv($gy + 399, 400);

        for ($i = 0; $i < $gm; $i++) {
            $g_day_no += $g_days_in_month[$i];
        }

        if ($gm > 1 && (($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0))) {
            $g_day_no++;
        }

        $g_day_no += $gd;
        $j_day_no = $g_day_no - 79;

        $j_np = intdiv($j_day_no, 12053);
        $j_day_no %= 12053;

        $jy = 979 + (33 * $j_np) + (4 * intdiv($j_day_no, 1461));
        $j_day_no %= 1461;

        if ($j_day_no >= 366) {
            $jy += intdiv($j_day_no - 1, 365);
            $j_day_no = ($j_day_no - 1) % 365;
        }

        for ($i = 0; $i < 11 && $j_day_no >= $j_days_in_month[$i]; $i++) {
            $j_day_no -= $j_days_in_month[$i];
        }

        $jm = $i + 1;
        $jd = $j_day_no + 1;

        return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    }

    /**
     * مطابق Postman: new Date().toISOString() → UTC با میلی‌ثانیه و پسوند Z
     * مثال: 2026-05-26T11:45:36.000Z
     */
    private function format_issue_datetime_for_api(int $utc_timestamp): string
    {
        $dt = new DateTime('@' . $utc_timestamp);
        $dt->setTimezone(new DateTimeZone('UTC'));

        return $dt->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * ساعت محلی سایت برای IssueTime (مثلاً 16:11 وقتی UTC همان لحظه 12:41 است)
     */
    private function format_issue_time_for_api(int $utc_timestamp): string
    {
        return wp_date('H:i:s', $utc_timestamp);
    }

    /**
     * تبدیل مبلغ ووکامرس به واحد API (هماهنگ با sai_price_unit در همگام‌سازی محصول)
     */
    private function convert_amount_for_api(float $amount): float
    {
        if (get_option('sai_price_unit', 'rial') === 'toman') {
            return round($amount * 10, 2);
        }

        return round($amount, 2);
    }

    private function is_cash_payment(WC_Order $order): bool
    {
        return in_array($order->get_payment_method(), ['cod', 'bacs', 'cheque'], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function build_customer_payload(WC_Order $order): array
    {
        $user_id = $order->get_user_id();

        if ($user_id > 0) {
            $first_name = (string) get_user_meta($user_id, 'first_name', true);
            $last_name  = (string) get_user_meta($user_id, 'last_name', true);
            $mobile     = $this->mobile_without_leading_zero(
                (string) (get_user_meta($user_id, 'billing_phone', true) ?: $order->get_billing_phone())
            );
        } else {
            $first_name = (string) $order->get_billing_first_name();
            $last_name  = (string) $order->get_billing_last_name();
            $mobile     = $this->mobile_without_leading_zero((string) $order->get_billing_phone());
        }

        // email و introducerMobileNo در SAI_API_Service::build_customer_query_string تنظیم می‌شوند
        return [
            'firstName' => $first_name,
            'lastName'  => $last_name,
            'mobileNo'  => $mobile,
            'isMale'    => true,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function send_customer_to_api(array $payload): ?int
    {
        if ($payload['mobileNo'] === '') {
            $this->log('Customer sync skipped: mobile number is empty', $payload);
            return null;
        }

        $this->log('Sending customer to API', $payload);

        $response = $this->api_service->add_customer($payload);

        if (is_wp_error($response)) {
            $this->log('add_customer WP_Error: ' . $response->get_error_message());
            return null;
        }

        $this->log('add_customer response', is_array($response) ? $response : ['raw' => $response]);

        return $this->extract_api_person_id($response);
    }

    /**
     * PersonId برگشتی از AddCustomer (شناسه داخلی سبز افزار، نه موبایل)
     *
     * @param mixed $response
     */
    private function extract_api_person_id($response): ?int
    {
        if (is_array($response) && isset($response['PersonId'])) {
            return (int) $response['PersonId'];
        }

        return $this->extract_api_id($response);
    }

    /**
     * کش قدیمی افزونه گاهی موبایل را به‌جای PersonId API ذخیره کرده — نامعتبر است.
     */
    private function is_stale_mobile_person_id(int $person_id, WC_Order $order): bool
    {
        $mobile_no = $this->build_customer_payload($order)['mobileNo'] ?? '';
        if ($mobile_no === '') {
            return false;
        }

        return $person_id === (int) $mobile_no;
    }

    private function clear_person_id_cache(WC_Order $order): void
    {
        $order->delete_meta_data(self::META_PERSON_ID);

        $user_id = $order->get_user_id();
        if ($user_id > 0) {
            delete_user_meta($user_id, self::USER_META_PERSON_ID);
        }
    }

    private function get_cached_person_id(WC_Order $order): ?int
    {
        $order_person_id = (int) $order->get_meta(self::META_PERSON_ID, true);
        if ($order_person_id > 0) {
            if ($this->is_stale_mobile_person_id($order_person_id, $order)) {
                $this->log(
                    'Ignoring stale cached PersonId ' . $order_person_id .
                        ' on order ' . $order->get_id() . ' (was saved as mobile, not API id)'
                );
                $this->clear_person_id_cache($order);
            } else {
                return $order_person_id;
            }
        }

        $user_id = $order->get_user_id();
        if ($user_id > 0) {
            $user_person_id = (int) get_user_meta($user_id, self::USER_META_PERSON_ID, true);
            if ($user_person_id > 0) {
                if ($this->is_stale_mobile_person_id($user_person_id, $order)) {
                    $this->log(
                        'Ignoring stale cached PersonId ' . $user_person_id .
                            ' on user ' . $user_id . ' (was saved as mobile, not API id)'
                    );
                    delete_user_meta($user_id, self::USER_META_PERSON_ID);
                } else {
                    return $user_person_id;
                }
            }
        }

        return null;
    }

    private function persist_person_id(WC_Order $order, int $person_id): void
    {
        $order->update_meta_data(self::META_PERSON_ID, $person_id);

        $user_id = $order->get_user_id();
        if ($user_id > 0) {
            update_user_meta($user_id, self::USER_META_PERSON_ID, $person_id);
        }
    }

    private function resolve_person_id(WC_Order $order): ?int
    {
        $cached = $this->get_cached_person_id($order);
        if ($cached !== null) {
            $this->log('Using cached API PersonId ' . $cached . ' for order ' . $order->get_id());
            return $cached;
        }

        if (!$this->is_customer_sync_enabled()) {
            $this->log('PersonId missing for order ' . $order->get_id() . ': customer sync is disabled.');
            return null;
        }

        $payload = $this->build_customer_payload($order);
        if ($payload['mobileNo'] === '') {
            $this->log('PersonId missing for order ' . $order->get_id() . ': billing phone is empty.');
            return null;
        }

        $api_person_id = $this->send_customer_to_api($payload);

        if ($api_person_id === null) {
            $this->log('PersonId missing for order ' . $order->get_id() . ': add_customer did not return PersonId.');
            return null;
        }

        $this->persist_person_id($order, $api_person_id);
        $this->log('Using API PersonId for hist factor: ' . $api_person_id);

        return $api_person_id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function build_hist_factor_details(WC_Order $order): array
    {
        $details = [];

        foreach ($order->get_items() as $item) {
            if (!($item instanceof WC_Order_Item_Product)) {
                continue;
            }

            $product  = $item->get_product();
            $quantity = (float) $item->get_quantity();
            $subtotal = (float) $item->get_subtotal();
            $total    = (float) $item->get_total();

            $unit_price   = $quantity > 0 ? $this->convert_amount_for_api($subtotal / $quantity) : 0.0;
            $discount_pct = $subtotal > 0 ? round((($subtotal - $total) / $subtotal) * 100, 2) : 0.0;

            $good_code = $product ? (string) $product->get_sku() : '';
            if ($good_code === '') {
                continue;
            }

            // مطابق Postman نمونه HistFactor: فقط GoodCode (بدون GoodId)
            $details[] = [
                'GoodCode'        => $good_code,
                'Quantity'        => $quantity,
                'UnitPrice'       => $unit_price,
                'DiscountPercent' => $discount_pct,
                'VATPercent'      => 0.0,
                'TollPercent'     => 0.0,
                'Comment'         => '',
            ];
        }

        return $details;
    }

    /**
     * @return array<string, mixed>
     */
    private function build_hist_factor_payload(WC_Order $order, int $person_id): array
    {
        $timestamp = $this->get_issue_unix_timestamp($order);
        $total     = $this->convert_amount_for_api((float) $order->get_total());
        $is_cash   = $this->is_cash_payment($order);

        $location_code = (string) get_option('sai_location_code', '');
        if ($location_code === '') {
            $location_code = (string) get_option('sai_branch_code', '');
        }

        return [
            'DocNo'                => (string) $order->get_id(),
            'PersonId'             => $person_id,
            'IssueDate'            => $this->to_jalali_date($timestamp),
            'IssueTime'            => $this->format_issue_time_for_api($timestamp),
            'Comment'              => sprintf('WooCommerce Order #%d', $order->get_id()),
            'TotalPrice'           => $total,
            'DiscountAmount'       => $this->convert_amount_for_api((float) $order->get_discount_total()),
            'UserDiscount'         => 0.0,
            'VAT'                  => $this->convert_amount_for_api((float) $order->get_total_tax()),
            'Toll'                 => 0.0,
            'CreditCardAmount'     => $is_cash ? 0.0 : $total,
            'PayAmount'            => $is_cash ? $total : 0.0,
            'IssueDateTime'        => $this->format_issue_datetime_for_api($timestamp),
            'LocationCode'         => $location_code,
            'histFactorDocDetails' => $this->build_hist_factor_details($order),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function send_hist_factor_to_api(int $order_id, WC_Order $order, array $payload): void
    {
        $this->log("Sending confirmed hist factor for order $order_id", $payload);

        $response = $this->api_service->add_hist_factor($payload);

        if (is_wp_error($response)) {
            $this->log("add_hist_factor WP_Error for order $order_id: " . $response->get_error_message());
            return;
        }

        $this->log("add_hist_factor response for order $order_id", is_array($response) ? $response : ['raw' => $response]);

        $factor_id = $this->extract_api_id($response);

        $order->update_meta_data(self::META_HIST_FACTOR_SYNCED, 'yes');

        if ($factor_id !== null) {
            $order->update_meta_data(self::META_HIST_FACTOR_ID, $factor_id);
            $this->log("Hist factor ID $factor_id saved on order $order_id");
        }

        $order->save();
    }

    public function sync_order_to_api(int $order_id): void
    {
        if (!$this->is_customer_sync_enabled() && !$this->is_factor_creation_enabled()) {
            return;
        }

        $order = wc_get_order($order_id);

        if (!$order instanceof WC_Order) {
            $this->log("Order not found: $order_id");
            return;
        }

        if ($order->get_meta(self::META_HIST_FACTOR_SYNCED, true) === 'yes') {
            $this->log("Order $order_id already synced to Sabz Afzar, skipping.");
            return;
        }

        $person_id = $this->resolve_person_id($order);

        if ($this->is_factor_creation_enabled()) {
            if ($person_id === null) {
                $this->log("Confirmed factor not sent for order $order_id: PersonId is required but missing.");
                $order->save();
                return;
            }

            $details = $this->build_hist_factor_details($order);
            if ($details === []) {
                $this->log("Confirmed factor not sent for order $order_id: no line items.");
                $order->save();
                return;
            }

            $this->send_hist_factor_to_api(
                $order_id,
                $order,
                $this->build_hist_factor_payload($order, $person_id)
            );
            return;
        }

        if ($person_id !== null) {
            $order->save();
        }
    }
}
