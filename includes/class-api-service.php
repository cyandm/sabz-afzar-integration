<?php

if (!defined('ABSPATH')) {
    exit;
}

class SAI_API_Service
{

    private $base_url;
    private $token;
    private $branch_code;

    /**
     * Normalize API base URL: trim, add http:// if missing, validate, esc_url_raw.
     */
    public static function normalize_base_url(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        $raw = untrailingslashit($raw);

        if (!preg_match('#^https?://#i', $raw)) {
            $raw = 'http://' . $raw;
        }

        if (!self::is_plausible_base_host($raw)) {
            return '';
        }

        $sanitized = esc_url_raw($raw);

        return $sanitized !== '' ? untrailingslashit($sanitized) : '';
    }

    /**
     * Display stored URL without scheme for admin input readability.
     */
    public static function format_base_url_for_display(string $stored): string
    {
        $stored = trim($stored);
        if ($stored === '') {
            return '';
        }

        return (string) preg_replace('#^https?://#i', '', untrailingslashit($stored));
    }

    private static function is_plausible_base_host(string $url): bool
    {
        $parts = wp_parse_url($url);
        if (empty($parts['host'])) {
            return false;
        }

        $host = strtolower((string) $parts['host']);

        if ($host === 'localhost') {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }

        return (bool) preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$/i', $host);
    }

    public function __construct()
    {
        $stored_base = get_option('sai_api_base_url', 'http://localhost:4217');
        $normalized  = self::normalize_base_url((string) $stored_base);
        $this->base_url    = $normalized !== '' ? $normalized : 'http://localhost:4217';
        $this->token       = get_option('sai_fixed_token', 'PUT_YOUR_STATIC_TOKEN_HERE');
        $this->branch_code = get_option('sai_branch_code', '');
    }

    /**
     * Request OAuth access token from Sabz POST /TOKEN (manual admin action only).
     *
     * @return string|WP_Error
     */
    public static function request_new_token(string $base_url)
    {
        $base_url = self::normalize_base_url($base_url);

        if ($base_url === '') {
            return new WP_Error('sai_invalid_base_url', 'آدرس API نامعتبر است.');
        }

        $token_path = apply_filters('sai_token_path', 'TOKEN');
        $url        = untrailingslashit($base_url) . '/' . ltrim($token_path, '/');

        $username = (string) apply_filters('sai_token_username', 'greenware');
        $password = (string) apply_filters('sai_token_password', 'Aa12345@');

        $body_variants = apply_filters(
            'sai_token_request_bodies',
            [
                [
                    'grant_type' => 'password',
                    'userName'   => $username,
                    'password'   => $password,
                ],
                [
                    'grant_type' => 'password',
                    'username'   => $username,
                    'password'   => $password,
                ],
            ],
            $username,
            $password
        );

        error_log('[SAI] TOKEN REQUEST | URL: ' . $url);

        $last_error = null;

        foreach ($body_variants as $index => $body) {
            if (!is_array($body) || empty($body['grant_type'])) {
                continue;
            }

            $response = wp_remote_post($url, [
                'timeout' => 30,
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept'       => 'application/json',
                ],
                'body'    => $body,
            ]);

            if (is_wp_error($response)) {
                $last_error = $response;
                error_log('[SAI] TOKEN RESPONSE | variant=' . $index . ' | transport error');
                continue;
            }

            $code = wp_remote_retrieve_response_code($response);
            $raw  = wp_remote_retrieve_body($response);

            error_log('[SAI] TOKEN RESPONSE | variant=' . $index . ' | HTTP: ' . $code);

            if ($code < 200 || $code >= 300) {
                $message = 'دریافت توکن از /TOKEN ناموفق بود. HTTP ' . $code;
                if ($code === 401) {
                    $message .= ' — نام کاربری یا رمز OAuth اشتباه است.';
                } elseif ($code === 404) {
                    $message .= ' — مسیر /TOKEN روی این سرور یافت نشد.';
                }
                $message .= ' | ' . $raw;

                $last_error = new WP_Error('sai_token_http_error', $message);
                continue;
            }

            $token = self::extract_token_from_response($raw);
            if ($token !== '') {
                return $token;
            }

            $last_error = new WP_Error(
                'sai_token_parse_error',
                'پاسخ /TOKEN فیلد access_token نداشت: ' . $raw
            );
        }

        if ($last_error instanceof WP_Error) {
            return $last_error;
        }

        return new WP_Error('sai_token_unknown', 'دریافت توکن از /TOKEN ناموفق بود.');
    }

    /**
     * @return string
     */
    private static function extract_token_from_response(string $raw)
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            if (preg_match('/^[\w\-\.]+$/u', $raw) && strlen($raw) >= 8) {
                return $raw;
            }

            return '';
        }

        if (is_string($data)) {
            return trim($data);
        }

        $candidates = [
            $data['access_token'] ?? null,
            $data['accessToken'] ?? null,
            $data['token'] ?? null,
            $data['Token'] ?? null,
        ];

        if (is_array($data['data'] ?? null)) {
            $nested = $data['data'];
            $candidates[] = $nested['access_token'] ?? null;
            $candidates[] = $nested['accessToken'] ?? null;
            $candidates[] = $nested['token'] ?? null;
            $candidates[] = $nested['Token'] ?? null;
        } elseif (is_string($data['data'] ?? null)) {
            $nested = json_decode($data['data'], true);
            if (is_array($nested)) {
                $candidates[] = $nested['token'] ?? null;
                $candidates[] = $nested['Token'] ?? null;
            } else {
                $candidates[] = $data['data'];
            }
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
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

    private function request($method, $endpoint, $body = null, $compressed = false, $json_headers = true)
    {
        $url = $this->base_url . '/' . ltrim($endpoint, '/');

        error_log('[SAI] REQUEST START');
        error_log('[SAI] Method: ' . strtoupper($method));
        error_log('[SAI] URL: ' . $url);
        error_log('[SAI] Compressed: ' . ($compressed ? 'yes' : 'no'));
        error_log('[SAI] Body: ' . ($body !== null ? wp_json_encode($body) : 'NULL'));

        $args = [
            'method'  => strtoupper($method),
            'headers' => $this->get_headers($json_headers),
            'timeout' => 60,
        ];

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        error_log('[SAI] Headers: ' . wp_json_encode($args['headers']));

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

    /**
     * QueryString مشتری — ۶ پارامتر Postman AddPerson (MyEshop collection).
     * nationalCode فقط در پاسخ API است، نه در query.
     *
     * نمونه: firstName=...&lastName=...&mobileNo=9302365110&email=null&introducerMobileNo=''&isMale=true
     */
    private function build_customer_query_string(array $args): string
    {
        return implode('&', [
            'firstName=' . rawurlencode(trim((string) ($args['firstName'] ?? ''))),
            'lastName=' . rawurlencode(trim((string) ($args['lastName'] ?? ''))),
            'mobileNo=' . rawurlencode(trim((string) ($args['mobileNo'] ?? ''))),
            'email=null',
            "introducerMobileNo=''",
            'isMale=true',
        ]);
    }

    /**
     * Postman AddPerson: POST بدون body و بدون Content-Type (json_headers=false).
     */
    public function add_customer($args = [])
    {
        $query    = $this->build_customer_query_string($args);
        $endpoint = 'api/linkJSONEShopAddCustomer?' . $query;

        error_log('[SAI] AddCustomer query: ' . $query);

        return $this->request('POST', $endpoint, null, false, false);
    }

    /**
     * ایجاد فاکتور فروش تایید شده
     * ساختار payload باید مطابق HistFactorDocModel باشد
     */
    public function add_hist_factor($payload = [])
    {
        return $this->request('POST', 'api/LinkJSONEShopAddHistFactor', $payload);
    }
}
