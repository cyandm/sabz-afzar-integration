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

        return [
            'message'     => 'Batch processed successfully',
            'created'     => $created,
            'updated'     => $updated,
            'skipped'     => $skipped,
            'processed'   => $processed,
            'total'       => $total,
            'offset'      => $offset,
            'limit'       => $limit,
            'next_offset' => $next_offset,
            'has_more'    => $has_more,
        ];
    }

    public function sync_products_from_greenware_batch($offset = 0, $limit = 20)
    {
        return $this->sync_products_from_cache_batch($offset, $limit);
    }

    private function normalize_persian_text($text)
    {
        $text = (string) $text;
        $text = str_replace(['ي', 'ك', "\xc2\xa0"], ['ی', 'ک', ' '], $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = preg_replace('/\s*‌\s*/u', '‌', $text);
        $text = str_replace('سرمه ای', 'سرمه‌ای', $text);
        $text = str_replace('قهوه ای', 'قهوه‌ای', $text);
        $text = str_replace('نسکافه ای', 'نسکافه‌ای', $text);

        return trim($text);
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
        $sizes = '(0|1|2|3|4|[234]?XL|S|M|L)';

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

    private function get_color_suffixes()
    {
        static $colors = null;

        if ($colors !== null) {
            return $colors;
        }

        $colors = [
            'آبی کاربنی',
            'آبی روشن',
            'آبی تیره',
            'آجری روشن',
            'زرد توسکانی',
            'زرد کهربایی',
            'سبز جنگل',
            'سبز روشن',
            'سبز تیره',
            'سبز ارتشی',
            'طوسی روشن',
            'قهوه‌ای روشن',
            'قهوه‌ای تیره',
            'سفید مشکی',
            'سفید دودی',
            'نوک مدادی',
            'سرمه‌ای',
            'قهوه‌ای',
            'نسکافه‌ای',
            'شکلاتی',
            'زرشکی',
            'سرخابی',
            'صورتی',
            'نارنجی',
            'بنفش',
            'لیمویی',
            'یشمی',
            'رزگلد',
            'طلایی',
            'کاربنی',
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
        ];

        $colors = array_map([$this, 'normalize_persian_text'], $colors);
        $colors = array_values(array_unique($colors));
        usort($colors, function ($a, $b) {
            return strlen($b) - strlen($a);
        });

        return $colors;
    }

    private function extract_color_variation($good_name)
    {
        $name = $this->strip_grouping_marker($good_name);

        foreach ($this->get_color_suffixes() as $color) {
            $pattern = '/^(.*?)\s+' . preg_quote($color, '/') . '$/u';

            if (preg_match($pattern, $name, $matches)) {
                $base_name = $this->normalize_persian_text($matches[1]);

                if ($base_name === '') {
                    continue;
                }

                return [
                    'base_name' => $base_name,
                    'color'     => $color,
                ];
            }
        }

        return null;
    }

    private function extract_variation_data($good_name)
    {
        $name = $this->strip_grouping_marker($good_name);
        $size_variation = $this->extract_size_variation($name);
        $size = null;
        $size_base_name = null;
        $color_source_name = $name;

        if ($size_variation !== null) {
            $size = $size_variation['size'];
            $size_base_name = $size_variation['base_name'];
            $color_source_name = $size_base_name;
        }

        $color_variation = $this->extract_color_variation($color_source_name);

        return [
            'size'            => $size,
            'size_base_name'  => $size_base_name,
            'color'           => $color_variation !== null ? $color_variation['color'] : null,
            'color_base_name' => $color_variation !== null ? $color_variation['base_name'] : null,
        ];
    }

    private function normalize_size_value($size)
    {
        return strtoupper(trim((string) $size));
    }

    private function build_variation_groups(array $items)
    {
        $records = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $good_name = isset($item['GoodName']) ? $item['GoodName'] : '';
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

        $parent_sku = $this->get_parent_sku($group_code, $parent_name);
        $product_id = $this->find_product_id_by_sku($parent_sku);
        $result_type = 'updated';

        if ($product_id) {
            $product = wc_get_product($product_id);

            if (!$product) {
                return new WP_Error('sai_invalid_parent_product', 'Could not load variable parent');
            }

            if (!$product->is_type('variable')) {
                return new WP_Error('sai_parent_sku_conflict', 'Parent SKU already belongs to a non-variable product: ' . $parent_sku);
            }
        } else {
            $product = new WC_Product_Variable();
            $product->set_sku($parent_sku);
            $result_type = 'created';
        }

        $product->set_name($parent_name);
        $product->set_status('publish');

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
            // مثلا pa_رنگ

            // ساخت term اگر وجود ندارد
            foreach ($attribute_options as $value) {
                if (!term_exists($value, $taxonomy)) {
                    wp_insert_term($value, $taxonomy);
                }
            }

            // گرفتن term IDs
            $term_ids = [];
            foreach ($attribute_options as $value) {
                $term = get_term_by('name', $value, $taxonomy);
                if ($term) {
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

        $product_id = $product->save();

        error_log('[SAI_SYNC] VARIABLE ' . strtoupper($result_type) . ': SKU=' . $parent_sku . ' | Name=' . $parent_name);

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

        foreach ($attributes as $attribute_name => $attribute_value) {
            $attribute_name = sanitize_text_field($attribute_name);
            $attribute_value = sanitize_text_field($attribute_value);

            if ($attribute_name === '' || $attribute_value === '') {
                continue;
            }

            $taxonomy = wc_attribute_taxonomy_name($attribute_name);

            $term = get_term_by('name', $attribute_value, $taxonomy);

            if ($term) {
                $variation_attributes[$taxonomy] = $term->slug;
            }
        }

        if (empty($variation_attributes)) {
            return new WP_Error('sai_invalid_variation_item', 'Missing variation attributes');
        }

        $this->retire_existing_simple_product_for_variation($good_code);

        $variation_id = $this->find_product_id_by_sku($good_code);
        $result_type = 'updated';

        if ($variation_id) {
            $variation = wc_get_product($variation_id);

            if (!$variation) {
                return new WP_Error('sai_invalid_variation', 'Could not load variation');
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

        $old_sku = 'old-' . $good_code;

        if ($this->find_product_id_by_sku($old_sku)) {
            $old_sku = '';
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

    private function get_parent_sku($group_code, $base_name)
    {
        return 'sai-parent-' . sanitize_key($group_code) . '-' . md5($this->normalize_grouping_name($base_name));
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

        $product_id = $this->find_product_id_by_sku($good_code);

        if ($product_id) {
            $product = wc_get_product($product_id);

            if (!$product) {
                return new WP_Error('sai_invalid_product', 'Could not load product');
            }

            if ($product->is_type('variation')) {
                return new WP_Error('sai_simple_sku_conflict', 'Simple product SKU already belongs to a variation: ' . $good_code);
            }

            $result_type = 'updated';
        } else {
            $product = new WC_Product_Simple();
            $product->set_name($good_name !== '' ? $good_name : $good_code);
            $product->set_sku($good_code);
            $product->set_status('publish');
            $result_type = 'created';
        }

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

            $parent_sku = $this->get_parent_sku($group_code, $remediation['parent_name']);
            $parent_id = $this->find_product_id_by_sku($parent_sku);

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

        $parent_sku = $this->get_parent_sku($group_code, $parent_name);

        if (!$this->find_product_id_by_sku($parent_sku)) {
            return null;
        }

        return [
            'parent_name' => $parent_name,
            'attributes'  => $attributes,
        ];
    }
}
