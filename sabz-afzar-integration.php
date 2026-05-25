<?php

/**
 * Plugin Name: همگام سازی سبز
 * Description: همگام سازی محصولات، ساخت حساب کاربری و فاکتور در نرم افزار حسابداری سبز
 * Version: 1.0.0
 * Author: Amirali Dizabadi
 * Text Domain: sabz-afzar-integration
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SAI_VERSION', '1.0.0');
define('SAI_PLUGIN_FILE', __FILE__);
define('SAI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SAI_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once SAI_PLUGIN_DIR . 'includes/class-api-service.php';
require_once SAI_PLUGIN_DIR . 'includes/class-admin-handler.php';
require_once SAI_PLUGIN_DIR . 'includes/class-woo-integration.php';
require_once SAI_PLUGIN_DIR . 'includes/class-order-sync.php';

final class Sabz_Afzar_Integration
{

    private static $instance = null;

    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('plugins_loaded', [$this, 'init']);
        register_activation_hook(SAI_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(SAI_PLUGIN_FILE, [$this, 'deactivate']);

        // هوک اجرای کرون جاب WordPress
        add_action('sai_hourly_product_sync', [$this, 'run_cron_product_sync']);
    }

    public function init()
    {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', [$this, 'woocommerce_notice']);
            return;
        }

        new SAI_Admin_Handler();
        new SAI_Woo_Integration();
        new SAI_Order_Sync();
    }

    public function activate()
    {
        $defaults = [
            'sai_branch_code'              => '',
            'sai_api_base_url'             => 'http://localhost:4217',
            'sai_fixed_token'              => 'PUT_YOUR_STATIC_TOKEN_HERE',
            'sai_enable_customer_sync'     => 'yes',
            'sai_enable_factor_creation'   => 'yes',
            'sai_enable_price_sync'        => 'yes',
            'sai_enable_stock_sync'        => 'yes',
            'sai_enable_auto_sync'         => 'yes',
            'sai_auto_sync_interval'       => 'hourly',
            'sai_use_compressed_endpoint'  => 'no',
        ];

        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }

        // ثبت کرون جاب هنگام فعال‌سازی افزونه
        if (!wp_next_scheduled('sai_hourly_product_sync')) {
            wp_schedule_event(time(), 'hourly', 'sai_hourly_product_sync');
        }
    }

    public function deactivate()
    {
        // حذف کرون جاب هنگام غیرفعال‌سازی افزونه
        $timestamp = wp_next_scheduled('sai_hourly_product_sync');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'sai_hourly_product_sync');
        }
    }

    /**
     * اجرای همگام‌سازی محصولات توسط کرون جاب
     * ابتدا کش می‌گیرد، سپس دسته‌ای import می‌کند
     */
    public function run_cron_product_sync()
    {
        error_log('[SAI_CRON] Hourly product sync started at ' . date('Y-m-d H:i:s'));

        if (!class_exists('WooCommerce')) {
            error_log('[SAI_CRON] WooCommerce not active, aborting.');
            return;
        }

        $woo = new SAI_Woo_Integration();

        // مرحله ۱: دریافت محصولات از API و ذخیره در کش
        $cache_result = $woo->fetch_and_cache_products();

        if (is_wp_error($cache_result)) {
            error_log('[SAI_CRON] fetch_and_cache_products failed: ' . $cache_result->get_error_message());
            return;
        }

        $total  = $cache_result['total'] ?? 0;
        $offset = 0;
        $limit  = 20;
        $batch  = 0;

        error_log("[SAI_CRON] Cache ready. Total jobs: $total");

        // مرحله ۲: پردازش دسته‌ای تا تمام شود
        do {
            $batch++;
            error_log("[SAI_CRON] Batch $batch | offset=$offset | limit=$limit");

            $result = $woo->sync_products_from_cache_batch($offset, $limit);

            if (is_wp_error($result)) {
                error_log('[SAI_CRON] Batch error: ' . $result->get_error_message());
                break;
            }

            $offset   = $result['next_offset'] ?? ($offset + $limit);
            $has_more = $result['has_more'] ?? false;

        } while ($has_more);

        error_log('[SAI_CRON] Hourly product sync finished. Batches: ' . $batch);
    }

    public function woocommerce_notice()
    {
        echo '<div class="notice notice-error"><p>Sabz Afzar Integration requires WooCommerce.</p></div>';
    }
}

Sabz_Afzar_Integration::instance();
