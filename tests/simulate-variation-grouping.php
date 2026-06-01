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

    public function simulateBuildGroups(array $items): array
    {
        $reflection = new ReflectionClass($this);
        $method = $reflection->getMethod('build_variation_groups');
        $method->setAccessible(true);

        return $method->invoke($this, $items);
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

exit($failures > 0 ? 1 : 0);
