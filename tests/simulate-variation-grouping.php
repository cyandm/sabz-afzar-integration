<?php

/**
 * Variation grouping / color parsing simulation.
 * Run: php tests/simulate-variation-grouping.php
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', true);
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str)
    {
        return trim((string) $str);
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($key)
    {
        return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $key));
    }
}

require_once dirname(__DIR__) . '/includes/class-woo-integration.php';

/**
 * @extends SAI_Woo_Integration
 */
final class VariationGroupingSimulation extends SAI_Woo_Integration
{
    public static function create(): self
    {
        $reflection = new ReflectionClass(self::class);
        /** @var self $instance */
        $instance = $reflection->newInstanceWithoutConstructor();

        return $instance;
    }

    public function simulateExtractColor(string $good_name): ?array
    {
        $reflection = new ReflectionClass($this);
        $method = $reflection->getMethod('extract_color_variation');
        $method->setAccessible(true);

        return $method->invoke($this, $good_name);
    }

    public function simulateExtractVariation(string $good_name): array
    {
        $reflection = new ReflectionClass($this);
        $method = $reflection->getMethod('extract_variation_data');
        $method->setAccessible(true);

        return $method->invoke($this, $good_name);
    }

    public function simulateBuildGroups(array $items): array
    {
        $reflection = new ReflectionClass($this);
        $method = $reflection->getMethod('build_variation_groups');
        $method->setAccessible(true);

        return $method->invoke($this, $items);
    }

    /**
     * @param array<string, mixed> $group
     */
    public function simulateGroupIdentityMatches(
        array $group,
        string $stored_group_code,
        string $stored_parent_name,
        string $parent_sku = ''
    ): bool {
        $reflection = new ReflectionClass($this);
        $method     = $reflection->getMethod('group_identity_matches_parent_meta');
        $method->setAccessible(true);

        return (bool) $method->invoke($this, $group, $stored_group_code, $stored_parent_name, $parent_sku);
    }
}

$sim = VariationGroupingSimulation::create();
$failures = 0;

function assert_color_parse(
    VariationGroupingSimulation $sim,
    string $label,
    string $good_name,
    string $expected_color,
    string $expected_base
): void {
    global $failures;

    $result = $sim->simulateExtractColor($good_name);

    echo $label . PHP_EOL;

    if ($result === null) {
        echo "  FAIL: expected color parse, got null" . PHP_EOL;
        $failures++;
        return;
    }

    echo '  color: ' . $result['color'] . PHP_EOL;
    echo '  base: ' . $result['base_name'] . PHP_EOL;

    if ($result['color'] !== $expected_color) {
        echo "  FAIL: expected color={$expected_color}" . PHP_EOL;
        $failures++;
    }

    if ($result['base_name'] !== $expected_base) {
        echo "  FAIL: expected base={$expected_base}" . PHP_EOL;
        $failures++;
    }
}

function assert_variation_parse(
    VariationGroupingSimulation $sim,
    string $label,
    string $good_name,
    ?string $expected_parent,
    ?string $expected_color,
    ?string $expected_size
): void {
    global $failures;

    $result = $sim->simulateExtractVariation($good_name);
    $parent = $result['color_base_name'] ?? ($result['size_base_name'] ?? null);
    $color = $result['color'] ?? null;
    $size = $result['size'] ?? null;

    echo $label . PHP_EOL;
    echo '  parent: ' . ($parent ?? 'null') . PHP_EOL;
    echo '  color: ' . ($color ?? 'null') . PHP_EOL;
    echo '  size: ' . ($size ?? 'null') . PHP_EOL;

    if ($parent !== $expected_parent) {
        echo "  FAIL: expected parent={$expected_parent}" . PHP_EOL;
        $failures++;
    }

    if ($color !== $expected_color) {
        echo "  FAIL: expected color={$expected_color}" . PHP_EOL;
        $failures++;
    }

    if ($size !== $expected_size) {
        echo "  FAIL: expected size={$expected_size}" . PHP_EOL;
        $failures++;
    }
}

function assert_color_parse_null(
    VariationGroupingSimulation $sim,
    string $label,
    string $good_name
): void {
    global $failures;

    $result = $sim->simulateExtractColor($good_name);

    echo $label . PHP_EOL;

    if ($result !== null) {
        echo '  FAIL: expected null, got color=' . $result['color'] . ' base=' . $result['base_name'] . PHP_EOL;
        $failures++;
    } else {
        echo '  OK: no color parse (expected)' . PHP_EOL;
    }
}

$base = 'اسکارف ورزشی همه هیچ';

assert_color_parse($sim, 'Dual color: صورتی بنفش', $base . ' صورتی بنفش', 'صورتی بنفش', $base);
assert_color_parse($sim, 'Single color: صورتی', $base . ' صورتی', 'صورتی', $base);
assert_color_parse($sim, 'Single color: بنفش', $base . ' بنفش', 'بنفش', $base);
assert_color_parse($sim, 'Single color: قرمز', $base . ' قرمز', 'قرمز', $base);
assert_color_parse($sim, 'Dual color: آبی قرمز', $base . ' آبی قرمز', 'آبی قرمز', $base);
assert_color_parse($sim, 'Compound color regression', 'تیشرت صورتی پاستیلی', 'صورتی پاستیلی', 'تیشرت');

assert_color_parse($sim, 'Inline dual: تیشرت زرد سبز کاج', 'تیشرت زرد سبز کاج', 'زرد سبز', 'تیشرت کاج');
assert_color_parse($sim, 'Inline dual: تیشرت نارنجی بنفش کاج', 'تیشرت نارنجی بنفش کاج', 'نارنجی بنفش', 'تیشرت کاج');
assert_color_parse($sim, 'Inline یقه: کفتان یقه زرد پرنده مهاجر', 'کفتان یقه زرد پرنده مهاجر', 'یقه زرد', 'کفتان پرنده مهاجر');
assert_color_parse(
    $sim,
    'Inline یقه: کفتان یقه کالباسی پرنده مهاجر',
    'کفتان یقه کالباسی پرنده مهاجر',
    'یقه کالباسی',
    'کفتان پرنده مهاجر'
);
assert_color_parse_null($sim, 'Inline negative: single middle color', 'تیشرت قرمز کاج');

$tablo_parent = 'تابلو جام';

assert_variation_parse(
    $sim,
    'Frame + dimension: تابلو جام قاب طلایی سایز 70×70',
    'تابلو جام قاب طلایی سایز 70×70',
    $tablo_parent,
    'قاب طلایی',
    '70X70'
);
assert_variation_parse(
    $sim,
    'Color suffix + dimension: تابلو جام طلایی سایز 50×50',
    'تابلو جام طلایی سایز 50×50',
    $tablo_parent,
    'طلایی',
    '50X50'
);
assert_variation_parse(
    $sim,
    'Dimension only: تابلو جام سایز 40×40',
    'تابلو جام سایز 40×40',
    $tablo_parent,
    null,
    '40X40'
);
assert_variation_parse(
    $sim,
    'Tablo size-only: تابلو بی همگان مشکی دوتکه بدون قاب سایز 70×70',
    'تابلو بی همگان مشکی دوتکه بدون قاب سایز 70×70',
    'تابلو بی همگان مشکی دوتکه بدون قاب',
    null,
    '70X70'
);

$tablo_group = [
    'group_code'  => '20',
    'parent_name' => 'تابلو بی همگان مشکی دوتکه بدون قاب',
    'items'       => [
        ['item' => ['GoodCode' => '2265']],
    ],
];

echo 'Parent identity match (tablo vs t-shirt parent)' . PHP_EOL;

if ($sim->simulateGroupIdentityMatches($tablo_group, '20', 'تابلو بی همگان مشکی دوتکه بدون قاب', 'sai-parent-2265')) {
    echo '  OK: matching tablo parent accepted' . PHP_EOL;
} else {
    echo '  FAIL: matching tablo parent should be accepted' . PHP_EOL;
    $failures++;
}

if (!$sim->simulateGroupIdentityMatches($tablo_group, '1082', 'تیشرت عشق', 'sai-parent-999')) {
    echo '  OK: mismatched t-shirt parent rejected' . PHP_EOL;
} else {
    echo '  FAIL: mismatched t-shirt parent should be rejected' . PHP_EOL;
    $failures++;
}

$kaj_parent = 'تیشرت کاج';
$kaj_items = [
    ['GoodCode' => 'K1', 'GoodName' => 'تیشرت زرد سبز کاج', 'GoodGroupCode' => '201'],
    ['GoodCode' => 'K2', 'GoodName' => 'تیشرت نارنجی بنفش کاج', 'GoodGroupCode' => '201'],
];

$kaj_jobs = $sim->simulateBuildGroups($kaj_items);
$kaj_variable_job = null;

foreach ($kaj_jobs as $job) {
    if (is_array($job) && ($job['type'] ?? '') === 'variable') {
        $kaj_variable_job = $job;
        break;
    }
}

echo 'Kaj shirt grouping' . PHP_EOL;

if ($kaj_variable_job === null) {
    echo "  FAIL: expected one variable group for تیشرت کاج" . PHP_EOL;
    $failures++;
} else {
    echo '  parent: ' . ($kaj_variable_job['parent_name'] ?? '') . PHP_EOL;

    if (($kaj_variable_job['parent_name'] ?? '') !== $kaj_parent) {
        echo "  FAIL: wrong parent name" . PHP_EOL;
        $failures++;
    }

    $kaj_colors = $kaj_variable_job['attributes']['color'] ?? [];

    foreach (['زرد سبز', 'نارنجی بنفش'] as $expected_color) {
        if (!in_array($expected_color, $kaj_colors, true)) {
            echo "  FAIL: missing color option {$expected_color}" . PHP_EOL;
            $failures++;
        }
    }
}

$scarf_items = [
    ['GoodCode' => 'S1', 'GoodName' => $base . ' صورتی بنفش', 'GoodGroupCode' => '200'],
    ['GoodCode' => 'S2', 'GoodName' => $base . ' صورتی', 'GoodGroupCode' => '200'],
    ['GoodCode' => 'S3', 'GoodName' => $base . ' بنفش', 'GoodGroupCode' => '200'],
    ['GoodCode' => 'S4', 'GoodName' => $base . ' قرمز', 'GoodGroupCode' => '200'],
    ['GoodCode' => 'S5', 'GoodName' => $base . ' آبی قرمز', 'GoodGroupCode' => '200'],
];

$jobs = $sim->simulateBuildGroups($scarf_items);
$variable_job = null;

foreach ($jobs as $job) {
    if (is_array($job) && ($job['type'] ?? '') === 'variable') {
        $variable_job = $job;
        break;
    }
}

echo 'Scarf family grouping' . PHP_EOL;

if ($variable_job === null) {
    echo "  FAIL: expected one variable group" . PHP_EOL;
    $failures++;
} else {
    $skus = array_map(
        static function ($row) {
            return is_array($row) && isset($row['item']['GoodCode']) ? $row['item']['GoodCode'] : '';
        },
        $variable_job['items'] ?? []
    );

    echo '  parent: ' . ($variable_job['parent_name'] ?? '') . PHP_EOL;
    echo '  skus: ' . implode(', ', $skus) . PHP_EOL;

    if (($variable_job['parent_name'] ?? '') !== $base) {
        echo "  FAIL: wrong parent name" . PHP_EOL;
        $failures++;
    }

    foreach (['S1', 'S2', 'S3', 'S4', 'S5'] as $sku) {
        if (!in_array($sku, $skus, true)) {
            echo "  FAIL: missing SKU {$sku} in variable group" . PHP_EOL;
            $failures++;
        }
    }

    $color_options = $variable_job['attributes']['color'] ?? [];

    foreach (['صورتی بنفش', 'صورتی', 'بنفش', 'قرمز', 'آبی قرمز'] as $expected_color) {
        if (!in_array($expected_color, $color_options, true)) {
            echo "  FAIL: missing color option {$expected_color}" . PHP_EOL;
            $failures++;
        }
    }
}

$tablo_items = [
    ['GoodCode' => 'T1', 'GoodName' => 'تابلو جام قاب طلایی سایز 70×70', 'GoodGroupCode' => '300'],
    ['GoodCode' => 'T2', 'GoodName' => 'تابلو جام قاب نقره‌ای سایز 50×50', 'GoodGroupCode' => '300'],
    ['GoodCode' => 'T3', 'GoodName' => 'تابلو جام قاب طلایی سایز 50×50', 'GoodGroupCode' => '300'],
];

$tablo_jobs = $sim->simulateBuildGroups($tablo_items);
$tablo_variable_job = null;

foreach ($tablo_jobs as $job) {
    if (is_array($job) && ($job['type'] ?? '') === 'variable') {
        $tablo_variable_job = $job;
        break;
    }
}

echo 'Tablo jam grouping' . PHP_EOL;

if ($tablo_variable_job === null) {
    echo "  FAIL: expected one variable group for تابلو جام" . PHP_EOL;
    $failures++;
} else {
    $tablo_skus = array_map(
        static function ($row) {
            return is_array($row) && isset($row['item']['GoodCode']) ? $row['item']['GoodCode'] : '';
        },
        $tablo_variable_job['items'] ?? []
    );

    echo '  parent: ' . ($tablo_variable_job['parent_name'] ?? '') . PHP_EOL;
    echo '  skus: ' . implode(', ', $tablo_skus) . PHP_EOL;

    if (($tablo_variable_job['parent_name'] ?? '') !== $tablo_parent) {
        echo "  FAIL: wrong parent name" . PHP_EOL;
        $failures++;
    }

    foreach (['T1', 'T2', 'T3'] as $sku) {
        if (!in_array($sku, $tablo_skus, true)) {
            echo "  FAIL: missing SKU {$sku} in variable group" . PHP_EOL;
            $failures++;
        }
    }

    $tablo_colors = $tablo_variable_job['attributes']['color'] ?? [];
    $tablo_sizes = $tablo_variable_job['attributes']['size'] ?? [];

    foreach (['قاب طلایی', 'قاب نقره‌ای'] as $expected_color) {
        if (!in_array($expected_color, $tablo_colors, true)) {
            echo "  FAIL: missing color option {$expected_color}" . PHP_EOL;
            $failures++;
        }
    }

    foreach (['70X70', '50X50'] as $expected_size) {
        if (!in_array($expected_size, $tablo_sizes, true)) {
            echo "  FAIL: missing size option {$expected_size}" . PHP_EOL;
            $failures++;
        }
    }
}

echo PHP_EOL . '--- Simple product: تست (no color/size) ---' . PHP_EOL;

assert_variation_parse($sim, 'Plain name: تست', 'تست', null, null, null);

$tst_item = [
    'GoodId'        => 212807,
    'GoodCode'      => '5494',
    'GoodName'      => 'تست',
    'GoodGroupCode' => '5',
    'GoodGroupName' => 'عطر',
];
$tst_jobs = $sim->simulateBuildGroups([$tst_item]);
$tst_simple_job = null;

foreach ($tst_jobs as $job) {
    if (is_array($job) && ($job['type'] ?? '') === 'simple') {
        $tst_simple_job = $job;
        break;
    }
}

if ($tst_simple_job === null) {
    echo "  FAIL: expected one simple job for تست" . PHP_EOL;
    $failures++;
} else {
    echo '  OK: type=simple' . PHP_EOL;

    if (isset($tst_simple_job['attributes'])) {
        echo "  FAIL: simple job must not have attributes" . PHP_EOL;
        $failures++;
    }

    $tst_good_code = $tst_simple_job['items'][0]['GoodCode'] ?? '';

    if ($tst_good_code !== '5494') {
        echo "  FAIL: expected GoodCode=5494 in simple job" . PHP_EOL;
        $failures++;
    }
}

echo PHP_EOL . '--- Variable product: تست مشکی ---' . PHP_EOL;

$tst_meshki_item = [
    'GoodCode'      => '5495',
    'GoodName'      => 'تست مشکی',
    'GoodGroupCode' => '5',
    'GoodGroupName' => 'عطر',
];
$tst_meshki_jobs = $sim->simulateBuildGroups([$tst_meshki_item]);
$tst_variable_job = null;

foreach ($tst_meshki_jobs as $job) {
    if (is_array($job) && ($job['type'] ?? '') === 'variable') {
        $tst_variable_job = $job;
        break;
    }
}

if ($tst_variable_job === null) {
    echo "  FAIL: expected one variable group for تست مشکی" . PHP_EOL;
    $failures++;
} else {
    echo '  parent: ' . ($tst_variable_job['parent_name'] ?? '') . PHP_EOL;

    if (($tst_variable_job['parent_name'] ?? '') !== 'تست') {
        echo "  FAIL: expected parent=تست" . PHP_EOL;
        $failures++;
    }

    $meshki_colors = $tst_variable_job['attributes']['color'] ?? [];

    if (!in_array('مشکی', $meshki_colors, true)) {
        echo "  FAIL: missing color option مشکی" . PHP_EOL;
        $failures++;
    }
}

exit($failures > 0 ? 1 : 0);
