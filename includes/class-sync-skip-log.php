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
     * @return array<int, array{line_fa: string, detail_fa: string}>
     */
    public static function format_entries_for_ui(array $entries): array
    {
        $formatted = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $error_code    = (string) ($entry['error_code'] ?? '');
            $error_message = (string) ($entry['error_message'] ?? '');
            $reason_fa     = (string) ($entry['reason_fa'] ?? self::map_reason_fa($error_code, $error_message));
            $detail_fa     = self::map_detail_fa($error_code, $error_message, $reason_fa);

            $formatted[] = [
                'line_fa'   => self::format_line_fa(array_merge($entry, ['reason_fa' => $reason_fa])),
                'detail_fa' => ($detail_fa !== $reason_fa) ? $detail_fa : '',
            ];
        }

        return $formatted;
    }

    /**
     * @return string پیام فارسی برای خطاهای force_simple و گزارش cron
     */
    public static function format_product_error_fa(string $good_code, WP_Error $error): string
    {
        $code   = sanitize_text_field($good_code);
        $reason = self::map_reason_fa($error->get_error_code(), $error->get_error_message());
        $detail = self::map_detail_fa($error->get_error_code(), $error->get_error_message(), $reason);

        if ($code === '') {
            return $detail !== '' ? $detail : $reason;
        }

        if ($detail !== '' && $detail !== $reason) {
            return 'کد ' . $code . ': ' . $detail;
        }

        return 'کد ' . $code . ': ' . $reason;
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

    private static function extract_message_suffix(string $error_message): string
    {
        if (preg_match('/:\s*(.+)$/u', $error_message, $matches)) {
            return trim($matches[1]);
        }

        return '';
    }

    private static function translate_attribute_term_list(string $terms_part): string
    {
        $terms_part = trim($terms_part);

        if ($terms_part === '') {
            return '';
        }

        $parts = array_map('trim', explode(',', $terms_part));
        $translated = [];

        foreach ($parts as $part) {
            if (preg_match('/^(color|size)\s*:\s*(.+)$/iu', $part, $matches)) {
                $label = strtolower($matches[1]) === 'size' ? 'سایز' : 'رنگ';
                $translated[] = $label . ': ' . trim($matches[2]);
                continue;
            }

            $translated[] = $part;
        }

        return implode('، ', $translated);
    }

    private static function map_detail_fa(string $error_code, string $error_message, string $reason_fa): string
    {
        if ($error_code === 'sai_missing_wc_attribute_term') {
            $terms_part = trim(str_ireplace('Missing WC attribute terms:', '', $error_message));
            $terms_fa   = self::translate_attribute_term_list($terms_part);

            if ($terms_fa !== '') {
                return 'مقادیر ویژگی در ووکامرس پیدا نشد: ' . $terms_fa;
            }
        }

        if ($error_code === 'sai_term_create_failed' && $error_message !== '') {
            return 'ساخت مقدار ویژگی در ووکامرس ناموفق بود: ' . $error_message;
        }

        return $reason_fa;
    }

    private static function map_reason_fa(string $error_code, string $error_message): string
    {
        $suffix = self::extract_message_suffix($error_message);

        switch ($error_code) {
            case 'sai_missing_wc_attribute_term':
                return 'نبود مقدار ویژگی رنگ یا سایز در ووکامرس';
            case 'sai_invalid_variation_item':
                if (stripos($error_message, 'Missing variation SKU') !== false) {
                    return 'خالی بودن کد محصول برای نسخهٔ متغیر';
                }
                if (stripos($error_message, 'Missing variation attributes') !== false) {
                    return 'نداشتن ویژگی رنگ یا سایز برای نسخهٔ متغیر';
                }
                return 'ناقص بودن اطلاعات نسخهٔ متغیر';
            case 'sai_variation_sku_conflict':
                return $suffix !== ''
                    ? 'اختصاص کد «' . $suffix . '» به محصولی غیر از نسخهٔ متغیر'
                    : 'اختصاص کد محصول به محصولی غیر از نسخهٔ متغیر';
            case 'sai_simple_sku_conflict':
                return $suffix !== ''
                    ? 'اختصاص کد «' . $suffix . '» قبلاً به یک نسخهٔ متغیر'
                    : 'اختصاص کد محصول قبلاً به یک نسخهٔ متغیر';
            case 'sai_sku_conflict':
                return $suffix !== ''
                    ? 'اختصاص کد «' . $suffix . '» به محصول دیگری'
                    : 'تعارض کد محصول با محصول دیگر';
            case 'sai_invalid_variable_group':
                return 'نامعتبر بودن گروه محصول متغیر';
            case 'sai_invalid_parent_product':
                if (stripos($error_message, 'simple conversion') !== false) {
                    return 'بارگذاری محصول متغیر برای تبدیل به ساده ناموفق بود';
                }
                return 'بارگذاری محصول والد متغیر ناموفق بود';
            case 'sai_parent_sku_conflict':
                return $suffix !== ''
                    ? 'اختصاص کد والد «' . $suffix . '» به محصول غیر متغیر'
                    : 'اختصاص کد والد به محصول غیر متغیر';
            case 'sai_invalid_variable_attributes':
                return 'نداشتن ویژگی معتبر برای محصول متغیر';
            case 'sai_missing_code':
                return 'خالی بودن کد محصول (GoodCode)';
            case 'sai_invalid_product':
                if (stripos($error_message, 'variable to simple conversion') !== false) {
                    return 'بارگذاری محصول پس از تبدیل متغیر به ساده ناموفق بود';
                }
                if (stripos($error_message, 'reload product after variable to simple conversion') !== false) {
                    return 'بارگذاری مجدد محصول پس از تبدیل متغیر به ساده ناموفق بود';
                }
                return 'بارگذاری محصول ساده ناموفق بود';
            case 'sai_invalid_simple_job':
                return 'ساختار دستهٔ همگام‌سازی محصول ساده نامعتبر است';
            case 'sai_invalid_variation_job':
                return 'ساختار دستهٔ همگام‌سازی نسخهٔ متغیر نامعتبر است';
            case 'sai_invalid_import_job':
                return 'ساختار دستهٔ همگام‌سازی نامعتبر است';
            case 'sai_invalid_variation':
                if (stripos($error_message, 'simple conversion') !== false) {
                    return 'بارگذاری نسخهٔ متغیر برای تبدیل به ساده ناموفق بود';
                }
                return 'بارگذاری نسخهٔ متغیر ناموفق بود';
            case 'sai_term_create_failed':
                return 'ساخت مقدار ویژگی در ووکامرس ناموفق بود';
            case 'sai_product_in_trash':
                return $suffix !== ''
                    ? 'قرار داشتن محصول «' . $suffix . '» در سطل زباله (همگام‌سازی انجام نشد)'
                    : 'قرار داشتن محصول در سطل زباله (همگام‌سازی انجام نشد)';
            case 'sai_simple_create_failed':
                return $suffix !== ''
                    ? 'ساخت محصول ساده با کد «' . $suffix . '» ناموفق بود'
                    : 'ساخت محصول ساده ناموفق بود';
            case 'sai_manual_action_required':
                return 'نیاز به اقدام دستی برای تبدیل محصول متغیر به ساده';
            case 'sai_invalid_attribute':
                return $suffix !== ''
                    ? 'پشتیبانی نشدن ویژگی «' . $suffix . '»'
                    : 'ویژگی پشتیبانی‌نشده';
            case 'sai_wc_missing':
                return 'در دسترس نبودن API ویژگی‌های ووکامرس';
            case 'sai_empty_attribute_term':
                return 'خالی بودن نام یا مقدار ویژگی';
            case 'sai_no_cache':
                return 'پیدا نشدن یا خالی بودن فایل کش';
            case 'sai_invalid_response':
                return 'نامعتبر بودن پاسخ API';
            case 'sai_json_encode_failed':
                return 'خطا در ذخیرهٔ JSON محصولات';
            case 'sai_cache_write_failed':
                return 'خطا در نوشتن فایل کش';
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
