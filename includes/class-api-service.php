<?php

if (!defined('ABSPATH')) {
    exit;
}

class SAI_API_Service
{

    private $base_url;
    private $token;
    private $branch_code;

    public function __construct()
    {
        $this->base_url    = untrailingslashit(get_option('sai_api_base_url', 'http://localhost:4217'));
        $this->token       = get_option('sai_fixed_token', 'PUT_YOUR_STATIC_TOKEN_HERE');
        $this->branch_code = get_option('sai_branch_code', '');
    }

    private function get_headers($json = true)
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->token,
        ];

        if ($json) {
            $headers['Content-Type'] = 'application/json';
        }

        return $headers;
    }

    private function request($method, $endpoint, $body = null, $compressed = false)
    {
        $url = $this->base_url . '/' . ltrim($endpoint, '/');

        error_log('[SAI] REQUEST START');
        error_log('[SAI] Method: ' . strtoupper($method));
        error_log('[SAI] URL: ' . $url);
        error_log('[SAI] Compressed: ' . ($compressed ? 'yes' : 'no'));
        error_log('[SAI] Body: ' . ($body !== null ? wp_json_encode($body) : 'NULL'));

        $args = [
            'method'  => strtoupper($method),
            'headers' => $this->get_headers(true),
            'timeout' => 60,
        ];

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            error_log('[SAI] REQUEST ERROR: ' . $response->get_error_message());
            return new WP_Error('api_error', $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw  = wp_remote_retrieve_body($response);

        error_log('[SAI] HTTP Code: ' . $code);
        error_log('[SAI] Raw Response: ' . $raw);

        if ($code < 200 || $code >= 300) {
            error_log('[SAI] API error, non-2xx response');
            return new WP_Error('sai_api_error', 'API request failed. HTTP code: ' . $code . ' | Body: ' . $raw);
        }

        if ($compressed) {
            if (function_exists('gzdecode')) {
                $decoded = @gzdecode($raw);
                if ($decoded !== false) {
                    error_log('[SAI] gzdecode successful');
                    $raw = $decoded;
                } else {
                    error_log('[SAI] gzdecode failed');
                }
            } else {
                error_log('[SAI] gzdecode function not available');
            }
        }

        $data = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('[SAI] JSON decode error: ' . json_last_error_msg());
            return new WP_Error('sai_json_error', 'Invalid JSON response: ' . json_last_error_msg());
        }

        error_log('[SAI] REQUEST END - Success');

        // ---- FIX: Decode nested "data" field if it's a JSON string ----
        if (is_array($data) && isset($data['data']) && is_string($data['data'])) {
            $nested = json_decode($data['data'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $data = $nested;
                error_log('[SAI] Nested data JSON decoded successfully');
            } else {
                error_log('[SAI] Nested data JSON decode error: ' . json_last_error_msg());
            }
        }

        // If the API returns a wrapper array with just one key 'data', flatten it
        if (is_array($data) && count($data) === 1 && isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }

        return $data;
    }

    public function get_items_qty_and_discount($goodcodes = [])
    {
        $compressed = get_option('sai_use_compressed_endpoint', 'no') === 'yes';

        $endpoint = $compressed
            ? 'api/linkJSONEShopItemQtyAndDiscountCompress?branchCode=' . urlencode($this->branch_code)
            : 'api/linkJSONEShopItemQtyAndDiscount?branchCode=' . urlencode($this->branch_code);

        error_log('[SAI] get_items_qty_and_discount called');
        error_log('[SAI] Compressed mode: ' . ($compressed ? 'yes' : 'no'));
        error_log('[SAI] Endpoint: ' . $endpoint);
        error_log('[SAI] GoodCodes: ' . wp_json_encode($goodcodes));

        return $this->request('POST', $endpoint, $goodcodes, $compressed);
    }

    /**
     * بررسی موجودی اقلام به تعداد درخواستی
     * مستندات: متد GET، پارامتر branchCode در QueryString
     * آرایه items: هر آیتم شامل BarCode (string) و Quantity (double)
     *
     * @param array $items مثال: [['BarCode' => '150', 'Quantity' => 2], ...]
     */
    public function check_item_qty($items = [])
    {
        $endpoint = 'api/linkJSONEShopItemListQtyCheck?branchCode=' . urlencode($this->branch_code);

        // نرمال‌سازی: مستندات می‌گویند BarCode (نه GoodCode) با Quantity
        $normalized = [];
        foreach ($items as $item) {
            $normalized[] = [
                'BarCode'  => $item['BarCode'] ?? ($item['barcode'] ?? ($item['GoodCode'] ?? '')),
                'Quantity' => isset($item['Quantity']) ? (float) $item['Quantity'] : 0,
            ];
        }

        // توجه: مستندات این متد را GET تعریف کرده اما body هم دارد.
        // برای سازگاری بیشتر از POST استفاده می‌کنیم چون GET با body روی برخی سرورها کار نمی‌کند.
        return $this->request('POST', $endpoint, $normalized);
    }

    public function add_customer($args = [])
    {
        $query = http_build_query([
            'firstName'          => $args['firstName'] ?? '',
            'lastName'           => $args['lastName'] ?? '',
            'mobileNo'           => $args['mobileNo'] ?? '',
            'email'              => $args['email'] ?? '',
            'introducerMobileNo' => $args['introducerMobileNo'] ?? '',
            'isMale'             => !empty($args['isMale']) ? 'true' : 'false',
            'nationalCode'       => $args['nationalCode'] ?? '',
        ]);

        $endpoint = 'api/linkJSONEShopAddCustomer?' . $query;

        return $this->request('POST', $endpoint, null);
    }

    /**
     * ایجاد فاکتور فروش موقت
     * ساختار payload باید مطابق FactorDocTransModel باشد
     */
    public function add_factor($payload = [])
    {
        return $this->request('POST', 'api/LinkJSONEShopAddFactor', $payload);
    }

    /**
     * ایجاد فاکتور فروش تایید شده
     * ساختار payload باید مطابق HistFactorDocModel باشد
     */
    public function add_hist_factor($payload = [])
    {
        return $this->request('POST', 'api/linkJSONEShopAddHistFactor', $payload);
    }
}
