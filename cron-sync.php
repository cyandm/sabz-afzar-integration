<?php

/**
 * =====================================================
 *  Sabz Afzar Integration — Standalone Cron Script
 * =====================================================
 *
 * این فایل را در سی‌پنل به عنوان Cron Job ثبت کنید:
 *
 *   مسیر فایل در سرور:
 *     /home/YOUR_CPANEL_USER/public_html/wp-content/plugins/sabz-afzar-integration/cron-sync.php
 *
 *   دستور در cPanel > Cron Jobs:
 *     /usr/local/bin/php /home/YOUR_CPANEL_USER/public_html/wp-content/plugins/sabz-afzar-integration/cron-sync.php >> /home/YOUR_CPANEL_USER/logs/sai-cron.log 2>&1
 *
 *   زمان‌بندی (هر یک ساعت):
 *     Minute:  0
 *     Hour:    *
 *     Day:     *
 *     Month:   *
 *     Weekday: *
 *
 * =====================================================
 */

// ----------------------------------------------------------------
// ۱. پیدا کردن خودکار مسیر wp-load.php
// ----------------------------------------------------------------

$wp_load = __DIR__;

// بالا می‌رویم تا wp-load.php پیدا شود (حداکثر ۶ سطح)
for ($i = 0; $i < 6; $i++) {
    $wp_load = dirname($wp_load);
    if (file_exists($wp_load . '/wp-load.php')) {
        break;
    }
}

$wp_load_file = $wp_load . '/wp-load.php';

if (!file_exists($wp_load_file)) {
    echo '[SAI_CRON] ERROR: wp-load.php not found. Make sure this file is inside the WordPress directory.' . PHP_EOL;
    exit(1);
}

// ----------------------------------------------------------------
// ۲. بارگذاری WordPress (بدون header HTTP)
// ----------------------------------------------------------------

define('DOING_CRON', true);

/** @noinspection PhpIncludeInspection */
require_once $wp_load_file;

// ----------------------------------------------------------------
// ۳. بررسی پیش‌نیازها
// ----------------------------------------------------------------

if (!class_exists('WooCommerce')) {
    echo '[SAI_CRON] ERROR: WooCommerce is not active.' . PHP_EOL;
    exit(1);
}

if (!class_exists('SAI_Woo_Integration')) {
    echo '[SAI_CRON] ERROR: SAI_Woo_Integration class not found. Is the plugin active?' . PHP_EOL;
    exit(1);
}

// ----------------------------------------------------------------
// ۴. اجرای همگام‌سازی
// ----------------------------------------------------------------

$start = microtime(true);
echo '[SAI_CRON] Product sync started at ' . date('Y-m-d H:i:s') . PHP_EOL;

$woo = new SAI_Woo_Integration();

// مرحله الف: دریافت محصولات از API سبزافزار و ذخیره در کش
echo '[SAI_CRON] Fetching products from Sabz Afzar API...' . PHP_EOL;

$cache_result = $woo->fetch_and_cache_products();

if (is_wp_error($cache_result)) {
    echo '[SAI_CRON] ERROR fetching products: ' . $cache_result->get_error_message() . PHP_EOL;
    exit(1);
}

$total = $cache_result['total']     ?? 0;
$raw   = $cache_result['raw_total'] ?? 0;

echo "[SAI_CRON] API returned $raw items → $total import jobs cached." . PHP_EOL;

// مرحله ب: پردازش دسته‌ای تا همه محصولات sync شوند
$offset  = 0;
$limit   = 20;
$batch   = 0;
$created = 0;
$updated = 0;
$skipped = 0;

do {
    $batch++;
    echo "[SAI_CRON] Batch $batch | offset=$offset" . PHP_EOL;

    $result = $woo->sync_products_from_cache_batch($offset, $limit);

    if (is_wp_error($result)) {
        echo '[SAI_CRON] ERROR in batch: ' . $result->get_error_message() . PHP_EOL;
        break;
    }

    $created += $result['created'] ?? 0;
    $updated += $result['updated'] ?? 0;
    $skipped += $result['skipped'] ?? 0;

    $offset   = $result['next_offset'] ?? ($offset + $limit);
    $has_more = $result['has_more']    ?? false;

} while ($has_more);

// ----------------------------------------------------------------
// ۵. گزارش نهایی
// ----------------------------------------------------------------

$elapsed = round(microtime(true) - $start, 2);

echo PHP_EOL;
echo '========================================' . PHP_EOL;
echo '[SAI_CRON] Sync finished at ' . date('Y-m-d H:i:s') . PHP_EOL;
echo "[SAI_CRON] Duration   : {$elapsed}s" . PHP_EOL;
echo "[SAI_CRON] Batches    : $batch" . PHP_EOL;
echo "[SAI_CRON] Created    : $created" . PHP_EOL;
echo "[SAI_CRON] Updated    : $updated" . PHP_EOL;
echo "[SAI_CRON] Skipped    : $skipped" . PHP_EOL;
echo '========================================' . PHP_EOL;

exit(0);
