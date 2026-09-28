<?php

/**
 * Seed simple products with deterministic SKUs, stock and images.
 *
 * Idempotent: an existing QA- SKU is loaded and refreshed rather than
 * duplicated, so the product count after two runs is identical. Images are
 * only attached on creation, otherwise the media gallery would grow on every
 * run even though the row count did not.
 *
 * Usage: ddev seed products [count]     (default 10)
 */

require_once __DIR__ . '/generator.php';

$count          = seed_int_arg(10);
$attributeSetId = seed_default_attribute_set_id();
$taxClassId     = seed_product_tax_class_id();
$websiteId      = seed_default_website_id();

$category    = seed_find_category(SEED_CATEGORY_PRODUCTS);
$categoryIds = [];

if (!$category instanceof Mage_Catalog_Model_Category) {
    seed_warn(sprintf(
        'Category "%s" does not exist; products will not be categorised. Run "ddev seed categories" first.',
        SEED_CATEGORY_PRODUCTS,
    ));
} else {
    $categoryIds = [seed_id($category->getId(), 'Seeded product category')];
}

$created = 0;
$updated = 0;
$failed  = 0;

for ($index = 1; $index <= $count; $index++) {
    $sku      = seed_gen_sku($index);
    $product  = seed_find_product($sku);
    $isNew    = !$product instanceof Mage_Catalog_Model_Product;

    if (!$product instanceof Mage_Catalog_Model_Product) {
        $product = seed_model('catalog/product', Mage_Catalog_Model_Product::class);
        $product->setSku($sku);
        $product->setTypeId(Mage_Catalog_Model_Product_Type::TYPE_SIMPLE);
        // Resolved from the entity type, never hardcoded to 4.
        $product->setAttributeSetId($attributeSetId);
    }

    $product->setStoreId(Mage_Core_Model_App::ADMIN_STORE_ID);
    $product->setName(seed_gen_name($index));
    $product->setDescription(seed_gen_description($index));
    $product->setShortDescription(seed_gen_short_description($index));
    $product->setPrice(seed_gen_price($index));
    $product->setWeight(1.0);
    $product->setTaxClassId($taxClassId);
    $product->setStatus(Mage_Catalog_Model_Product_Status::STATUS_ENABLED);
    $product->setVisibility(Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH);
    $product->setWebsiteIds([$websiteId]);

    if ($categoryIds !== []) {
        $product->setCategoryIds($categoryIds);
    }

    // The qa_supplier_code attribute may not exist yet; setting unknown data on
    // a product is harmless, it is simply dropped on save.
    $product->setData(SEED_CODE_PREFIX . 'supplier_code', seed_gen_supplier_code($index));

    // qty and is_in_stock are NOT product attributes: they live in
    // cataloginventory_stock_item. $product->setQty(10)->save() silently
    // no-ops. setStockData() before save() is the supported path — the
    // cataloginventory observer picks the array up during catalog_product_save
    // and writes the stock item for us.
    $product->setStockData([
        'qty'                     => seed_gen_qty($index),
        'is_in_stock'             => 1,
        'use_config_manage_stock' => 1,
    ]);

    if ($isNew) {
        $image = seed_gen_image($index);

        if ($image !== null) {
            try {
                // $move = false copies the file, $exclude = false keeps it
                // visible in the gallery rather than hiding it.
                $product->addImageToMediaGallery($image, ['image', 'small_image', 'thumbnail'], false, false);
            } catch (Exception $exception) {
                seed_warn(sprintf('Could not attach image to %s: %s', $sku, $exception->getMessage()));
            }
        }
    }

    try {
        $product->save();
    } catch (Exception $exception) {
        seed_warn(sprintf('Could not save %s: %s', $sku, $exception->getMessage()));
        $failed++;

        continue;
    }

    if ($isNew) {
        $created++;
    } else {
        $updated++;
    }
}

seed_log(sprintf(
    '  products: %d created, %d refreshed, %d failed (%d requested, prefix "%s")',
    $created,
    $updated,
    $failed,
    $count,
    SEED_SKU_PREFIX,
));

if ($category instanceof Mage_Catalog_Model_Category) {
    seed_log(sprintf('  products: assigned to category "%s"', SEED_CATEGORY_PRODUCTS));
}

exit(0);
