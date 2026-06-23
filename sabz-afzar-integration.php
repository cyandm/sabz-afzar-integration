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
require_once SAI_PLUGIN_DIR . 'includes/class-sync-lock.php';
require_once SAI_PLUGIN_DIR . 'includes/class-sync-skip-log.php';
require_once SAI_PLUGIN_DIR . 'includes/class-cron-activity-log.php';
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
            'sai_location_code'            => '',
            'sai_api_base_url'             => 'http://localhost:4217',
            'sai_fixed_token'              => 'PUT_YOUR_STATIC_TOKEN_HERE',
            'sai_enable_customer_sync'     => 'yes',
            'sai_enable_factor_creation'   => 'yes',
            'sai_enable_price_sync'        => 'yes',
            'sai_enable_stock_sync'        => 'yes',
            'sai_enable_auto_sync'         => 'no',
            'sai_use_server_cron'          => 'yes',
            'sai_sync_batch_size'          => 100,
            'sai_auto_sync_interval'       => 'hourly',
            'sai_use_compressed_endpoint'  => 'no',
        ];

        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }

        self::schedule_product_sync_cron();

        if (class_exists('WooCommerce') && class_exists('SAI_Woo_Integration')) {
            (new SAI_Woo_Integration())->ensure_default_product_attributes();
        }
    }

    public static function get_sync_batch_size(): int
    {
        $size = (int) get_option('sai_sync_batch_size', 100);
        return max(1, min(500, $size));
    }

    public static function schedule_product_sync_cron(): void
    {
        wp_clear_scheduled_hook('sai_hourly_product_sync');

        if (get_option('sai_use_server_cron', 'no') === 'yes') {
            return;
        }

        if (get_option('sai_enable_auto_sync', 'no') !== 'yes') {
            return;
        }

        $interval = get_option('sai_auto_sync_interval', 'hourly');
        if (!in_array($interval, ['hourly', 'twicedaily', 'daily'], true)) {
            $interval = 'hourly';
        }

        if (!wp_next_scheduled('sai_hourly_product_sync')) {
            wp_schedule_event(time(), $interval, 'sai_hourly_product_sync');
        }
    }

    public function deactivate()
    {
        $timestamp = wp_next_scheduled('sai_hourly_product_sync');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'sai_hourly_product_sync');
        }
    }

    public function run_cron_product_sync()
    {
        if (get_option('sai_use_server_cron', 'no') === 'yes') {
            error_log('[SAI_CRON] Server cron (cron-sync.php) enabled; WP Cron sync skipped.');
            return;
        }

        if (get_option('sai_enable_auto_sync', 'no') !== 'yes') {
            error_log('[SAI_CRON] Auto sync disabled in settings, skipping.');
            return;
        }

        if (!SAI_Sync_Lock::acquire()) {
            error_log('[SAI_CRON] Another sync is already running, skipping.');
            return;
        }

        $start        = microtime(true);
        $started_at   = date('Y-m-d H:i:s');
        $batch_failed = false;
        $error_message = '';
        $created = $updated = $skipped = $batch = $total = $raw = 0;
        $manual_action_needed = 0;

        error_log('[SAI_CRON] Hourly product sync started at ' . $started_at);

        if (!class_exists('WooCommerce')) {
            error_log('[SAI_CRON] WooCommerce not active, aborting.');
            SAI_Sync_Lock::release();
            return;
        }

        $woo = new SAI_Woo_Integration();

        // مرحله ۱: دریافت از API و کش
        $cache_result = $woo->fetch_and_cache_products();

        if (is_wp_error($cache_result)) {
            $error_message = $cache_result->get_error_message();
            error_log('[SAI_CRON] fetch_and_cache_products failed: ' . $error_message);
            SAI_Sync_Lock::release();

            SAI_Cron_Activity_Log::save_run([
                'source'                 => 'wp_cron',
                'started_at'             => $started_at,
                'finished_at'            => date('Y-m-d H:i:s'),
                'duration_seconds'       => round(microtime(true) - $start, 2),
                'total_jobs'             => 0,
                'raw_total'              => 0,
                'created'                => 0,
                'updated'                => 0,
                'skipped'                => 0,
                'manual_action_required' => 0,
                'manual_action_errors'   => [],
                'batches'                => 0,
                'status'                 => 'failed',
                'error_message'          => $error_message,
            ]);

            return;
        }

        $total = $cache_result['total']     ?? 0;
        $raw   = $cache_result['raw_total'] ?? 0;

        error_log("[SAI_CRON] Cache ready. Total jobs: $total");

        // مرحله ۲: بررسی محصولات اشتباه
        $force_result         = $woo->force_simple_products_from_cache();
        $manual_action_needed = count($force_result['errors'] ?? []);

        if ($manual_action_needed > 0) {
            error_log("[SAI_CRON] $manual_action_needed product(s) need manual action (variable → simple).");
        }

        // مرحله ۳: import دسته‌ای
        $offset = 0;
        $limit  = self::get_sync_batch_size();

        do {
            $batch++;
            error_log("[SAI_CRON] Batch $batch | offset=$offset | limit=$limit");

            $result = $woo->sync_products_from_cache_batch($offset, $limit);

            if (is_wp_error($result)) {
                $error_message = $result->get_error_message();
                error_log('[SAI_CRON] Batch error: ' . $error_message);
                $batch_failed = true;
                break;
            }

            $created += $result['created'] ?? 0;
            $updated += $result['updated'] ?? 0;
            $skipped += $result['skipped'] ?? 0;

            $offset   = $result['next_offset'] ?? ($offset + $limit);
            $has_more = $result['has_more']    ?? false;
        } while ($has_more);

        $finished_at = date('Y-m-d H:i:s');
        $elapsed     = round(microtime(true) - $start, 2);

        error_log('[SAI_CRON] Hourly product sync finished. Batches: ' . $batch);

        SAI_Cron_Activity_Log::save_run([
            'source'                 => 'wp_cron',
            'started_at'             => $started_at,
            'finished_at'            => $finished_at,
            'duration_seconds'       => $elapsed,
            'total_jobs'             => $total,
            'raw_total'              => $raw,
            'created'                => $created,
            'updated'                => $updated,
            'skipped'                => $skipped,
            'manual_action_required' => $manual_action_needed,
            'manual_action_errors'   => is_array($force_result['errors'] ?? null) ? $force_result['errors'] : [],
            'batches'                => $batch,
            'status'                 => $batch_failed ? 'failed' : 'ok',
            'error_message'          => $error_message,
        ]);

        SAI_Sync_Lock::release();
    }

    public function woocommerce_notice()
    {
        echo '<div class="notice notice-error"><p>Sabz Afzar Integration requires WooCommerce.</p></div>';
    }
}

Sabz_Afzar_Integration::instance();