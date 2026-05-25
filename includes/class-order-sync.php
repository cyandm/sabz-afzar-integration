<?php
if (!defined('ABSPATH')) exit;

class SAI_Order_Sync
{
    private $api_service;

    public function __construct()
    {
        $this->api_service = new SAI_API_Service();

        add_action('user_register', [$this, 'sync_new_user_to_api'], 10, 1);
        add_action('woocommerce_order_status_processing', [$this, 'sync_order_to_api'], 10, 1);
    }

    private function log($message, $data = [])
    {
        $log_entry = '[SabzAfzar Integration] ' . $message;
        if (!empty($data)) {
            $log_entry .= ': ' . print_r($data, true);
        }
        error_log($log_entry);
    }

    /**
     * ارسال کاربر به API و برگرداندن PersonId
     * در صورت موفقیت عدد PersonId برمی‌گردد، در صورت خطا null
     */
    private function send_customer_to_api($payload)
    {
        $this->log('Sending customer to API', $payload);

        $response = $this->api_service->add_customer($payload);

        if (is_wp_error($response)) {
            $this->log('add_customer WP_Error: ' . $response->get_error_message());
            return null;
        }

        $this->log('add_customer response', $response);

        // مستندات: فیلد خروجی Id است
        if (is_array($response) && isset($response['Id'])) {
            return (int) $response['Id'];
        }

        // برخی پیاده‌سازی‌ها مستقیماً عدد برمی‌گردانند
        if (is_numeric($response)) {
            return (int) $response;
        }

        return null;
    }

    public function sync_new_user_to_api($user_id)
    {
        $payload = [
            'firstName'          => get_user_meta($user_id, 'first_name', true),
            'lastName'           => get_user_meta($user_id, 'last_name', true),
            'mobileNo'           => get_user_meta($user_id, 'billing_phone', true),
            'email'              => '',
            'introducerMobileNo' => '',
            'isMale'             => false,
            'nationalCode'       => '',
        ];

        $this->send_customer_to_api($payload);
    }

    public function sync_order_to_api($order_id)
    {
        $order = wc_get_order($order_id);

        if (!$order) {
            $this->log("Order not found: $order_id");
            return;
        }

        // ۱. ارسال مشتری به API و دریافت PersonId
        $person_id = null;
        $user_id   = $order->get_user_id();

        if ($user_id > 0) {
            $customer_payload = [
                'firstName'          => get_user_meta($user_id, 'first_name', true),
                'lastName'           => get_user_meta($user_id, 'last_name', true),
                'mobileNo'           => get_user_meta($user_id, 'billing_phone', true),
                'email'              => '',
                'introducerMobileNo' => '',
                'isMale'             => false,
                'nationalCode'       => '',
            ];
        } else {
            // کاربر مهمان
            $customer_payload = [
                'firstName'          => $order->get_billing_first_name(),
                'lastName'           => $order->get_billing_last_name(),
                'mobileNo'           => $order->get_billing_phone(),
                'email'              => '',
                'introducerMobileNo' => '',
                'isMale'             => false,
                'nationalCode'       => '',
            ];
        }

        $person_id = $this->send_customer_to_api($customer_payload);

        if (!$person_id) {
            $this->log("Could not get PersonId for order $order_id – proceeding without PersonId");
        }

        // ۲. ساخت آیتم‌های فاکتور مطابق FactorTransDetailModel
        $factor_details = [];
        foreach ($order->get_items() as $item) {
            $product  = $item->get_product();
            $quantity = (float) $item->get_quantity();
            $subtotal = (float) $item->get_subtotal(); // بدون تخفیف
            $total    = (float) $item->get_total();    // بعد از تخفیف

            $unit_price      = $quantity > 0 ? round($subtotal / $quantity, 2) : 0;
            $discount_amount = $subtotal - $total;
            $discount_pct    = ($subtotal > 0) ? round(($discount_amount / $subtotal) * 100, 2) : 0;

            $factor_details[] = [
                'GoodId'          => 0,   // WooCommerce GoodId ندارد؛ اگر mapping داری اینجا بگذار
                'GoodCode'        => $product ? $product->get_sku() : '',
                'Quantity'        => $quantity,
                'UnitPrice'       => $unit_price,
                'DiscountPercent' => $discount_pct,
                'VATPercent'      => 0,
                'TollPercent'     => 0,
                'Comment'         => '',
            ];
        }

        // ۳. ساخت payload مطابق FactorDocTransModel
        $now          = current_time('timestamp');
        $issue_date   = date('Y/m/d', $now);
        $issue_time   = date('H:i:s', $now);
        $issue_dt     = date('Y-m-d\TH:i:s', $now);

        $factor_payload = [
            'DocNo'              => (string) $order_id,
            'PersonId'           => $person_id,        // null اگر ثبت نشد
            'IssueDate'          => $issue_date,
            'IssueTime'          => $issue_time,
            'Comment'            => sprintf('WooCommerce Order #%d', $order_id),
            'TotalPrice'         => (float) $order->get_total(),
            'DiscountAmount'     => (float) $order->get_discount_total(),
            'UserDiscount'       => 0,
            'DeliveryType'       => 1,  // 0=حضوری  1=ارسالی
            'VAT'                => (float) $order->get_total_tax(),
            'Toll'               => 0,
            'CreditCardAmount'   => 0,
            'PayAmount'          => (float) $order->get_total(),
            'IssueDateTime'      => $issue_dt,
            'factorTransDetails' => $factor_details,
        ];

        $this->log("Sending factor for order $order_id", $factor_payload);

        $response = $this->api_service->add_factor($factor_payload);

        if (is_wp_error($response)) {
            $this->log("add_factor WP_Error for order $order_id: " . $response->get_error_message());
            return;
        }

        $this->log("add_factor response for order $order_id", $response);

        // ذخیره شناسه فاکتور روی سفارش
        $factor_id = null;
        if (is_array($response) && isset($response['Id'])) {
            $factor_id = $response['Id'];
        } elseif (is_numeric($response)) {
            $factor_id = $response;
        }

        if ($factor_id) {
            $order->update_meta_data('_sai_factor_id', $factor_id);
            $order->save();
            $this->log("Factor ID $factor_id saved on order $order_id");
        }
    }
}

new SAI_Order_Sync();
