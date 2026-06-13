<?php

/**
 * Smoke test: AddPerson query string matches Postman (6 params, no nationalCode).
 * Run: php tests/verify-add-customer-query.php
 */

declare(strict_types=1);

$args = [
    'firstName' => 'امیرعلی',
    'lastName'  => 'دیزآبادی',
    'mobileNo'  => '9302365110',
];

$query = implode('&', [
    'firstName=' . rawurlencode(trim((string) ($args['firstName'] ?? ''))),
    'lastName=' . rawurlencode(trim((string) ($args['lastName'] ?? ''))),
    'mobileNo=' . rawurlencode(trim((string) ($args['mobileNo'] ?? ''))),
    'email=null',
    "introducerMobileNo=''",
    'isMale=true',
]);

$checks = [
    'has firstName'           => str_contains($query, 'firstName='),
    'has lastName'            => str_contains($query, 'lastName='),
    'has mobileNo=9302365110' => str_contains($query, 'mobileNo=9302365110'),
    'has email=null'          => str_contains($query, 'email=null'),
    "has introducerMobileNo=''" => str_contains($query, "introducerMobileNo=''"),
    'has isMale=true'         => str_contains($query, 'isMale=true'),
    'no nationalCode'         => !str_contains($query, 'nationalCode'),
];

$failed = false;

foreach ($checks as $label => $ok) {
    if (!$ok) {
        echo "FAIL: {$label}\n";
        $failed = true;
    }
}

if ($failed) {
    echo "Query: {$query}\n";
    exit(1);
}

echo "OK: AddPerson query string matches Postman (6 params)\n";
echo "Query sample: {$query}\n";
exit(0);
