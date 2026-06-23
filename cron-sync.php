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
$started_at   = date('Y-m-d H:i:s');
$batch_failed = false;
$error_message = '';

$created              = 0;
$updated              = 0;
$skipped              = 0;
$manual_action_needed = 0;
$batch                = 0;
$total                = 0;
$raw                  = 0;

echo '[SAI_CRON] Product sync started at ' . $started_at . PHP_EOL;

$woo = new SAI_Woo_Integration();

// ----------------------------------------------------------------
// مرحله ۱: دریافت محصولات از API و ذخیره در کش
// ----------------------------------------------------------------

echo '[SAI_CRON] Fetching products from Sabz Afzar API...' . PHP_EOL;

$cache_result = $woo->fetch_and_cache_products();

if (is_wp_error($cache_result)) {
    $error_message = $cache_result->get_error_message();
    echo '[SAI_CRON] ERROR fetching products: ' . $error_message . PHP_EOL;
    SAI_Sync_Lock::release();

    SAI_Cron_Activity_Log::save_run([
        'source'                 => 'server_cron',
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

    exit(1);
}

$total = $cache_result['total']     ?? 0;
$raw   = $cache_result['raw_total'] ?? 0;

echo "[SAI_CRON] API returned $raw items → $total import jobs cached." . PHP_EOL;

// ----------------------------------------------------------------
// مرحله ۲: بررسی و لاگ محصولاتی که باید ساده باشن ولی متغیرن
// ----------------------------------------------------------------

echo '[SAI_CRON] Checking for products that need manual fix (variable → simple)...' . PHP_EOL;

$force_result = $woo->force_simple_products_from_cache();
$manual_action_needed = count($force_result['errors'] ?? []);

if ($manual_action_needed > 0) {
    echo "[SAI_CRON] ⚠ $manual_action_needed product(s) need manual action:" . PHP_EOL;
    foreach ($force_result['errors'] as $err) {
        echo '  - ' . $err . PHP_EOL;
    }
} else {
    echo '[SAI_CRON] No manual fixes required.' . PHP_EOL;
}

// ----------------------------------------------------------------
// مرحله ۳: import دسته‌ای
// ----------------------------------------------------------------

$limit = Sabz_Afzar_Integration::get_sync_batch_size();
$offset = 0;

echo "[SAI_CRON] Batch size: $limit jobs per iteration" . PHP_EOL;

do {
    $batch++;
    echo "[SAI_CRON] Batch $batch | offset=$offset" . PHP_EOL;

    $result = $woo->sync_products_from_cache_batch($offset, $limit);

    if (is_wp_error($result)) {
        $error_message = $result->get_error_message();
        echo '[SAI_CRON] ERROR in batch: ' . $error_message . PHP_EOL;
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

$finished_at = date('Y-m-d H:i:s');
$elapsed     = round(microtime(true) - $start, 2);
$status      = $batch_failed ? 'failed' : 'ok';

echo PHP_EOL;
echo '========================================' . PHP_EOL;
echo '[SAI_CRON] Sync finished at ' . $finished_at . PHP_EOL;
echo "[SAI_CRON] Duration              : {$elapsed}s" . PHP_EOL;
echo "[SAI_CRON] Batches               : $batch" . PHP_EOL;
echo "[SAI_CRON] Created               : $created" . PHP_EOL;
echo "[SAI_CRON] Updated               : $updated" . PHP_EOL;
echo "[SAI_CRON] Skipped               : $skipped" . PHP_EOL;
echo "[SAI_CRON] Manual action needed  : $manual_action_needed" . PHP_EOL;

if ($batch_failed) {
    echo '[SAI_CRON] Status     : FAILED (batch error)' . PHP_EOL;
} else {
    echo '[SAI_CRON] Status     : OK' . PHP_EOL;
}

echo '========================================' . PHP_EOL;

// ذخیره گزارش آخرین اجرا
SAI_Cron_Activity_Log::save_run([
    'source'                 => 'server_cron',
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
    'status'                 => $status,
    'error_message'          => $error_message,
]);

SAI_Sync_Lock::release();

exit($batch_failed ? 1 : 0);
