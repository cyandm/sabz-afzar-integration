<?php
if (!defined('ABSPATH')) {
    exit;
}

class SAI_Admin_Handler
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_post_sai_save_settings', [$this, 'save_settings']);

        add_action('wp_ajax_sai_manual_sync', [$this, 'ajax_manual_sync']);
        add_action('wp_ajax_sai_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_sai_remediate_variations', [$this, 'ajax_remediate_variations']);
        add_action('wp_ajax_sai_refresh_token', [$this, 'ajax_refresh_token']);
    }

    public function register_menu()
    {
        add_menu_page(
            'Sabz Afzar Integration',
            'همگام سازی سبز',
            'manage_woocommerce',
            'sabz-afzar-integration',
            [$this, 'render_dashboard'],
            'dashicons-update',
            56
        );
    }

    public function enqueue_assets($hook)
    {
        if ($hook !== 'toplevel_page_sabz-afzar-integration') {
            return;
        }

        wp_enqueue_style(
            'sai-admin-style',
            SAI_PLUGIN_URL . 'assets/css/admin-style.css',
            [],
            SAI_VERSION
        );

        wp_enqueue_script(
            'sai-admin-script',
            SAI_PLUGIN_URL . 'assets/js/admin-script.js',
            ['jquery'],
            SAI_VERSION,
            true
        );

        wp_localize_script('sai-admin-script', 'saiAdmin', [
            'ajaxUrl'            => admin_url('admin-ajax.php'),
            'nonce'              => wp_create_nonce('sai_admin_nonce'),
            'syncBatchSize'      => Sabz_Afzar_Integration::get_sync_batch_size(),
            'lastAutoSyncReport' => SAI_Cron_Activity_Log::get_ui_payload(),
        ]);
    }

    public function render_dashboard()
    {
        include SAI_PLUGIN_DIR . 'templates/admin-dashboard.php';
    }

    public function save_settings()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('sai_save_settings_nonce');

        update_option('sai_branch_code', sanitize_text_field($_POST['sai_branch_code'] ?? ''));
        update_option('sai_location_code', sanitize_text_field($_POST['sai_location_code'] ?? ''));
        update_option(
            'sai_api_base_url',
            SAI_API_Service::normalize_base_url((string) ($_POST['sai_api_base_url'] ?? ''))
        );
        update_option('sai_fixed_token', sanitize_text_field($_POST['sai_fixed_token'] ?? ''));

        update_option('sai_enable_customer_sync', !empty($_POST['sai_enable_customer_sync']) ? 'yes' : 'no');
        update_option('sai_enable_factor_creation', !empty($_POST['sai_enable_factor_creation']) ? 'yes' : 'no');
        update_option('sai_enable_price_sync', !empty($_POST['sai_enable_price_sync']) ? 'yes' : 'no');
        update_option('sai_enable_stock_sync', !empty($_POST['sai_enable_stock_sync']) ? 'yes' : 'no');
        update_option('sai_enable_auto_sync', !empty($_POST['sai_enable_auto_sync']) ? 'yes' : 'no');
        update_option('sai_use_server_cron', !empty($_POST['sai_use_server_cron']) ? 'yes' : 'no');
        update_option('sai_use_compressed_endpoint', !empty($_POST['sai_use_compressed_endpoint']) ? 'yes' : 'no');
        update_option('sai_auto_sync_interval', sanitize_text_field($_POST['sai_auto_sync_interval'] ?? 'hourly'));
        update_option(
            'sai_sync_batch_size',
            max(1, min(500, (int) ($_POST['sai_sync_batch_size'] ?? 100)))
        );

        update_option(
            'sai_price_unit',
            in_array(($_POST['sai_price_unit'] ?? 'rial'), ['rial', 'toman'], true)
                ? $_POST['sai_price_unit']
                : 'rial'
        );

        Sabz_Afzar_Integration::schedule_product_sync_cron();

        wp_safe_redirect(admin_url('admin.php?page=sabz-afzar-integration&saved=1'));
        exit;
    }

    public function ajax_manual_sync()
    {
        check_ajax_referer('sai_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $offset = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;
        $limit  = isset($_POST['limit'])
            ? max(1, min(500, (int) $_POST['limit']))
            : Sabz_Afzar_Integration::get_sync_batch_size();

        $woo = new SAI_Woo_Integration();

        if ($offset === 0) {
            SAI_Sync_Skip_Log::clear();

            $cache = $woo->fetch_and_cache_products();

            if (is_wp_error($cache)) {
                wp_send_json_error(['message' => $cache->get_error_message()]);
            }

            $force_result = $woo->force_simple_products_from_cache();
            $manual_action_needed = count($force_result['errors'] ?? []);

            if ($manual_action_needed > 0) {
                error_log("[SAI_SYNC] $manual_action_needed product(s) need manual action (variable → simple).");
            }
        }

        $result = $woo->sync_products_from_greenware_batch($offset, $limit);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        error_log('[SAI_SYNC] Returning batch | offset=' . $offset . ' | next=' . $result['next_offset'] . ' | has_more=' . ($result['has_more'] ? 'yes' : 'no'));

        wp_send_json_success($result);
    }

    public function ajax_test_connection()
    {
        check_ajax_referer('sai_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $api = new SAI_API_Service();
        $result = $api->get_items_qty_and_discount([]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => 'Connection OK',
            'count'   => is_array($result) ? count($result) : 0,
        ]);
    }

    public function ajax_remediate_variations()
    {
        check_ajax_referer('sai_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $woo = new SAI_Woo_Integration();
        $result = $woo->remediate_orphan_simple_variations();

        error_log(
            '[SAI_SYNC] Remediation AJAX finished | converted=' . ($result['converted'] ?? 0) .
                ' | skipped=' . ($result['skipped'] ?? 0) .
                ' | errors=' . count($result['errors'] ?? [])
        );

        wp_send_json_success($result);
    }

    public function ajax_refresh_token()
    {
        check_ajax_referer('sai_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $base_url = SAI_API_Service::normalize_base_url((string) ($_POST['sai_api_base_url'] ?? ''));

        if ($base_url === '') {
            wp_send_json_error(['message' => 'آدرس API نامعتبر است. می‌توانید بدون http:// وارد کنید (مثلاً localhost:4217).']);
        }

        $token_result = SAI_API_Service::request_new_token($base_url);

        if (is_wp_error($token_result)) {
            wp_send_json_error(['message' => $token_result->get_error_message()]);
        }

        update_option('sai_api_base_url', $base_url);
        update_option('sai_fixed_token', sanitize_text_field($token_result));

        error_log('[SAI] New API token saved via admin refresh.');

        wp_send_json_success([
            'token'   => $token_result,
            'message' => 'توکن جدید دریافت و ذخیره شد.',
        ]);
    }
}
