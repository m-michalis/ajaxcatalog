<?php

/**
 * Expose tests/fixtures/mock over HTTP by linking it into the docroot.
 *
 * A module that talks to a remote API can be pointed at
 * <base_url>/mock/sample.json during development instead of a real endpoint,
 * which keeps the round trip real (DNS, HTTP client, timeouts, parsing) while
 * the payload stays fixed.
 *
 * The link lives inside openmage/, which is gitignored and rebuilt by
 * `ddev setup-openmage`, so it has to be re-created after every reset. That is
 * why this is a seeder rather than a one-off setup step.
 *
 * Idempotent: `ln -sfn` semantics — an existing symlink is replaced, an
 * existing real directory is left alone and reported.
 *
 * Usage: ddev seed mock
 */

require_once __DIR__ . '/lib.php';

/**
 * Minimal fallback payload written when the committed JSON fixture is missing.
 *
 * @var array<string, mixed>
 */
const SEED_MOCK_JSON_FALLBACK = [
    'meta'   => ['source' => 'tests/fixtures/mock/sample.json', 'generatedBy' => 'ddev seed mock', 'version' => '1.0'],
    'status' => 'ok',
    'items'  => [
        ['sku' => 'QA-0001', 'name' => 'QA Alpine Router 0001', 'price' => 16.99, 'qty' => 23],
    ],
    'errors' => [],
];

/**
 * Minimal fallback payload written when the committed XML fixture is missing.
 */
const SEED_MOCK_XML_FALLBACK = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
    . '<response><status>ok</status><items><item><sku>QA-0001</sku>'
    . '<name>QA Alpine Router 0001</name><price currency="USD">16.99</price>'
    . '<qty>23</qty></item></items><errors/></response>' . "\n";

$mockDir = seed_mock_dir();
seed_mkdir($mockDir);

$restored = 0;

$jsonPath = $mockDir . '/sample.json';

if (!is_file($jsonPath)) {
    $encoded = json_encode(SEED_MOCK_JSON_FALLBACK, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    file_put_contents($jsonPath, ($encoded === false ? '{}' : $encoded) . "\n");
    seed_warn('tests/fixtures/mock/sample.json was missing and has been recreated from a fallback payload.');
    $restored++;
}

$xmlPath = $mockDir . '/sample.xml';

if (!is_file($xmlPath)) {
    file_put_contents($xmlPath, SEED_MOCK_XML_FALLBACK);
    seed_warn('tests/fixtures/mock/sample.xml was missing and has been recreated from a fallback payload.');
    $restored++;
}

$link = seed_openmage_root() . '/mock';

// is_link() before file_exists(): a symlink pointing at a deleted target is
// still a symlink but file_exists() reports false for it.
if (is_link($link)) {
    unlink($link);
} elseif (file_exists($link)) {
    seed_fail(sprintf('"%s" already exists and is not a symlink; refusing to replace it.', $link));
}

// Relative target, resolved against the link's own directory (openmage/), so
// the link survives the project being moved or bind-mounted elsewhere.
if (!symlink('../tests/fixtures/mock', $link)) {
    seed_fail(sprintf('Could not create the symlink "%s".', $link));
}

$baseUrl = seed_default_store()->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB);

seed_log(sprintf('  mock: linked %s -> ../tests/fixtures/mock', $link));
seed_log(sprintf('  mock: %d fallback payload(s) restored', $restored));

if (is_string($baseUrl)) {
    seed_log(sprintf('  mock: %smock/sample.json', $baseUrl));
    seed_log(sprintf('  mock: %smock/sample.xml', $baseUrl));
}

exit(0);
