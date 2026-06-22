<?php
if (!defined('ABSPATH')) {
    exit;
}

class SAI_Woo_Integration
{
    const SIZE_ATTRIBUTE_NAME = 'size';
    const COLOR_ATTRIBUTE_NAME = 'color';

    private $api;

    public function __construct()
    {
        $this->api = new SAI_API_Service();
    }

    private function get_cache_file()
    {
        $upload = wp_upload_dir();
        $dir = trailingslashit($upload['basedir']) . 'sai-cache';

        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        return trailingslashit($dir) . 'products.json';
    }

    private function delete_cache_file()
    {
        $file = $this->get_cache_file();

        if (file_exists($file)) {
            $deleted = @unlink($file);

            if ($deleted) {
                error_log('[SAI_SYNC] Old cache file deleted: ' . $file);
            } else {
                error_log('[SAI_SYNC] Failed to delete old cache file: ' . $file);
            }
        }
    }

    public function fetch_and_cache_products()
    {
        $file = $this->get_cache_file();

        $this->delete_cache_file();
        SAI_Sync_Skip_Log::clear();

        error_log('[SAI_SYNC] Fetching fresh products from API...');

        $response = $this->api->get_items_qty_and_discount([]);

        if (is_wp_error($response)) {
            error_log('[SAI_SYNC] API error while fetching products: ' . $response->get_error_message());
            return $response;
        }

        if (!is_array($response)) {
            error_log('[SAI_SYNC] Invalid API response type while caching');
            return new WP_Error('sai_invalid_response', 'Invalid API response');
        }

        $jobs = $this->build_variation_groups($response);
        $json = wp_json_encode($jobs);

        if ($json === false) {
            error_log('[SAI_SYNC] Failed to encode products response as JSON');
            return new WP_Error('sai_json_encode_failed', 'Failed to encode products data');
        }

        $saved = file_put_contents($file, $json);

        if ($saved === false) {
            error_log('[SAI_SYNC] Failed to write cache file: ' . $file);
            return new WP_Error('sai_cache_write_failed', 'Could not write cache file');
        }

        error_log('[SAI_SYNC] Total jobs in cache: ' . count($jobs));

        error_log('[SAI_SYNC] Products cached successfully | raw_total=' . count($response) . ' | jobs_total=' . count($jobs) . ' | file=' . $file);

        return [
            'cached'    => true,
            'total'     => count($jobs),
            'raw_total' => count($response),
        ];
    }

    private function load_cached_products()
    {
        $file = $this->get_cache_file();

        if (!file_exists($file)) {
            error_log('[SAI_SYNC] Cache file not found: ' . $file);
            return [];
        }

        $raw = file_get_contents($file);

        if ($raw === false || $raw === '') {
            error_log('[SAI_SYNC] Cache file is empty or unreadable: ' . $file);
            return [];
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            error_log('[SAI_SYNC] Cache file JSON invalid: ' . $file);
            return [];
        }

        return $data;
    }

    public function sync_products_from_cache_batch($offset = 0, $limit = 20)
    {
        $offset = max(0, (int) $offset);
        $limit  = max(1, (int) $limit);

        error_log('[SAI_SYNC] Cache batch started | offset=' . $offset . ' | limit=' . $limit);

        SAI_Sync_Skip_Log::mark_batch_start();

        $jobs = $this->load_cached_products();

        if (empty($jobs)) {
            return new WP_Error('sai_no_cache', 'Cache not found or empty');
        }

        $total = count($jobs);
        $slice = array_slice($jobs, $offset, $limit);

        $created   = 0;
        $updated   = 0;
        $skipped   = 0;
        $processed = 0;

        foreach ($slice as $job) {
            $result = is_array($job)
                ? $this->process_import_job($job)
                : new WP_Error('sai_invalid_import_job', 'Invalid import job');

            if (is_wp_error($result)) {
                $skipped++;
                $this->record_skip(
                    $result,
                    is_array($job) ? $this->build_job_skip_context($job) : ['level' => 'job']
                );
                error_log('[SAI_SYNC] Job skipped: ' . $result->get_error_message());
                continue;
            }

            if (is_array($result)) {
                $created += isset($result['created']) ? (int) $result['created'] : 0;
                $updated += isset($result['updated']) ? (int) $result['updated'] : 0;
                $skipped += isset($result['skipped']) ? (int) $result['skipped'] : 0;
            } elseif ($result === 'created') {
                $created++;
            } elseif ($result === 'updated') {
                $updated++;
            } else {
                $skipped++;
            }

            $processed++;
        }

        $next_offset = $offset + $limit;
        $has_more    = $next_offset < $total;

        if (!$has_more) {
            $this->delete_cache_file();
            error_log('[SAI_SYNC] Import finished. Cache file deleted.');
        }

        error_log(
            '[SAI_SYNC] Cache batch done | processed=' . $processed .
                ' | created=' . $created .
                ' | updated=' . $updated .
                ' | skipped=' . $skipped .
                ' | next_offset=' . $next_offset .
                ' | total=' . $total .
                ' | has_more=' . ($has_more ? 'yes' : 'no')
        );

        $skip_log = SAI_Sync_Skip_Log::get_ui_payload();

        return [
            'message'          => 'Batch processed successfully',
            'created'          => $created,
            'updated'          => $updated,
            'skipped'          => $skipped,
            'processed'        => $processed,
            'total'            => $total,
            'offset'           => $offset,
            'limit'            => $limit,
            'next_offset'      => $next_offset,
            'has_more'         => $has_more,
            'skip_log_batch'   => $skip_log['skip_log_batch'],
            'skip_log_total'   => $skip_log['skip_log_total'],
            'skip_log_hidden'  => $skip_log['skip_log_hidden'],
        ];
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function build_job_skip_context(array $job): array
    {
        $type  = isset($job['type']) ? (string) $job['type'] : 'simple';
        $items = isset($job['items']) && is_array($job['items']) ? $job['items'] : [];
        $item  = [];

        if ($type === 'variable') {
            $first = reset($items);
            if (is_array($first) && isset($first['item']) && is_array($first['item'])) {
                $item = $first['item'];
            }

            return [
                'level'       => 'job',
                'item'        => $item,
                'parent_name' => isset($job['parent_name']) ? sanitize_text_field((string) $job['parent_name']) : '',
            ];
        }

        $first = reset($items);
        if (is_array($first)) {
            $item = isset($first['item']) && is_array($first['item']) ? $first['item'] : $first;
        }

        return [
            'level' => 'simple',
            'item'  => $item,
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function record_skip(WP_Error $error, array $context = []): void
    {
        SAI_Sync_Skip_Log::append($error, $context);
    }

    public function sync_products_from_greenware_batch($offset = 0, $limit = 20)
    {
        return $this->sync_products_from_cache_batch($offset, $limit);
    }

    private function normalize_persian_text($text)
    {
        $text = (string) $text;

        $text = str_replace(
            [
                'ي',
                'ك',
                'ة',
                'ۀ',
                'أ',
                'إ',
                'ؤ',
                'ئ',
                "\xc2\xa0",
                "\xe2\x80\x89",
                "\xe2\x80\x8a",
                "\xe2\x80\xaf",
                "\xe2\x80\x8b",
                "\xef\xbb\xbf",
            ],
            [
                'ی',
                'ک',
                'ه',
                'ه',
                'ا',
                'ا',
                'و',
                'ی',
                ' ',
                ' ',
                ' ',
                ' ',
                '',
                '',
            ],
            $text
        );

        $text = preg_replace('/\s+/u', ' ', $text);
        $text = preg_replace('/\s*‌\s*/u', '‌', $text);

        foreach ($this->get_persian_compound_replacements() as $search => $replace) {
            $text = str_replace($search, $replace, $text);
        }

        return trim($text);
    }

    /**
     * Common Persian compound words written with a space instead of half-space (ZWNJ).
     *
     * @return array<string, string>
     */
    private function get_persian_compound_replacements()
    {
        static $replacements = null;

        if ($replacements !== null) {
            return $replacements;
        }

        return $replacements = [
            'سرمه ای'       => 'سرمه‌ای',
            'قهوه ای'       => 'قهوه‌ای',
            'نسکافه ای'     => 'نسکافه‌ای',
            'پسته ای'       => 'پسته‌ای',
            'فیروزه ای'     => 'فیروزه‌ای',
            'نقره ای'       => 'نقره‌ای',
            'مغز پسته ای'   => 'مغز پسته‌ای',
            'پوست پیاز'     => 'پوست پیازی',
            'رز گلد'        => 'رزگلد',
            'کله غازی'      => 'کله‌غازی',
            'آبی کله غازی'  => 'آبی کله‌غازی',
            'سفید صدفی'     => 'سفید صدفی',
            'مشکی مات'      => 'مشکی مات',
        ];
    }

    private function normalize_grouping_name($text)
    {
        $text = $this->normalize_persian_text($text);
        $text = str_replace('‌', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    private function strip_grouping_marker($text)
    {
        $text = $this->normalize_persian_text($text);
        $text = preg_replace('/\s*\*+\s*$/u', '', $text);

        return trim($text);
    }

    private function extract_size_variation($good_name)
    {
        $name = $this->strip_grouping_marker($good_name);
        $dimension = '(\d+\s*[×xX]\s*\d+)';
        $sizes = '(0|1|2|3|4|[234]?XL|S|M|L)';

        if (preg_match('/^(.*?)\s+سایز\s*' . $dimension . '$/iu', $name, $matches)) {
            return [
                'base_name' => $this->normalize_persian_text($matches[1]),
                'size'      => $this->normalize_size_value($matches[2]),
            ];
        }

        if (preg_match('/^(.*?)\s+سایز\s*' . $sizes . '$/iu', $name, $matches)) {
            return [
                'base_name' => $this->normalize_persian_text($matches[1]),
                'size'      => $this->normalize_size_value($matches[2]),
            ];
        }

        if (preg_match('/^(.*?)\s+' . $sizes . '$/iu', $name, $matches)) {
            return [
                'base_name' => $this->normalize_persian_text($matches[1]),
                'size'      => $this->normalize_size_value($matches[2]),
            ];
        }

        return null;
    }

    /**
     * @return array{base_name:string,color:string}|null
     */
    private function extract_frame_color_variation($good_name)
    {
        $name = $this->strip_grouping_marker($good_name);

        if (!preg_match('/^(.*?)\s+(قاب\s+.+)$/u', $name, $matches)) {
            return null;
        }

        $base_name = $this->normalize_persian_text($matches[1]);
        $color = $this->normalize_persian_text($matches[2]);

        if ($base_name === '' || $color === '') {
            return null;
        }

        return [
            'base_name' => $base_name,
            'color'     => $color,
        ];
    }

    private function get_color_suffixes()
    {
        static $colors = null;

        if ($colors !== null) {
            return $colors;
        }

        $colors = [
            // Multi-word (longest matches first after sort)
            'آبی کاربنی',
            'آبی کله‌غازی',
            'آبی آسمانی',
            'آبی نفتی',
            'آبی پاستیلی',
            'آبی روشن',
            'آبی تیره',
            'آبی یخی',
            'آبی فیروزه‌ای',
            'آجری روشن',
            'آجری تیره',
            'زرد توسکانی',
            'زرد کهربایی',
            'زرد لیمویی',
            'زرد خردلی',
            'سبز جنگل',
            'سبز ارتشی',
            'سبز زیتونی',
            'سبز پاستیلی',
            'سبز روشن',
            'سبز تیره',
            'سبز آلو',
            'سبز نعنایی',
            'طوسی روشن',
            'طوسی تیره',
            'طوسی متوسط',
            'قهوه‌ای روشن',
            'قهوه‌ای تیره',
            'قهوه‌ای متوسط',
            'سفید مشکی',
            'سفید دودی',
            'سفید صدفی',
            'سفید شیری',
            'مشکی مات',
            'مشکی براق',
            'قرمز تیره',
            'قرمز روشن',
            'قرمز آجری',
            'صورتی تیره',
            'صورتی روشن',
            'صورتی پاستیلی',
            'بنفش تیره',
            'بنفش روشن',
            'نارنجی تیره',
            'نارنجی روشن',
            'کرم تیره',
            'کرم روشن',
            'خاکستری تیره',
            'خاکستری روشن',
            'نوک مدادی',
            'مغز پسته‌ای',
            'پوست پیازی',
            'سرمه‌ای',
            'قهوه‌ای',
            'نسکافه‌ای',
            'فیروزه‌ای',
            'نقره‌ای',
            'شکلاتی',
            'زرشکی',
            'سرخابی',
            'خرمایی',
            'عنابی',
            'زیتونی',
            'نخودی',
            'کالباسی',
            'هلویی',
            'گیلاسی',
            'گلبهی',
            'پسته‌ای',
            'یاسی',
            'آلویی',
            'برنزی',
            'زرین',
            'مسی',
            'اکری',
            'پاستیلی',
            'صورتی',
            'نارنجی',
            'بنفش',
            'لیمویی',
            'یشمی',
            'رزگلد',
            'طلایی',
            'کاربنی',
            'فیلی',
            'خاکستری',
            'مشکی',
            'سفید',
            'خاکی',
            'شیری',
            'طوسی',
            'دودی',
            'قرمز',
            'آبی',
            'سبز',
            'زرد',
            'کرم',
            'بژ',
            'سدری',
            'آجری',
            'نعنایی',
        ];

        $colors = array_map([$this, 'normalize_persian_text'], $colors);
        $colors = array_values(array_unique($colors));
        usort($colors, function ($a, $b) {
            return strlen($b) - strlen($a);
        });

        return $colors;
    }

    /**
     * @return list<string>
     */
    private function get_single_word_color_suffixes()
    {
        static $single_word_colors = null;

        if ($single_word_colors !== null) {
            return $single_word_colors;
        }

        $single_word_colors = [];

        foreach ($this->get_color_suffixes() as $color) {
            if (strpos($color, ' ') === false) {
                $single_word_colors[] = $color;
            }
        }

        return $single_word_colors;
    }

    /**
     * @return list<string>
     */
    private function get_compound_color_suffixes()
    {
        static $compound_colors = null;

        if ($compound_colors !== null) {
            return $compound_colors;
        }

        $compound_colors = [];

        foreach ($this->get_color_suffixes() as $color) {
            if (strpos($color, ' ') !== false) {
                $compound_colors[] = $color;
            }
        }

        return $compound_colors;
    }

    private function is_single_word_color_token($token)
    {
        $token = $this->normalize_persian_text((string) $token);

        if ($token === '') {
            return false;
        }

        return in_array($token, $this->get_single_word_color_suffixes(), true);
    }

    /**
     * @param list<string> $colors
     * @return array{base_name:string,color:string}|null
     */
    private function try_extract_color_suffix_match($name, array $colors)
    {
        foreach ($colors as $color) {
            $pattern = '/^(.*?)\s+' . preg_quote($color, '/') . '$/u';

            if (!preg_match($pattern, $name, $matches)) {
                continue;
            }

            $base_name = $this->normalize_persian_text($matches[1]);

            if ($base_name === '') {
                continue;
            }

            return [
                'base_name' => $base_name,
                'color'     => $color,
            ];
        }

        return null;
    }

    /**
     * @return array{base_name:string,color:string}|null
     */
    private function try_extract_dual_color_variation($name)
    {
        $name = $this->normalize_persian_text($name);
        $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY);

        if (!is_array($parts) || count($parts) < 2) {
            return null;
        }

        $last_index = count($parts) - 1;
        $color_second = $parts[$last_index];
        $color_first = $parts[$last_index - 1];

        if (!$this->is_single_word_color_token($color_first) || !$this->is_single_word_color_token($color_second)) {
            return null;
        }

        $base_parts = array_slice($parts, 0, -2);

        if ($base_parts === []) {
            return null;
        }

        return [
            'base_name' => implode(' ', $base_parts),
            'color'     => $color_first . ' ' . $color_second,
        ];
    }

    /**
     * @param list<string> $tokens
     */
    private function match_inline_color_phrase(array $tokens)
    {
        $token_count = count($tokens);

        if ($token_count === 0) {
            return null;
        }

        $first = $this->normalize_persian_text($tokens[0]);

        if ($first === 'یقه') {
            if ($token_count === 2 && $this->is_single_word_color_token($tokens[1])) {
                return 'یقه ' . $this->normalize_persian_text($tokens[1]);
            }

            if (
                $token_count === 3
                && $this->is_single_word_color_token($tokens[1])
                && $this->is_single_word_color_token($tokens[2])
            ) {
                return 'یقه '
                    . $this->normalize_persian_text($tokens[1])
                    . ' '
                    . $this->normalize_persian_text($tokens[2]);
            }

            return null;
        }

        if (
            $token_count === 2
            && $this->is_single_word_color_token($tokens[0])
            && $this->is_single_word_color_token($tokens[1])
        ) {
            return $this->normalize_persian_text($tokens[0])
                . ' '
                . $this->normalize_persian_text($tokens[1]);
        }

        return null;
    }

    /**
     * @return array{base_name:string,color:string}|null
     */
    private function try_extract_inline_color_variation($name)
    {
        $name = $this->normalize_persian_text($name);
        $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY);

        if (!is_array($parts) || count($parts) < 3) {
            return null;
        }

        $best_match = null;
        $best_span_length = 0;

        $part_count = count($parts);

        for ($start = 0; $start < $part_count; $start++) {
            for ($end = $start; $end < $part_count; $end++) {
                $before = array_slice($parts, 0, $start);
                $after = array_slice($parts, $end + 1);

                if ($before === [] || $after === []) {
                    continue;
                }

                $span_tokens = array_slice($parts, $start, $end - $start + 1);
                $color = $this->match_inline_color_phrase($span_tokens);

                if ($color === null) {
                    continue;
                }

                $span_length = $end - $start + 1;

                if ($span_length > $best_span_length) {
                    $best_span_length = $span_length;
                    $best_match = [
                        'base_name' => implode(' ', array_merge($before, $after)),
                        'color'     => $color,
                    ];
                }
            }
        }

        return $best_match;
    }

    private function extract_color_variation($good_name)
    {
        $name = $this->strip_grouping_marker($good_name);

        $compound_match = $this->try_extract_color_suffix_match($name, $this->get_compound_color_suffixes());

        if ($compound_match !== null) {
            return $compound_match;
        }

        $dual_match = $this->try_extract_dual_color_variation($name);

        if ($dual_match !== null) {
            return $dual_match;
        }

        $single_suffix_match = $this->try_extract_color_suffix_match($name, $this->get_single_word_color_suffixes());

        if ($single_suffix_match !== null) {
            return $single_suffix_match;
        }

        return $this->try_extract_inline_color_variation($name);
    }

    private function extract_variation_data($good_name)
    {
        $name = $this->strip_grouping_marker($good_name);
        $size_variation = $this->extract_size_variation($name);
        $size = null;
        $size_base_name = null;
        $color_source_name = $name;
        $color_variation = null;

        if ($size_variation !== null) {
            $size = $size_variation['size'];
            $size_base_name = $size_variation['base_name'];
            $color_source_name = $size_base_name;

            $color_variation = $this->extract_frame_color_variation($color_source_name);

            if ($color_variation === null) {
                $color_variation = $this->extract_color_variation($color_source_name);
            }
        } else {
            $color_variation = $this->extract_color_variation($color_source_name);
        }

        return [
            'size'            => $size,
            'size_base_name'  => $size_base_name,
            'color'           => $color_variation !== null ? $color_variation['color'] : null,
            'color_base_name' => $color_variation !== null ? $color_variation['base_name'] : null,
        ];
    }

    private function normalize_size_value($size)
    {
        $size = trim((string) $size);

        if (preg_match('/^\d+\s*[×xX]\s*\d+$/u', $size)) {
            $normalized = preg_replace('/\s+/u', '', $size);

            return str_replace(['×', 'x'], 'X', $normalized);
        }

        return strtoupper($size);
    }

    private function has_star_marker($good_name): bool
    {
        return strpos((string) $good_name, '*') !== false;
    }

    private function build_variation_groups(array $items)
    {
        $records = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $good_name = isset($item['GoodName']) ? $item['GoodName'] : '';

            if ($this->has_star_marker($good_name)) {
                error_log('[SAI_SYNC] Skipped starred product: GoodCode=' . (isset($item['GoodCode']) ? $item['GoodCode'] : '') . ' | GoodName=' . $good_name);
                continue;
            }

            $group_code = isset($item['GoodGroupCode']) ? sanitize_text_field($item['GoodGroupCode']) : '';
            $group_name = isset($item['GoodGroupName']) ? sanitize_text_field($item['GoodGroupName']) : '';
            $variation = $this->extract_variation_data($good_name);

            $records[] = [
                'item'       => $item,
                'group_code' => $group_code,
                'group_name' => $group_name,
                'variation'  => $variation,
            ];
        }

        $candidate_groups = [];
        $candidate_order = [];
        $simple_jobs = [];

        foreach ($records as $record) {
            $item = $record['item'];
            $group_code = $record['group_code'];
            $group_name = $record['group_name'];
            $variation = $record['variation'];

            if ($group_code === '') {
                $simple_jobs[] = [
                    'type'  => 'simple',
                    'items' => [$item],
                ];
                continue;
            }

            $variation_attributes = [];
            $parent_name = '';
            $group_kind = '';
            $promote_color = $variation['color'] !== null && $variation['color_base_name'] !== null;

            if ($promote_color) {
                $parent_name = $variation['color_base_name'];
                $variation_attributes[self::COLOR_ATTRIBUTE_NAME] = $variation['color'];

                if ($variation['size'] !== null) {
                    $variation_attributes[self::SIZE_ATTRIBUTE_NAME] = $variation['size'];
                    $group_kind = 'color-size';
                } else {
                    $group_kind = 'color';
                }
            } elseif ($variation['size'] !== null && $variation['size_base_name'] !== null) {
                $parent_name = $variation['size_base_name'];
                $variation_attributes[self::SIZE_ATTRIBUTE_NAME] = $variation['size'];
                $group_kind = 'size';
            }

            if ($parent_name === '' || empty($variation_attributes)) {
                $simple_jobs[] = [
                    'type'  => 'simple',
                    'items' => [$item],
                ];
                continue;
            }

            $group_base_name = $this->normalize_grouping_name($parent_name);
            // Color-family items share one group regardless of whether they also have a size.
            $group_key = $promote_color
                ? $group_code . '|color|' . $group_base_name
                : $group_code . '|' . $group_kind . '|' . $group_base_name;

            if (!isset($candidate_groups[$group_key])) {
                $candidate_groups[$group_key] = [
                    'type'        => 'variable',
                    'parent_name' => $parent_name,
                    'group_code'  => $group_code,
                    'group_name'  => $group_name,
                    'attributes'  => [],
                    'items'       => [],
                ];
                $candidate_order[] = $group_key;
            }

            if ($candidate_groups[$group_key]['group_name'] === '' && $group_name !== '') {
                $candidate_groups[$group_key]['group_name'] = $group_name;
            }

            foreach ($variation_attributes as $attribute_name => $attribute_value) {
                if (!isset($candidate_groups[$group_key]['attributes'][$attribute_name])) {
                    $candidate_groups[$group_key]['attributes'][$attribute_name] = [];
                }

                if (!in_array($attribute_value, $candidate_groups[$group_key]['attributes'][$attribute_name], true)) {
                    $candidate_groups[$group_key]['attributes'][$attribute_name][] = $attribute_value;
                }
            }

            $candidate_groups[$group_key]['items'][] = [
                'item'       => $item,
                'attributes' => $variation_attributes,
            ];
        }

        $jobs = [];

        foreach ($candidate_order as $group_key) {
            $jobs[] = $candidate_groups[$group_key];
        }

        return array_merge($jobs, $simple_jobs);
    }

    private function process_import_job(array $job)
    {
        $type = isset($job['type']) ? $job['type'] : 'simple';

        if ($type === 'variable') {
            return $this->process_variable_group($job);
        }

        $items = isset($job['items']) && is_array($job['items']) ? $job['items'] : [];
        $item = reset($items);

        if (!is_array($item)) {
            return new WP_Error('sai_invalid_simple_job', 'Invalid simple import job');
        }

        return $this->process_single_greenware_item($item);
    }

    private function process_variable_group(array $group)
    {
        $parent = $this->create_or_update_variable_parent($group);

        if (is_wp_error($parent)) {
            return $parent;
        }

        $created = $parent['result'] === 'created' ? 1 : 0;
        $updated = $parent['result'] === 'updated' ? 1 : 0;
        $skipped = 0;
        $parent_id = (int) $parent['product_id'];
        $items = isset($group['items']) && is_array($group['items']) ? $group['items'] : [];

        foreach ($items as $variation_item) {
            $result = is_array($variation_item)
                ? $this->create_or_update_variation($parent_id, $variation_item)
                : new WP_Error('sai_invalid_variation_job', 'Invalid variation import job');

            if (is_wp_error($result)) {
                $skipped++;
                $item = is_array($variation_item) && isset($variation_item['item']) && is_array($variation_item['item'])
                    ? $variation_item['item']
                    : [];
                $this->record_skip($result, [
                    'level'       => 'variation',
                    'item'        => $item,
                    'parent_name' => isset($group['parent_name']) ? sanitize_text_field((string) $group['parent_name']) : '',
                ]);
                error_log('[SAI_SYNC] Variation skipped: ' . $result->get_error_message());
                continue;
            }

            if ($result === 'created') {
                $created++;
            } elseif ($result === 'updated') {
                $updated++;
            }
        }

        WC_Product_Variable::sync($parent_id);
        wc_delete_product_transients($parent_id);

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    private function create_or_update_variable_parent(array $group)
    {
        $parent_name = isset($group['parent_name']) ? sanitize_text_field($group['parent_name']) : '';
        $group_code = isset($group['group_code']) ? sanitize_text_field($group['group_code']) : '';
        $group_name = isset($group['group_name']) ? sanitize_text_field($group['group_name']) : '';

        if ($parent_name === '' || $group_code === '') {
            return new WP_Error('sai_invalid_variable_group', 'Invalid variable product group');
        }

        $anchor_good_code = $this->get_group_anchor_good_code($group);
        $parent_sku       = $anchor_good_code !== '' ? $this->get_parent_sku($anchor_good_code) : '';
        $product_id       = $this->find_variable_parent_id($group);
        $result_type      = 'updated';

        if ($product_id) {
            $product = wc_get_product($product_id);

            if (!$product) {
                return new WP_Error('sai_invalid_parent_product', 'Could not load variable parent');
            }

            $trash_error = $this->reject_if_product_in_trash($product);

            if ($trash_error instanceof WP_Error) {
                return $trash_error;
            }

            if (!$product->is_type('variable')) {
                $existing_sku = sanitize_text_field($product->get_sku());

                return new WP_Error('sai_parent_sku_conflict', 'Parent SKU already belongs to a non-variable product: ' . $existing_sku);
            }
        } else {
            $trash_error = $this->reject_if_any_sku_in_trash(
                array_filter([
                    $parent_sku,
                    $group_code !== '' && $parent_name !== ''
                        ? $this->get_legacy_parent_sku($group_code, $parent_name)
                        : '',
                ])
            );

            if ($trash_error instanceof WP_Error) {
                return $trash_error;
            }

            $product = new WC_Product_Variable();

            if ($parent_sku !== '') {
                $product->set_sku($parent_sku);
            }

            $product->set_status('publish');
            $result_type = 'created';
        }

        $product->set_name($parent_name);
        $this->sync_product_slug_from_name($product, $parent_name);

        $product_attributes = [];
        $attribute_names = [];
        $attribute_position = 0;
        $group_attributes = isset($group['attributes']) && is_array($group['attributes']) ? $group['attributes'] : [];

        foreach ($this->sort_variation_attributes($group_attributes) as $attribute_name => $attribute_options) {
            if (!is_array($attribute_options)) {
                continue;
            }

            $attribute_options = array_values(array_unique(array_filter(
                array_map('sanitize_text_field', $attribute_options),
                function ($option) {
                    return $option !== '';
                }
            )));

            if (empty($attribute_options)) {
                continue;
            }

            $taxonomy = wc_attribute_taxonomy_name($attribute_name);

            $term_ids = [];
            foreach ($attribute_options as $value) {
                $term = $this->get_or_create_attribute_term($attribute_name, $value);
                if ($term instanceof WP_Term) {
                    $term_ids[] = (int) $term->term_id;
                }
            }

            $attribute = new WC_Product_Attribute();
            $attribute->set_id(wc_attribute_taxonomy_id_by_name($attribute_name));
            $attribute->set_name($taxonomy);
            $attribute->set_options($term_ids);
            $attribute->set_position($attribute_position);
            $attribute->set_visible(true);
            $attribute->set_variation(true);

            $product_attributes[] = $attribute;
            $attribute_names[] = $attribute_name;
            $attribute_position++;
        }

        if (empty($product_attributes)) {
            return new WP_Error('sai_invalid_variable_attributes', 'Variable product group has no valid attributes');
        }

        $product->set_attributes($product_attributes);

        $this->apply_group_category($product, $group_name);

        $product->update_meta_data('_sai_is_parent', 'yes');
        $product->update_meta_data('_sai_group_code', $group_code);
        $product->update_meta_data('_sai_group_name', $group_name);
        $product->update_meta_data('_sai_base_name', $parent_name);
        $product->update_meta_data('_sai_variation_attributes', wp_json_encode($attribute_names));

        if ($anchor_good_code !== '') {
            $product->update_meta_data('_sai_parent_anchor_goodcode', $anchor_good_code);
        }

        $product_id = $product->save();

        error_log('[SAI_SYNC] VARIABLE ' . strtoupper($result_type) . ': SKU=' . $product->get_sku() . ' | Name=' . $parent_name);

        return [
            'product_id' => $product_id,
            'result'     => $result_type,
        ];
    }

    private function create_or_update_variation($parent_id, array $variation_item)
    {
        $item = isset($variation_item['item']) && is_array($variation_item['item']) ? $variation_item['item'] : [];
        $attributes = isset($variation_item['attributes']) && is_array($variation_item['attributes']) ? $variation_item['attributes'] : [];
        $good_code = isset($item['GoodCode']) ? sanitize_text_field($item['GoodCode']) : '';

        if ($good_code === '') {
            return new WP_Error('sai_invalid_variation_item', 'Missing variation SKU');
        }

        $variation_attributes = [];
        $missing_terms        = [];

        foreach ($attributes as $attribute_name => $attribute_value) {
            $attribute_name = sanitize_text_field($attribute_name);
            $attribute_value = sanitize_text_field($attribute_value);

            if ($attribute_name === '' || $attribute_value === '') {
                continue;
            }

            $taxonomy = wc_attribute_taxonomy_name($attribute_name);
            $term     = $this->get_or_create_attribute_term($attribute_name, $attribute_value);

            if ($term instanceof WP_Term) {
                $variation_attributes[$taxonomy] = $term->slug;
            } else {
                $missing_terms[$attribute_name] = is_wp_error($term)
                    ? $term->get_error_message()
                    : $attribute_value;
            }
        }

        if (empty($variation_attributes)) {
            if (!empty($missing_terms)) {
                $parts = [];
                foreach ($missing_terms as $name => $value) {
                    $parts[] = $name . ': ' . $value;
                }

                return new WP_Error(
                    'sai_missing_wc_attribute_term',
                    'Missing WC attribute terms: ' . implode(', ', $parts)
                );
            }

            return new WP_Error('sai_invalid_variation_item', 'Missing variation attributes');
        }

        $this->retire_existing_simple_product_for_variation($good_code);

        $trash_error = $this->reject_if_sku_in_trash($good_code);

        if ($trash_error instanceof WP_Error) {
            return $trash_error;
        }

        $variation_id = $this->find_product_id_by_sku($good_code);
        $result_type = 'updated';

        if ($variation_id) {
            $variation = wc_get_product($variation_id);

            if (!$variation) {
                return new WP_Error('sai_invalid_variation', 'Could not load variation');
            }

            $trash_error = $this->reject_if_product_in_trash($variation);

            if ($trash_error instanceof WP_Error) {
                return $trash_error;
            }

            if (!$variation->is_type('variation')) {
                return new WP_Error('sai_variation_sku_conflict', 'Variation SKU already belongs to a non-variation product: ' . $good_code);
            }
        } else {
            $variation = new WC_Product_Variation();
            $variation->set_sku($good_code);
            $result_type = 'created';
        }

        $variation->set_parent_id((int) $parent_id);
        $variation->set_attributes($variation_attributes);

        $this->apply_price_data($variation, $item);
        $this->apply_stock_data($variation, $item);

        $variation->update_meta_data('_sai_good_id', isset($item['GoodId']) ? sanitize_text_field($item['GoodId']) : '');
        $variation->update_meta_data('_sai_good_code', $good_code);
        $variation->update_meta_data('_sai_original_name', isset($item['GoodName']) ? sanitize_text_field($item['GoodName']) : '');
        $variation->update_meta_data('_sai_group_code', isset($item['GoodGroupCode']) ? sanitize_text_field($item['GoodGroupCode']) : '');
        $variation->update_meta_data('_sai_group_name', isset($item['GoodGroupName']) ? sanitize_text_field($item['GoodGroupName']) : '');
        $variation->update_meta_data('_sai_unit_name', isset($item['UnitName']) ? sanitize_text_field($item['UnitName']) : '');

        $variation->save();

        error_log('[SAI_SYNC] VARIATION ' . strtoupper($result_type) . ': SKU=' . $good_code . ' | Attributes=' . wp_json_encode($attributes));

        return $result_type;
    }

    private function retire_existing_simple_product_for_variation($good_code)
    {
        $product_id = $this->find_product_id_by_sku($good_code);

        if (!$product_id) {
            return;
        }

        $product = wc_get_product($product_id);

        if (!$product || !$product->is_type('simple')) {
            return;
        }

        if ($this->is_product_in_trash($product)) {
            return;
        }

        $old_sku = 'old-' . $good_code;

        if ($this->find_product_id_by_sku($old_sku)) {
            $old_sku = 'old-' . $good_code . '-' . time();
        }

        $product->set_status('draft');
        $product->set_sku($old_sku);
        $product->update_meta_data('_sai_replaced_by_variation', 'yes');
        $product->update_meta_data('_sai_replaced_at', current_time('mysql'));
        $product->save();

        error_log('[SAI_SYNC] Retired simple product for variation SKU=' . $good_code);
    }

    private function find_product_id_by_sku($sku)
    {
        $sku = sanitize_text_field($sku);

        if ($sku === '') {
            return 0;
        }

        return (int) wc_get_product_id_by_sku($sku);
    }

    private function find_trashed_product_id_by_sku(string $sku): int
    {
        $sku = sanitize_text_field($sku);

        if ($sku === '') {
            return 0;
        }

        $ids = wc_get_products([
            'sku'    => $sku,
            'status' => 'trash',
            'limit'  => 1,
            'return' => 'ids',
        ]);

        if (!is_array($ids) || empty($ids)) {
            return 0;
        }

        return (int) $ids[0];
    }

    /**
     * @param WC_Product|WC_Product_Variation|WC_Product_Variable|WC_Product_Simple|null $product
     */
    private function is_product_in_trash($product): bool
    {
        return is_object($product)
            && method_exists($product, 'get_status')
            && $product->get_status() === 'trash';
    }

    private function reject_if_sku_in_trash(string $sku): ?WP_Error
    {
        $sku = sanitize_text_field($sku);

        if ($sku === '' || $this->find_trashed_product_id_by_sku($sku) <= 0) {
            return null;
        }

        return new WP_Error(
            'sai_product_in_trash',
            'Product is in trash; sync skipped to preserve trash status: ' . $sku
        );
    }

    /**
     * @param WC_Product|WC_Product_Variation|WC_Product_Variable|WC_Product_Simple|null $product
     */
    private function reject_if_product_in_trash($product): ?WP_Error
    {
        if (!$this->is_product_in_trash($product)) {
            return null;
        }

        $sku = is_object($product) && method_exists($product, 'get_sku')
            ? sanitize_text_field((string) $product->get_sku())
            : '';

        return new WP_Error(
            'sai_product_in_trash',
            'Product is in trash; sync skipped to preserve trash status' . ($sku !== '' ? ': ' . $sku : '')
        );
    }

    /**
     * @param array<int, string> $skus
     */
    private function reject_if_any_sku_in_trash(array $skus): ?WP_Error
    {
        foreach ($skus as $sku) {
            $trash_error = $this->reject_if_sku_in_trash($sku);

            if ($trash_error instanceof WP_Error) {
                return $trash_error;
            }
        }

        return null;
    }

    /**
     * First GoodCode in a variable group (sorted alphabetically) — stable parent anchor.
     */
    private function get_group_anchor_good_code(array $group): string
    {
        $good_codes = [];
        $items      = isset($group['items']) && is_array($group['items']) ? $group['items'] : [];

        foreach ($items as $variation_item) {
            $item      = isset($variation_item['item']) && is_array($variation_item['item']) ? $variation_item['item'] : [];
            $good_code = isset($item['GoodCode']) ? sanitize_text_field($item['GoodCode']) : '';

            if ($good_code !== '') {
                $good_codes[] = $good_code;
            }
        }

        if ($good_codes === []) {
            return '';
        }

        sort($good_codes, SORT_STRING);

        return $good_codes[0];
    }

    private function get_parent_sku(string $anchor_good_code): string
    {
        return 'sai-parent-' . sanitize_key($anchor_good_code);
    }

    private function get_legacy_parent_sku(string $group_code, string $base_name): string
    {
        return 'sai-parent-' . sanitize_key($group_code) . '-' . md5($this->normalize_grouping_name($base_name));
    }

    private function is_valid_variable_parent(int $product_id): bool
    {
        if ($product_id <= 0) {
            return false;
        }

        $product = wc_get_product($product_id);

        return $product
            && $product->is_type('variable')
            && !$this->is_product_in_trash($product);
    }

    /**
     * @param array<string, mixed> $group
     */
    private function group_identity_matches_parent_meta(
        array $group,
        string $stored_group_code,
        string $stored_parent_name,
        string $parent_sku = ''
    ): bool {
        $expected_group_code  = isset($group['group_code']) ? sanitize_text_field((string) $group['group_code']) : '';
        $expected_parent_name = isset($group['parent_name']) ? sanitize_text_field((string) $group['parent_name']) : '';
        $anchor_good_code     = $this->get_group_anchor_good_code($group);

        if ($anchor_good_code !== '' && $parent_sku !== '') {
            if ($parent_sku === $this->get_parent_sku($anchor_good_code)) {
                return true;
            }
        }

        $expected_name_normalized = $this->normalize_grouping_name($expected_parent_name);
        $stored_name_normalized   = $this->normalize_grouping_name($stored_parent_name);

        if ($expected_name_normalized === '' || $expected_name_normalized !== $stored_name_normalized) {
            return false;
        }

        if ($expected_group_code !== '' && $stored_group_code !== '' && $expected_group_code !== $stored_group_code) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $group
     */
    private function parent_matches_import_group(int $parent_id, array $group): bool
    {
        if (!$this->is_valid_variable_parent($parent_id)) {
            return false;
        }

        $product = wc_get_product($parent_id);

        if (!$product) {
            return false;
        }

        $stored_group_code = sanitize_text_field($product->get_meta('_sai_group_code', true));
        $stored_base_name  = sanitize_text_field($product->get_meta('_sai_base_name', true));
        $parent_name       = $stored_base_name !== ''
            ? $stored_base_name
            : sanitize_text_field($product->get_name());

        return $this->group_identity_matches_parent_meta(
            $group,
            $stored_group_code,
            $parent_name,
            sanitize_text_field($product->get_sku())
        );
    }

    /**
     * @param array<string, mixed> $group
     */
    private function accept_variable_parent_candidate(int $parent_id, array $group): int
    {
        if ($parent_id > 0 && $this->parent_matches_import_group($parent_id, $group)) {
            return $parent_id;
        }

        return 0;
    }

    /**
     * Resolve an existing variable parent without creating duplicates.
     */
    private function find_variable_parent_id(array $group): int
    {
        $anchor_good_code = $this->get_group_anchor_good_code($group);

        if ($anchor_good_code !== '') {
            $parent_id = $this->accept_variable_parent_candidate(
                $this->find_product_id_by_sku($this->get_parent_sku($anchor_good_code)),
                $group
            );

            if ($parent_id) {
                return $parent_id;
            }
        }

        $items = isset($group['items']) && is_array($group['items']) ? $group['items'] : [];

        foreach ($items as $variation_item) {
            $item      = isset($variation_item['item']) && is_array($variation_item['item']) ? $variation_item['item'] : [];
            $good_code = isset($item['GoodCode']) ? sanitize_text_field($item['GoodCode']) : '';

            if ($good_code === '') {
                continue;
            }

            $product_id = $this->find_product_id_by_sku($good_code);

            if (!$product_id) {
                continue;
            }

            $product = wc_get_product($product_id);

            if (!$product) {
                continue;
            }

            if ($product->is_type('variation')) {
                $parent_id = $this->accept_variable_parent_candidate((int) $product->get_parent_id(), $group);

                if ($parent_id) {
                    return $parent_id;
                }
            }
        }

        $group_code  = isset($group['group_code']) ? sanitize_text_field($group['group_code']) : '';
        $parent_name = isset($group['parent_name']) ? sanitize_text_field($group['parent_name']) : '';

        if ($group_code !== '' && $parent_name !== '') {
            $legacy_sku = $this->get_legacy_parent_sku($group_code, $parent_name);
            $parent_id  = $this->accept_variable_parent_candidate(
                $this->find_product_id_by_sku($legacy_sku),
                $group
            );

            if ($parent_id) {
                return $parent_id;
            }
        }

        if ($anchor_good_code !== '') {
            $parent_ids = wc_get_products([
                'limit'      => 1,
                'return'     => 'ids',
                'type'       => 'variable',
                'meta_query' => [
                    'relation' => 'AND',
                    [
                        'key'   => '_sai_is_parent',
                        'value' => 'yes',
                    ],
                    [
                        'key'   => '_sai_parent_anchor_goodcode',
                        'value' => $anchor_good_code,
                    ],
                ],
            ]);

            if (is_array($parent_ids) && !empty($parent_ids)) {
                $parent_id = $this->accept_variable_parent_candidate((int) $parent_ids[0], $group);

                if ($parent_id) {
                    return $parent_id;
                }
            }
        }

        return 0;
    }

    private function sync_product_slug_from_name($product, string $name): void
    {
        if (!is_object($product) || !method_exists($product, 'get_slug') || !method_exists($product, 'set_slug')) {
            return;
        }

        $name = trim($name);

        if ($name === '') {
            return;
        }

        $new_slug = sanitize_title($name);

        if ($new_slug === '' || $product->get_slug() === $new_slug) {
            return;
        }

        $product->set_slug($new_slug);
    }

    private function sort_variation_attributes(array $attributes)
    {
        $sorted = [];

        foreach ([self::COLOR_ATTRIBUTE_NAME, self::SIZE_ATTRIBUTE_NAME] as $attribute_name) {
            if (isset($attributes[$attribute_name])) {
                $sorted[$attribute_name] = $attributes[$attribute_name];
                unset($attributes[$attribute_name]);
            }
        }

        foreach ($attributes as $attribute_name => $attribute_options) {
            $sorted[$attribute_name] = $attribute_options;
        }

        return $sorted;
    }

    private function get_attribute_key($attribute_name)
    {
        return sanitize_title($attribute_name);
    }

    /**
     * Ensure global WooCommerce attributes pa_color and pa_size exist (e.g. on plugin activate).
     */
    public function ensure_default_product_attributes(): void
    {
        if (!function_exists('wc_attribute_taxonomy_id_by_name')) {
            return;
        }

        $color = $this->ensure_wc_global_attribute(self::COLOR_ATTRIBUTE_NAME);
        $size  = $this->ensure_wc_global_attribute(self::SIZE_ATTRIBUTE_NAME);

        if (is_wp_error($color)) {
            error_log('[SAI_SYNC] Could not ensure color attribute: ' . $color->get_error_message());
        }

        if (is_wp_error($size)) {
            error_log('[SAI_SYNC] Could not ensure size attribute: ' . $size->get_error_message());
        }
    }

    /**
     * @return int|WP_Error Attribute taxonomy ID.
     */
    private function ensure_wc_global_attribute(string $slug)
    {
        if (!in_array($slug, [self::COLOR_ATTRIBUTE_NAME, self::SIZE_ATTRIBUTE_NAME], true)) {
            return new WP_Error('sai_invalid_attribute', 'Unsupported attribute slug: ' . $slug);
        }

        if (!function_exists('wc_attribute_taxonomy_id_by_name')) {
            return new WP_Error('sai_wc_missing', 'WooCommerce attribute API is not available');
        }

        $attribute_id = wc_attribute_taxonomy_id_by_name($slug);
        if ($attribute_id) {
            return (int) $attribute_id;
        }

        if (!function_exists('wc_create_attribute')) {
            return new WP_Error('sai_wc_missing', 'wc_create_attribute is not available');
        }

        $labels = [
            self::COLOR_ATTRIBUTE_NAME => 'رنگ',
            self::SIZE_ATTRIBUTE_NAME  => 'سایز',
        ];

        $created = wc_create_attribute([
            'name'         => $labels[$slug],
            'slug'         => $slug,
            'type'         => 'select',
            'order_by'     => 'menu_order',
            'has_archives' => false,
        ]);

        if (is_wp_error($created)) {
            return $created;
        }

        delete_transient('wc_attribute_taxonomies');

        if (class_exists('WC_Cache_Helper')) {
            WC_Cache_Helper::get_transient_version('product', true);
        }

        $this->register_attribute_taxonomy_if_needed($slug);

        return (int) $created;
    }

    private function register_attribute_taxonomy_if_needed(string $slug): void
    {
        $taxonomy = wc_attribute_taxonomy_name($slug);

        if (taxonomy_exists($taxonomy)) {
            return;
        }

        register_taxonomy(
            $taxonomy,
            apply_filters('woocommerce_taxonomy_objects_' . $taxonomy, ['product']),
            apply_filters(
                'woocommerce_taxonomy_args_' . $taxonomy,
                [
                    'labels'       => [
                        'name' => $slug,
                    ],
                    'hierarchical' => true,
                    'show_ui'      => false,
                    'query_var'    => true,
                    'rewrite'      => false,
                ]
            )
        );
    }

    /**
     * Find or create a term under pa_color / pa_size (reuses existing; no duplicates).
     *
     * @return WP_Term|WP_Error
     */
    private function get_or_create_attribute_term(string $attribute_name, string $label)
    {
        $attribute_name = sanitize_text_field($attribute_name);
        $label          = $this->normalize_persian_text(sanitize_text_field($label));

        if ($attribute_name === '' || $label === '') {
            return new WP_Error('sai_empty_attribute_term', 'Empty attribute name or term label');
        }

        if (!in_array($attribute_name, [self::COLOR_ATTRIBUTE_NAME, self::SIZE_ATTRIBUTE_NAME], true)) {
            return new WP_Error('sai_invalid_attribute', 'Unsupported attribute: ' . $attribute_name);
        }

        $ensure = $this->ensure_wc_global_attribute($attribute_name);
        if (is_wp_error($ensure)) {
            return $ensure;
        }

        $taxonomy = wc_attribute_taxonomy_name($attribute_name);
        $this->register_attribute_taxonomy_if_needed($attribute_name);

        $existing = $this->find_existing_attribute_term($attribute_name, $label, $taxonomy);
        if ($existing instanceof WP_Term) {
            return $existing;
        }

        $inserted = wp_insert_term($label, $taxonomy);

        if (is_wp_error($inserted)) {
            if ($inserted->get_error_code() === 'term_exists') {
                $resolved = $this->resolve_term_exists_error($inserted, $label, $taxonomy);
                if ($resolved instanceof WP_Term) {
                    return $resolved;
                }
            }

            return new WP_Error(
                'sai_term_create_failed',
                $inserted->get_error_message(),
                ['attribute' => $attribute_name, 'label' => $label]
            );
        }

        if (!is_array($inserted) || !isset($inserted['term_id'])) {
            return new WP_Error('sai_term_create_failed', 'Term insert returned invalid data');
        }

        $term = get_term((int) $inserted['term_id'], $taxonomy);

        if (!$term instanceof WP_Term) {
            return new WP_Error('sai_term_create_failed', 'Could not load term after insert');
        }

        return $term;
    }

    private function find_existing_attribute_term(string $attribute_name, string $label, string $taxonomy): ?WP_Term
    {
        $term = get_term_by('name', $label, $taxonomy);
        if ($term instanceof WP_Term) {
            return $term;
        }

        $exists = term_exists($label, $taxonomy);
        if ($exists) {
            $term_id = is_array($exists) ? (int) $exists['term_id'] : (int) $exists;
            $term    = get_term($term_id, $taxonomy);
            if ($term instanceof WP_Term && !is_wp_error($term)) {
                return $term;
            }
        }

        $slug = sanitize_title($label);
        if ($slug !== '') {
            $term = get_term_by('slug', $slug, $taxonomy);
            if ($term instanceof WP_Term) {
                return $term;
            }
        }

        if ($attribute_name !== self::SIZE_ATTRIBUTE_NAME) {
            return null;
        }

        $terms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
        ]);

        if (is_wp_error($terms) || !is_array($terms)) {
            return null;
        }

        $label_lower = strtolower($label);
        $slug_lower  = strtolower($slug);

        foreach ($terms as $candidate) {
            if (!$candidate instanceof WP_Term) {
                continue;
            }

            if (
                strtolower($candidate->name) === $label_lower
                || strtolower($candidate->slug) === $label_lower
                || ($slug_lower !== '' && strtolower($candidate->slug) === $slug_lower)
            ) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param WP_Error $error
     */
    private function resolve_term_exists_error(WP_Error $error, string $label, string $taxonomy): ?WP_Term
    {
        $data = $error->get_error_data('term_exists');
        if ($data === null) {
            $data = $error->get_error_data();
        }

        $term_id = 0;
        if (is_numeric($data)) {
            $term_id = (int) $data;
        } elseif (is_array($data) && isset($data['term_id'])) {
            $term_id = (int) $data['term_id'];
        }

        if ($term_id > 0) {
            $term = get_term($term_id, $taxonomy);
            if ($term instanceof WP_Term && !is_wp_error($term)) {
                return $term;
            }
        }

        $exists = term_exists($label, $taxonomy);
        if ($exists) {
            $term_id = is_array($exists) ? (int) $exists['term_id'] : (int) $exists;
            $term    = get_term($term_id, $taxonomy);
            if ($term instanceof WP_Term && !is_wp_error($term)) {
                return $term;
            }
        }

        return null;
    }

    private function get_or_create_product_category_id($group_name)
    {
        $group_name = $this->normalize_persian_text($group_name);

        if ($group_name === '') {
            return 0;
        }

        $existing = term_exists($group_name, 'product_cat');

        if (is_array($existing) && isset($existing['term_id'])) {
            return (int) $existing['term_id'];
        }

        if (is_int($existing)) {
            return $existing;
        }

        if (is_string($existing) && is_numeric($existing)) {
            return (int) $existing;
        }

        $created = wp_insert_term($group_name, 'product_cat');

        if (is_wp_error($created)) {
            error_log('[SAI_SYNC] Category create failed: ' . $created->get_error_message() . ' | Name=' . $group_name);
            return 0;
        }

        return isset($created['term_id']) ? (int) $created['term_id'] : 0;
    }

    private function apply_group_category($product, $group_name)
    {
        if (!method_exists($product, 'set_category_ids') || !method_exists($product, 'get_category_ids')) {
            return;
        }

        $category_id = $this->get_or_create_product_category_id($group_name);

        if (!$category_id) {
            return;
        }

        $category_ids = array_map('intval', $product->get_category_ids());
        $previous_category_id = (int) $product->get_meta('_sai_category_id', true);

        if ($previous_category_id && $previous_category_id !== $category_id) {
            $category_ids = array_values(array_diff($category_ids, [$previous_category_id]));
        }

        if (!in_array($category_id, $category_ids, true)) {
            $category_ids[] = $category_id;
        }

        $product->set_category_ids(array_values(array_unique($category_ids)));
        $product->update_meta_data('_sai_category_id', $category_id);
    }

    private function apply_price_data($product, array $item)
    {
        if (get_option('sai_enable_price_sync', 'yes') !== 'yes') {
            return;
        }

        $price = isset($item['SellPrice']) ? (float) $item['SellPrice'] : 0;
        $discount = isset($item['DiscountPercent']) ? (float) $item['DiscountPercent'] : 0;

        if (get_option('sai_price_unit', 'rial') === 'toman') {
            $price = $price / 10;
        }

        $product->set_regular_price((string) $price);

        if ($discount > 0) {
            $sale_price = $price - (($price * $discount) / 100);
            $product->set_sale_price((string) max(0, $sale_price));
        } else {
            $product->set_sale_price('');
        }
    }

    private function apply_stock_data($product, array $item)
    {
        if (get_option('sai_enable_stock_sync', 'yes') !== 'yes') {
            return;
        }

        $qty = isset($item['Quantity']) ? (float) $item['Quantity'] : 0;

        $product->set_manage_stock(true);
        $product->set_stock_quantity($qty);
        $product->set_stock_status($qty > 0 ? 'instock' : 'outofstock');
    }

    private function process_single_greenware_item($item)
    {
        $good_code = isset($item['GoodCode']) ? sanitize_text_field($item['GoodCode']) : '';
        $good_name = isset($item['GoodName']) ? sanitize_text_field($item['GoodName']) : '';

        if ($good_code === '') {
            return new WP_Error('sai_missing_code', 'Missing GoodCode');
        }

        $trash_error = $this->reject_if_sku_in_trash($good_code);

        if ($trash_error instanceof WP_Error) {
            return $trash_error;
        }

        $good_id = isset($item['GoodId']) ? sanitize_text_field($item['GoodId']) : '';

        $product_id = $this->find_product_id_by_sku($good_code);

        if (!$product_id && $good_id !== '') {
            $product_by_good_id = wc_get_products([
                'limit'      => 1,
                'return'     => 'ids',
                'status'     => ['draft', 'pending', 'private', 'publish', 'future'],
                'meta_query' => [
                    [
                        'key'     => '_sai_good_id',
                        'value'   => $good_id,
                        'compare' => '=',
                    ],
                ],
            ]);

            if (is_array($product_by_good_id) && !empty($product_by_good_id)) {
                $product_id = (int) $product_by_good_id[0];
            }
        }

        if ($product_id) {
            $product = wc_get_product($product_id);

            if (!$product) {
                return new WP_Error('sai_invalid_product', 'Could not load product');
            }

            $trash_error = $this->reject_if_product_in_trash($product);

            if ($trash_error instanceof WP_Error) {
                return $trash_error;
            }

            if ($product->is_type('variation')) {
                return new WP_Error('sai_simple_sku_conflict', 'Simple product SKU already belongs to a variation: ' . $good_code);
            }

            if ($product->get_sku() !== $good_code) {
                $existing_sku_product_id = wc_get_product_id_by_sku($good_code);

                if ($existing_sku_product_id && (int) $existing_sku_product_id !== (int) $product->get_id()) {
                    return new WP_Error(
                        'sai_sku_conflict',
                        'Cannot update SKU because it already belongs to another product: ' . $good_code
                    );
                }

                $product->set_sku($good_code);
            }

            $result_type = 'updated';
        } else {
            $product = new WC_Product_Simple();
            $product->set_sku($good_code);
            $product->set_status('publish');
            $result_type = 'created';
        }

        $display_name = $good_name !== '' ? $good_name : $good_code;
        $product->set_name($display_name);
        $this->sync_product_slug_from_name($product, $display_name);

        $this->apply_price_data($product, $item);
        $this->apply_stock_data($product, $item);

        $this->apply_group_category($product, isset($item['GoodGroupName']) ? sanitize_text_field($item['GoodGroupName']) : '');

        $product->update_meta_data('_sai_good_id', isset($item['GoodId']) ? sanitize_text_field($item['GoodId']) : '');
        $product->update_meta_data('_sai_good_code', $good_code);
        $product->update_meta_data('_sai_original_name', $good_name);
        $product->update_meta_data('_sai_group_code', isset($item['GoodGroupCode']) ? sanitize_text_field($item['GoodGroupCode']) : '');
        $product->update_meta_data('_sai_group_name', isset($item['GoodGroupName']) ? sanitize_text_field($item['GoodGroupName']) : '');
        $product->update_meta_data('_sai_unit_name', isset($item['UnitName']) ? sanitize_text_field($item['UnitName']) : '');
        $product->update_meta_data('_sai_no_entry_inv', isset($item['NoEntryInv']) ? sanitize_text_field($item['NoEntryInv']) : '');

        $product->save();

        error_log('[SAI_SYNC] ' . strtoupper($result_type) . ': SKU=' . $good_code . ' | Name=' . $good_name);

        return $result_type;
    }

    /**
     * Convert published simple products that belong to an existing variable parent.
     *
     * @return array{converted:int,skipped:int,errors:array<int,string>}
     */
    public function remediate_orphan_simple_variations()
    {
        $converted = 0;
        $skipped = 0;
        $errors = [];

        $product_ids = wc_get_products([
            'status'   => 'publish',
            'type'     => 'simple',
            'limit'    => -1,
            'return'   => 'ids',
            'meta_query' => [
                [
                    'key'     => '_sai_group_code',
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        if (!is_array($product_ids)) {
            return [
                'converted' => 0,
                'skipped'   => 0,
                'errors'    => ['Could not query simple products'],
            ];
        }

        foreach ($product_ids as $product_id) {
            $product = wc_get_product((int) $product_id);

            if (!$product || !$product->is_type('simple')) {
                $skipped++;
                continue;
            }

            if ($product->get_meta('_sai_is_parent', true) === 'yes') {
                $skipped++;
                continue;
            }

            $good_code = sanitize_text_field($product->get_meta('_sai_good_code', true));

            if ($good_code === '') {
                $good_code = sanitize_text_field($product->get_sku());
            }

            if ($good_code === '') {
                $skipped++;
                continue;
            }

            $group_code = sanitize_text_field($product->get_meta('_sai_group_code', true));

            if ($group_code === '') {
                $skipped++;
                continue;
            }

            $good_name = sanitize_text_field($product->get_meta('_sai_original_name', true));

            if ($good_name === '') {
                $good_name = sanitize_text_field($product->get_name());
            }

            $remediation = $this->build_remediation_variation_context($group_code, $good_name);

            if ($remediation === null) {
                $skipped++;
                continue;
            }

            $lookup_group = [
                'parent_name' => $remediation['parent_name'],
                'group_code'  => $group_code,
                'items'       => [
                    [
                        'item' => [
                            'GoodCode' => $good_code,
                        ],
                    ],
                ],
            ];
            $parent_id = $this->find_variable_parent_id($lookup_group);

            if (!$parent_id) {
                $skipped++;
                continue;
            }

            $parent = wc_get_product($parent_id);

            if (!$parent || !$parent->is_type('variable')) {
                $skipped++;
                continue;
            }

            $regular_price = (float) $product->get_regular_price();
            $sale_price = $product->get_sale_price();
            $discount = 0.0;

            if ($sale_price !== '' && $regular_price > 0) {
                $discount = max(0, 100 - (((float) $sale_price / $regular_price) * 100));
            }

            $sell_price = $regular_price;

            if (get_option('sai_price_unit', 'rial') === 'toman') {
                $sell_price = $regular_price * 10;
            }

            $variation_item = [
                'item' => [
                    'GoodCode'        => $good_code,
                    'GoodName'        => $good_name,
                    'GoodGroupCode'   => $group_code,
                    'GoodGroupName'   => sanitize_text_field($product->get_meta('_sai_group_name', true)),
                    'GoodId'          => sanitize_text_field($product->get_meta('_sai_good_id', true)),
                    'UnitName'        => sanitize_text_field($product->get_meta('_sai_unit_name', true)),
                    'SellPrice'       => $sell_price,
                    'DiscountPercent' => $discount,
                    'Quantity'        => $product->get_stock_quantity(),
                ],
                'attributes' => $remediation['attributes'],
            ];

            $result = $this->create_or_update_variation((int) $parent_id, $variation_item);

            if (is_wp_error($result)) {
                $errors[] = 'SKU ' . $good_code . ': ' . $result->get_error_message();
                continue;
            }

            WC_Product_Variable::sync((int) $parent_id);
            wc_delete_product_transients((int) $parent_id);
            $converted++;

            $parent_sku = $parent ? sanitize_text_field($parent->get_sku()) : '';

            error_log('[SAI_SYNC] Remediated orphan simple to variation: SKU=' . $good_code . ' | Parent=' . $parent_sku);
        }

        error_log(
            '[SAI_SYNC] Remediation finished | converted=' . $converted .
                ' | skipped=' . $skipped .
                ' | errors=' . count($errors)
        );

        return [
            'converted' => $converted,
            'skipped'   => $skipped,
            'errors'    => $errors,
        ];
    }

    /**
     * @return array{parent_name:string,attributes:array<string,string>}|null
     */
    private function build_remediation_variation_context($group_code, $good_name)
    {
        $variation = $this->extract_variation_data($good_name);
        $attributes = [];
        $parent_name = '';

        if ($variation['color'] !== null && $variation['color_base_name'] !== null) {
            $parent_name = $variation['color_base_name'];
            $attributes[self::COLOR_ATTRIBUTE_NAME] = $variation['color'];

            if ($variation['size'] !== null) {
                $attributes[self::SIZE_ATTRIBUTE_NAME] = $variation['size'];
            }
        } elseif ($variation['size'] !== null && $variation['size_base_name'] !== null) {
            $parent_name = $variation['size_base_name'];
            $attributes[self::SIZE_ATTRIBUTE_NAME] = $variation['size'];
        }

        if ($parent_name === '' || empty($attributes)) {
            return null;
        }

        return [
            'parent_name' => $parent_name,
            'attributes'  => $attributes,
        ];
    }
}
