<?php

/**
 * =====================================================
 *  Sabz Afzar Integration — Standalone Cron Script
 * =====================================================
 *
 * این فایل را در cPanel به عنوان Cron Job ثبت کنید.
 * در پنل ادمین افزونه، گزینه «همگام‌سازی با cron سرور» باید فعال باشد
 * تا WP Cron همگام‌سازی محصول اجرا نشود.
 *
 *   مسیر فایل در سرور:
 *     /home/YOUR_CPANEL_USER/public_html/wp-content/plugins/sabz-afzar-integration/cron-sync.php
 *
 *   دستور در cPanel > Cron Jobs:
 *     /usr/local/bin/php /home/YOUR_CPANEL_USER/public_html/wp-content/plugins/sabz-afzar-integration/cron-sync.php >> /home/YOUR_CPANEL_USER/logs/sai-cron.log 2>&1
 *
 *   مسیر PHP را با `which php` در SSH یا از cPanel > Select PHP Version بررسی کنید.
 *
 *   زمان‌بندی (هر یک ساعت):
 *     Minute:  0 | Hour: * | Day: * | Month: * | Weekday: *
 *
 *   (اختیاری) در wp-config.php برای غیرفعال کردن WP Cron سراسری:
 *     define('DISABLE_WP_CRON', true);
 *
 * =====================================================
 */

// ----------------------------------------------------------------
// ۱. پیدا کردن خودکار مسیر wp-load.php
// ----------------------------------------------------------------

$wp_load = __DIR__;

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

if (!class_exists('SAI_Sync_Lock')) {
    echo '[SAI_CRON] ERROR: SAI_Sync_Lock class not found.' . PHP_EOL;
    exit(1);
}

if (!SAI_Sync_Lock::acquire()) {
    echo '[SAI_CRON] ERROR: Another sync is already running. Skipping.' . PHP_EOL;
    exit(1);
}

// ----------------------------------------------------------------
// ۴. اجرای همگام‌سازی
// ----------------------------------------------------------------

$start        = microtime(true);
$batch_failed = false;

echo '[SAI_CRON] Product sync started at ' . date('Y-m-d H:i:s') . PHP_EOL;

$woo = new SAI_Woo_Integration();

echo '[SAI_CRON] Fetching products from Sabz Afzar API...' . PHP_EOL;

$cache_result = $woo->fetch_and_cache_products();

if (is_wp_error($cache_result)) {
    echo '[SAI_CRON] ERROR fetching products: ' . $cache_result->get_error_message() . PHP_EOL;
    SAI_Sync_Lock::release();
    exit(1);
}

$total = $cache_result['total']     ?? 0;
$raw   = $cache_result['raw_total'] ?? 0;

echo "[SAI_CRON] API returned $raw items → $total import jobs cached." . PHP_EOL;

$offset  = 0;
$limit   = Sabz_Afzar_Integration::get_sync_batch_size();
$batch   = 0;

echo "[SAI_CRON] Batch size : $limit jobs per iteration" . PHP_EOL;
$created = 0;
$updated = 0;
$skipped = 0;

do {
    $batch++;
    echo "[SAI_CRON] Batch $batch | offset=$offset" . PHP_EOL;

    $result = $woo->sync_products_from_cache_batch($offset, $limit);

    if (is_wp_error($result)) {
        echo '[SAI_CRON] ERROR in batch: ' . $result->get_error_message() . PHP_EOL;
        $batch_failed = true;
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

if ($batch_failed) {
    echo '[SAI_CRON] Status     : FAILED (batch error)' . PHP_EOL;
} else {
    echo '[SAI_CRON] Status     : OK' . PHP_EOL;
}

echo '========================================' . PHP_EOL;

SAI_Sync_Lock::release();

exit($batch_failed ? 1 : 0);
