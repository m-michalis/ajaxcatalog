<?php

/**
 * Seed a small category tree under the store's root category.
 *
 * Layout:
 *
 *     Root Category (existing)
 *     └── QA Store
 *         ├── QA Electronics   <- products.php assigns its products here
 *         └── QA Apparel       <- intentionally left empty
 *
 * Idempotent: every node is looked up by its exact name before being created.
 *
 * Usage: ddev seed categories
 */

require_once __DIR__ . '/lib.php';

// tests/fixtures/seed holds standalone CLI scripts, not a library of
// classes. Plain functions are the right shape here: a seeder is a file you
// run, and `require_once lib.php` is the whole of its dependency graph.
// Wrapping these in a static class would add indirection and buy nothing.
// phpcs:disable Squiz.Functions.GlobalFunction.Found

/**
 * Create a category under a parent, or return the existing one.
 *
 * @param string $name       Category name, always prefixed so clean.php finds it
 * @param string $parentPath Path of the PARENT category, e.g. "1/2"
 *
 * @throws RuntimeException When the category cannot be persisted
 */
function seed_create_category(string $name, string $parentPath): Mage_Catalog_Model_Category
{
    $existing = seed_find_category($name);

    if ($existing instanceof Mage_Catalog_Model_Category) {
        seed_log(sprintf('  categories: %s already exists (id %s), skipping', $name, (string) $existing->getId()));

        return $existing;
    }

    $category = seed_model('catalog/category', Mage_Catalog_Model_Category::class);

    $category->setStoreId(Mage_Core_Model_App::ADMIN_STORE_ID);
    $category->setName($name);
    $category->setUrlKey(strtolower(str_replace(' ', '-', trim($name))));
    $category->setIsActive(1);
    $category->setIncludeInMenu(1);
    $category->setIsAnchor(1);
    $category->setDisplayMode(Mage_Catalog_Model_Category::DM_PRODUCT);

    // available_sort_by and default_sort_by are required by the category
    // resource's validation in several OpenMage versions; omitting them makes
    // save() fail with an unhelpful "Please select one of the options".
    $category->setAvailableSortBy(['position']);
    $category->setDefaultSortBy('position');

    // The resource expects the PARENT path here. _beforeSave() explodes it to
    // derive level and parent_id, and _afterSave() appends the new entity id.
    $category->setPath($parentPath);

    $category->save();

    if (seed_optional_id($category->getId()) === null) {
        throw new RuntimeException(sprintf('Category "%s" was not persisted.', $name));
    }

    seed_log(sprintf('  categories: created %s (id %s)', $name, (string) $category->getId()));

    return $category;
}

$rootCategory = seed_model('catalog/category', Mage_Catalog_Model_Category::class);
$rootCategory->setStoreId(Mage_Core_Model_App::ADMIN_STORE_ID);
$rootCategory->load(seed_root_category_id());

$rootPath = $rootCategory->getPath();

if (!is_string($rootPath) || $rootPath === '') {
    seed_fail('Could not resolve the catalog root category path. Is the catalog installed?');
}

seed_log(sprintf('  categories: root category is "%s" (path %s)', (string) $rootCategory->getName(), $rootPath));

$storeCategory = seed_create_category(SEED_CATEGORY_ROOT, $rootPath);
$storePath     = $storeCategory->getPath();

if (!is_string($storePath) || $storePath === '') {
    seed_fail(sprintf('Category "%s" has no path after save.', SEED_CATEGORY_ROOT));
}

seed_create_category(SEED_CATEGORY_PRODUCTS, $storePath);
seed_create_category(SEED_CATEGORY_SECONDARY, $storePath);

seed_log('  categories: tree ready');

exit(0);
