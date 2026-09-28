<?php

/**
 * Delete everything the seeders created — and nothing else.
 *
 * Matching is by prefix only:
 *
 *   orders      customer_email starts with "qa-"
 *   products    sku starts with "QA-"
 *   customers   email starts with "qa-"
 *   categories  name starts with "QA "
 *   stores      code starts with "qa_"
 *   attributes  attribute_code starts with "qa_"
 *
 * Nothing without one of those prefixes is ever touched, which is why every
 * seeder is required to prefix what it writes.
 *
 * The whole run happens inside seed_secure(): customers, categories, products
 * and orders all call _protectFromNonAdmin() from _beforeDelete() and refuse
 * to delete without the isSecureArea registry flag. Without it the deletes
 * throw one by one and the data survives.
 *
 * core_config_data is deliberately NOT cleaned: config paths are shared with
 * the rest of the installation and cannot be matched by prefix safely. Re-run
 * `ddev seed config` or reset with `ddev reset-openmage` instead.
 *
 * Usage: ddev seed clean
 */

require_once __DIR__ . '/lib.php';

// tests/fixtures/seed holds standalone CLI scripts, not a library of
// classes. Plain functions are the right shape here: a seeder is a file you
// run, and `require_once lib.php` is the whole of its dependency graph.
// Wrapping these in a static class would add indirection and buy nothing.
// phpcs:disable Squiz.Functions.GlobalFunction.Found

/**
 * Delete every seeded category, deepest first.
 *
 * Deleting a parent cascades to its children, so ids are collected up front
 * and each one is re-checked before the delete.
 */
function seed_clean_categories(): int
{
    $collection = seed_category_collection();
    $collection->addAttributeToFilter('name', ['like' => SEED_LABEL_PREFIX . '%']);
    $collection->setOrder('level', 'DESC');

    $ids = [];

    foreach ($collection as $category) {
        $id = seed_optional_id($category->getId());

        if ($id !== null) {
            $ids[] = $id;
        }
    }

    $deleted = 0;

    foreach ($ids as $id) {
        $category = seed_model('catalog/category', Mage_Catalog_Model_Category::class);
        $category->setStoreId(Mage_Core_Model_App::ADMIN_STORE_ID);
        $category->load($id);

        // Already gone as a cascade of its parent.
        if (seed_optional_id($category->getId()) === null) {
            continue;
        }

        $category->delete();
        $deleted++;
    }

    return $deleted;
}

/**
 * Delete every store view whose code carries the seed prefix.
 */
function seed_clean_stores(): int
{
    $collection = seed_resource(
        'core/store_collection',
        Mage_Core_Model_Resource_Store_Collection::class,
    );

    // Filtered loosely on purpose: "_" is a LIKE wildcard, so the exact prefix
    // test is done in PHP rather than fought with SQL escaping.
    $collection->addFieldToFilter('code', ['like' => 'qa%']);

    $deleted = 0;

    foreach ($collection as $store) {
        if (!$store instanceof Mage_Core_Model_Store) {
            continue;
        }

        $code = $store->getCode();
        if (!is_string($code)) {
            continue;
        }

        if (!str_starts_with($code, SEED_CODE_PREFIX)) {
            continue;
        }

        $store->delete();
        $deleted++;
    }

    return $deleted;
}

/**
 * Remove every product attribute whose code carries the seed prefix.
 */
function seed_clean_attributes(): int
{
    $setup = seed_catalog_setup();

    $entityTypeId = seed_id($setup->getEntityTypeId('catalog_product'), 'catalog_product entity type');

    $collection = seed_resource(
        'eav/entity_attribute_collection',
        Mage_Eav_Model_Resource_Entity_Attribute_Collection::class,
    );
    $collection->setEntityTypeFilter($entityTypeId);
    $collection->addFieldToFilter('attribute_code', ['like' => 'qa%']);

    $codes = [];

    foreach ($collection as $attribute) {
        $code = $attribute->getData('attribute_code');

        if (is_string($code) && str_starts_with($code, SEED_CODE_PREFIX)) {
            $codes[] = $code;
        }
    }

    if ($codes === []) {
        return 0;
    }

    $setup->startSetup();

    foreach ($codes as $code) {
        $setup->removeAttribute('catalog_product', $code);
    }

    $setup->endSetup();

    seed_reset_eav_cache();

    return count($codes);
}

/**
 * Delete the deterministic images produced by generator.php.
 */
function seed_clean_generated(): int
{
    $directory = seed_generated_dir();

    if (!is_dir($directory)) {
        return 0;
    }

    $files = glob($directory . '/' . SEED_SKU_PREFIX . '*.png');

    if ($files === false) {
        return 0;
    }

    $deleted = 0;

    foreach ($files as $file) {
        if (is_file($file) && unlink($file)) {
            $deleted++;
        }
    }

    return $deleted;
}

/**
 * Remove the docroot symlink created by mock.php, leaving the fixtures intact.
 */
function seed_clean_mock_link(): void
{
    $link = seed_openmage_root() . '/mock';

    if (is_link($link)) {
        unlink($link);
        seed_log(sprintf('  clean: removed the mock symlink %s', $link));
    }
}

$counters = [
    'orders'     => 0,
    'products'   => 0,
    'customers'  => 0,
    'categories' => 0,
    'stores'     => 0,
    'attributes' => 0,
    'images'     => 0,
];

seed_secure(function () use (&$counters): void {
    // Orders first: a customer with orders cannot be removed cleanly.
    foreach (seed_seeded_orders() as $order) {
        $order->delete();
        $counters['orders']++;
    }

    foreach (seed_seeded_products() as $product) {
        $product->delete();
        $counters['products']++;
    }

    foreach (seed_seeded_customers() as $customer) {
        $customer->delete();
        $counters['customers']++;
    }

    $counters['categories'] = seed_clean_categories();
    $counters['stores']     = seed_clean_stores();
    $counters['attributes'] = seed_clean_attributes();
});

$counters['images'] = seed_clean_generated();
seed_clean_mock_link();

seed_app()->reinitStores();
seed_refresh_config();

foreach ($counters as $label => $count) {
    seed_log(sprintf('  clean: removed %d %s', $count, $label));
}

seed_log('  clean: core_config_data left untouched — re-run "ddev seed config" to restore it');

exit(0);
