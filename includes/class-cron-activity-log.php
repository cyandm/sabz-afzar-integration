<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * گزارش آخرین اجرای همگام‌سازی خودکار (cron)
 * هر run جدید گزارش قبلی را جایگزین می‌کند.
 */
class SAI_Cron_Activity_Log
{
    private const MAX_SKIP_UI_LINES = 500;
    private const LOG_FILE          = 'cron-activity-log.json';

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
     * @param array<string, mixed> $entry
     */
    public static function save_run(array $entry): void
    {
        $skip_all = SAI_Sync_Skip_Log::get_all();
        $total    = count($skip_all);
        $slice    = array_slice($skip_all, 0, self::MAX_SKIP_UI_LINES);

        $entry['skip_log_lines']  = SAI_Sync_Skip_Log::format_entries_for_ui($slice);
        $entry['skip_log_total']  = $total;
        $entry['skip_log_hidden'] = max(0, $total - count($slice));

        if (!isset($entry['manual_action_errors']) || !is_array($entry['manual_action_errors'])) {
            $entry['manual_action_errors'] = [];
        }

        $file = self::get_log_file_path();
        file_put_contents($file, wp_json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * @deprecated Use save_run() instead.
     * @param array<string, mixed> $entry
     */
    public static function append(array $entry): void
    {
        self::save_run($entry);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get_latest(): ?array
    {
        $file = self::get_log_file_path();

        if (!file_exists($file)) {
            return null;
        }

        $raw  = file_get_contents($file);
        $data = json_decode($raw ?: 'null', true);

        if (!is_array($data)) {
            return null;
        }

        if (isset($data['source'])) {
            return $data;
        }

        if (isset($data[0]) && is_array($data[0])) {
            return $data[0];
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function get_all(): array
    {
        $latest = self::get_latest();

        return $latest !== null ? [$latest] : [];
    }

    public static function clear(): void
    {
        $file = self::get_log_file_path();
        if (file_exists($file)) {
            unlink($file);
        }
    }

    public static function get_ui_payload(): array
    {
        $entry = self::get_latest();

        if ($entry === null) {
            return ['has_report' => false];
        }

        $finished_ts = strtotime((string) ($entry['finished_at'] ?? ''));
        $human       = '';

        if ($finished_ts) {
            $human = human_time_diff($finished_ts, current_time('timestamp')) . ' پیش';
        }

        $source = (string) ($entry['source'] ?? '');
        $source_label = $source === 'server_cron' ? 'cron سرور' : 'WP Cron';

        return [
            'has_report'             => true,
            'source'                   => $source,
            'source_label'             => $source_label,
            'started_at'               => (string) ($entry['started_at'] ?? ''),
            'finished_at'              => (string) ($entry['finished_at'] ?? ''),
            'human_finished'           => $human,
            'duration_seconds'         => (float) ($entry['duration_seconds'] ?? 0),
            'total_jobs'               => (int) ($entry['total_jobs'] ?? 0),
            'raw_total'                => (int) ($entry['raw_total'] ?? 0),
            'created'                  => (int) ($entry['created'] ?? 0),
            'updated'                  => (int) ($entry['updated'] ?? 0),
            'skipped'                  => (int) ($entry['skipped'] ?? 0),
            'manual_action_required'   => (int) ($entry['manual_action_required'] ?? 0),
            'manual_action_errors'     => is_array($entry['manual_action_errors'] ?? null)
                ? array_values($entry['manual_action_errors'])
                : [],
            'batches'                  => (int) ($entry['batches'] ?? 0),
            'status'                   => (string) ($entry['status'] ?? 'ok'),
            'error_message'            => (string) ($entry['error_message'] ?? ''),
            'skip_log_lines'           => is_array($entry['skip_log_lines'] ?? null)
                ? $entry['skip_log_lines']
                : [],
            'skip_log_total'           => (int) ($entry['skip_log_total'] ?? 0),
            'skip_log_hidden'          => (int) ($entry['skip_log_hidden'] ?? 0),
        ];
    }
}
