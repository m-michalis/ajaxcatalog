<?php

/**
 * Seed extra store views so multi-store behaviour can be exercised locally.
 *
 * The views are created inside the EXISTING website and store group, which is
 * what you want for translation and per-store-scope testing. Creating a whole
 * second website is a different exercise and is deliberately not done here.
 *
 * Idempotent: each view is looked up by code before it is created.
 *
 * Usage: ddev seed stores
 */

require_once __DIR__ . '/lib.php';

/**
 * Store views to create, in code => name order.
 *
 * @var array<string, string>
 */
$storeViews = [
    SEED_CODE_PREFIX . 'de' => SEED_LABEL_PREFIX . 'German',
    SEED_CODE_PREFIX . 'it' => SEED_LABEL_PREFIX . 'Italian',
];

$defaultStore = seed_default_store();
$websiteId    = seed_id($defaultStore->getWebsiteId(), 'Default store view website id');
$groupId      = seed_id($defaultStore->getGroupId(), 'Default store view group id');

$created = 0;
$skipped = 0;

foreach ($storeViews as $code => $name) {
    $store = seed_model('core/store', Mage_Core_Model_Store::class);
    $store->load($code, 'code');

    if (seed_optional_id($store->getId()) !== null) {
        seed_log(sprintf('  stores: %s already exists, skipping', $code));
        $skipped++;

        continue;
    }

    $store->setCode($code);
    $store->setName($name);
    $store->setWebsiteId($websiteId);
    $store->setGroupId($groupId);
    $store->setIsActive(1);
    $store->save();

    seed_log(sprintf('  stores: created %s (%s) as store id %s', $code, $name, (string) $store->getId()));
    $created++;
}

// A newly inserted core_store row is invisible to Mage::app() until the store
// list is rebuilt, so anything running after this in the same process would
// still not see the view.
seed_app()->reinitStores();
seed_app()->cleanCache();

seed_log(sprintf('  stores: %d created, %d already present', $created, $skipped));

exit(0);
