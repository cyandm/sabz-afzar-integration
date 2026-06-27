<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * لاگ فعالیت همگام‌سازی خودکار (cron)
 * هر بار که cron اجرا میشه یه رکورد کامل ذخیره میشه
 */
class SAI_Cron_Activity_Log
{
    private const MAX_ENTRIES = 30;
    private const LOG_FILE    = 'cron-activity-log.json';

    public static function get_log_file_path(): string
    {
        $upload = wp_upload_dir();
        $dir    = trailingslashit($upload['basedir']) . 'sai-cache';

        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        return trailingslashit($dir) . self::LOG_FILE;
    }

    /**
     * ثبت یک اجرای کامل cron
     *
     * @param array{
     *   source: string,
     *   started_at: string,
     *   finished_at: string,
     *   duration_seconds: float,
     *   total_jobs: int,
     *   raw_total: int,
     *   created: int,
     *   updated: int,
     *   skipped: int,
     *   manual_action_required: int,
     *   batches: int,
     *   status: string,
     *   error_message: string
     * } $entry
     */
    public static function append(array $entry): void
    {
        $entries = self::get_all();

        array_unshift($entries, $entry);

        // فقط آخرین MAX_ENTRIES رو نگه داریم
        $entries = array_slice($entries, 0, self::MAX_ENTRIES);

        $file = self::get_log_file_path();
        file_put_contents($file, wp_json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function get_all(): array
    {
        $file = self::get_log_file_path();

        if (!file_exists($file)) {
            return [];
        }

        $raw  = file_get_contents($file);
        $data = json_decode($raw ?: '[]', true);

        return is_array($data) ? $data : [];
    }

    public static function clear(): void
    {
        $file = self::get_log_file_path();
        file_put_contents($file, wp_json_encode([], JSON_UNESCAPED_UNICODE));
    }
}
