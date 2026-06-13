<?php

if (!defined('ABSPATH')) {
    exit;
}

class SAI_Sync_Skip_Log
{
    private const MAX_UI_LINES = 500;

    /** @var array<int, array<string, string>> */
    private static $entries = [];

    /** @var int */
    private static $batch_start_index = 0;

    public static function get_log_file_path(): string
    {
        $upload = wp_upload_dir();
        $dir    = trailingslashit($upload['basedir']) . 'sai-cache';

        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        return trailingslashit($dir) . 'sync-skip-log.json';
    }

    public static function clear(): void
    {
        self::$entries           = [];
        self::$batch_start_index = 0;

        $file = self::get_log_file_path();
        file_put_contents($file, wp_json_encode([]));

        error_log('[SAI_SYNC] Skip log cleared: ' . $file);
    }

    /**
     * @param WP_Error $error
     * @param array<string, mixed> $context
     * @return array<string, string>
     */
    public static function append(WP_Error $error, array $context = []): array
    {
        $entry = self::build_entry($error, $context);
        self::$entries[] = $entry;
        self::persist();

        return $entry;
    }

    /**
     * @return array<int, array<string, string>>
     */
    public static function get_batch_entries(): array
    {
        return array_slice(self::$entries, self::$batch_start_index);
    }

    public static function mark_batch_start(): void
    {
        self::$batch_start_index = count(self::$entries);
    }

    public static function get_count(): int
    {
        return count(self::$entries);
    }

    /**
     * @return array<int, array<string, string>>
     */
    public static function get_all(): array
    {
        if (!empty(self::$entries)) {
            return self::$entries;
        }

        $file = self::get_log_file_path();
        if (!file_exists($file)) {
            return [];
        }

        $raw  = file_get_contents($file);
        $data = json_decode($raw ?: '[]', true);

        if (!is_array($data)) {
            return [];
        }

        self::$entries = $data;

        return self::$entries;
    }

    /**
     * @param array<string, string> $entry
     */
    public static function format_line_fa(array $entry): string
    {
        $label  = self::get_product_label($entry);
        $reason = $entry['reason_fa'] ?? 'خطای همگام‌سازی';

        return 'محصول ' . $label . ' به دلیل ' . $reason . ' رد شد';
    }

    /**
     * @param array<int, array<string, string>> $entries
     * @return array<int, array{line_fa: string, error_code: string, error_message: string}>
     */
    public static function format_entries_for_ui(array $entries): array
    {
        $formatted = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $formatted[] = [
                'line_fa'        => self::format_line_fa($entry),
                'error_code'     => (string) ($entry['error_code'] ?? ''),
                'error_message'  => (string) ($entry['error_message'] ?? ''),
            ];
        }

        return $formatted;
    }

    public static function get_ui_payload(): array
    {
        $all   = self::get_all();
        $total = count($all);
        $slice = array_slice($all, 0, self::MAX_UI_LINES);

        return [
            'skip_log_batch'  => self::format_entries_for_ui(self::get_batch_entries()),
            'skip_log_total'  => $total,
            'skip_log_lines'  => self::format_entries_for_ui($slice),
            'skip_log_hidden' => max(0, $total - count($slice)),
        ];
    }

    /**
     * @param WP_Error $error
     * @param array<string, mixed> $context
     * @return array<string, string>
     */
    private static function build_entry(WP_Error $error, array $context): array
    {
        $item = isset($context['item']) && is_array($context['item']) ? $context['item'] : [];

        $good_code = '';
        if (!empty($context['good_code'])) {
            $good_code = sanitize_text_field((string) $context['good_code']);
        } elseif (!empty($item['GoodCode'])) {
            $good_code = sanitize_text_field((string) $item['GoodCode']);
        }

        $good_name = '';
        if (!empty($context['good_name'])) {
            $good_name = sanitize_text_field((string) $context['good_name']);
        } elseif (!empty($item['GoodName'])) {
            $good_name = sanitize_text_field((string) $item['GoodName']);
        }

        $error_code    = $error->get_error_code();
        $error_message = $error->get_error_message();

        return [
            'good_code'      => $good_code,
            'good_name'      => $good_name,
            'parent_name'    => sanitize_text_field((string) ($context['parent_name'] ?? '')),
            'level'          => sanitize_text_field((string) ($context['level'] ?? 'unknown')),
            'error_code'     => $error_code,
            'error_message'  => $error_message,
            'reason_fa'      => self::map_reason_fa($error_code, $error_message),
            'time'           => current_time('mysql'),
        ];
    }

    /**
     * @param array<string, string> $entry
     */
    private static function get_product_label(array $entry): string
    {
        $code = trim((string) ($entry['good_code'] ?? ''));
        $name = trim((string) ($entry['good_name'] ?? ''));

        if ($code !== '' && $name !== '') {
            return $code . ' (' . $name . ')';
        }

        if ($code !== '') {
            return $code;
        }

        if ($name !== '') {
            return $name;
        }

        $parent = trim((string) ($entry['parent_name'] ?? ''));
        if ($parent !== '') {
            return $parent;
        }

        return '—';
    }

    private static function map_reason_fa(string $error_code, string $error_message): string
    {
        switch ($error_code) {
            case 'sai_missing_wc_attribute_term':
                return 'attribute رنگ/سایز در ووکامرس پیدا نشد';
            case 'sai_invalid_variation_item':
                if (stripos($error_message, 'Missing variation SKU') !== false) {
                    return 'کد محصول (SKU) خالی است';
                }
                return 'اطلاعات variation ناقص است';
            case 'sai_variation_sku_conflict':
                return 'SKU متعلق به محصول غیر variation است';
            case 'sai_simple_sku_conflict':
                return 'SKU قبلاً به variation اختصاص دارد';
            case 'sai_invalid_variable_group':
                return 'گروه متغیر نامعتبر است';
            case 'sai_invalid_parent_product':
                return 'بارگذاری محصول والد ناموفق بود';
            case 'sai_parent_sku_conflict':
                return 'SKU والد متعلق به محصول غیر متغیر است';
            case 'sai_invalid_variable_attributes':
                return 'ویژگی‌های محصول متغیر معتبر نیست';
            case 'sai_missing_code':
                return 'کد محصول (GoodCode) خالی است';
            case 'sai_invalid_product':
                return 'بارگذاری محصول simple ناموفق بود';
            case 'sai_invalid_simple_job':
                return 'ساختار job محصول simple نامعتبر است';
            case 'sai_invalid_variation_job':
                return 'ساختار job variation نامعتبر است';
            case 'sai_invalid_import_job':
                return 'ساختار job import نامعتبر است';
            case 'sai_term_create_failed':
                return 'ساخت term ویژگی در ووکامرس ناموفق بود';
            case 'sai_product_in_trash':
                return 'محصول در سطل زباله است و همگام‌سازی آن را منتشر نمی‌کند';
            default:
                return 'خطای همگام‌سازی';
        }
    }

    private static function persist(): void
    {
        $file = self::get_log_file_path();
        file_put_contents($file, wp_json_encode(self::$entries, JSON_UNESCAPED_UNICODE));
    }
}
