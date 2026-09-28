<?php

namespace Tests\Integration\Seed;

use Mage_Catalog_Model_Category;
use Mage_Catalog_Model_Product;
use Mage_Catalog_Model_Product_Status;
use Mage_Catalog_Model_Product_Visibility;
use Mage_CatalogInventory_Model_Stock_Item;
use Mage;
use Mage_Eav_Model_Entity_Attribute;
use Mage_Eav_Model_Entity_Attribute_Source_Abstract;
use Tests\Base\AbstractTestCase;

/**
 * Proves the category, product and attribute seeders really work.
 *
 * Counts are asserted with "at least", never "exactly": these tests share a
 * database with CoreSeedTest and with whatever the developer seeded by hand,
 * so an exact count would be testing the environment rather than the seeder.
 * Idempotence is asserted as "the count did not change across two runs", which
 * is the property that actually matters.
 */
class CatalogSeedTest extends AbstractTestCase
{
    use SeedRunner;

    /**
     * How many products the catalog tests seed.
     */
    private const PRODUCT_COUNT = 5;

    public function testCategorySeederCreatesTheQaTree(): void
    {
        $this->runSeeder('categories');

        $root = seed_find_category(SEED_CATEGORY_ROOT);

        self::assertInstanceOf(
            Mage_Catalog_Model_Category::class,
            $root,
            sprintf('Category "%s" was not created.', SEED_CATEGORY_ROOT),
        );

        foreach ([SEED_CATEGORY_PRODUCTS, SEED_CATEGORY_SECONDARY] as $name) {
            $child = seed_find_category($name);

            self::assertInstanceOf(
                Mage_Catalog_Model_Category::class,
                $child,
                sprintf('Category "%s" was not created.', $name),
            );

            self::assertSame(
                seed_id($root->getId(), 'QA root category'),
                seed_id($child->getParentId(), 'QA child category parent'),
                sprintf('Category "%s" is not a child of "%s".', $name, SEED_CATEGORY_ROOT),
            );

            self::assertSame('1', (string) $child->getIsActive());
        }
    }

    public function testCategorySeederIsIdempotent(): void
    {
        $this->runSeeder('categories');
        $before = $this->countCategories();

        $this->runSeeder('categories');
        $after = $this->countCategories();

        self::assertSame($before, $after, 'Running the category seeder twice created duplicates.');
        self::assertGreaterThanOrEqual(3, $after);
    }

    public function testProductSeederCreatesDeterministicSkus(): void
    {
        $this->runSeeder('categories');
        $this->runSeeder('products', [(string) self::PRODUCT_COUNT]);

        for ($index = 1; $index <= self::PRODUCT_COUNT; $index++) {
            $sku     = seed_gen_sku($index);
            $product = seed_find_product($sku);

            self::assertInstanceOf(
                Mage_Catalog_Model_Product::class,
                $product,
                sprintf('Product "%s" was not created.', $sku),
            );

            self::assertSame(seed_gen_name($index), (string) $product->getName());
            self::assertSame(seed_gen_price($index), (float) $product->getPrice());
            self::assertSame(
                Mage_Catalog_Model_Product_Status::STATUS_ENABLED,
                (int) $product->getStatus(),
            );
            self::assertSame(
                Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH,
                (int) $product->getVisibility(),
            );
        }

        self::assertSame('QA-0001', seed_gen_sku(1), 'SKU generation is no longer deterministic.');
    }

    public function testProductStockIsReadableThroughTheStockItem(): void
    {
        $this->runSeeder('categories');
        $this->runSeeder('products', [(string) self::PRODUCT_COUNT]);

        $product = seed_find_product(seed_gen_sku(1));

        self::assertInstanceOf(Mage_Catalog_Model_Product::class, $product);

        $stockItem = $product->getStockItem();

        // catalog_product_load_after decorates the product with its stock item,
        // but only when Mage_CatalogInventory's observer is in scope; load it
        // directly otherwise so the assertion tests stock, not the observer.
        if (!$stockItem instanceof Mage_CatalogInventory_Model_Stock_Item) {
            $stockItem = Mage::getModel('cataloginventory/stock_item');

            self::assertInstanceOf(Mage_CatalogInventory_Model_Stock_Item::class, $stockItem);

            $stockItem->loadByProduct($product);
        }

        // qty and is_in_stock are NOT product attributes: proving they read
        // back here is proving setStockData() reached cataloginventory_stock_item.
        self::assertSame(seed_gen_qty(1), (int) $stockItem->getQty());
        self::assertSame(1, (int) $stockItem->getIsInStock());
    }

    public function testProductSeederIsIdempotent(): void
    {
        $this->runSeeder('categories');
        $this->runSeeder('products', [(string) self::PRODUCT_COUNT]);
        $before = $this->countSeededEntities();

        $this->runSeeder('products', [(string) self::PRODUCT_COUNT]);
        $after = $this->countSeededEntities();

        self::assertSame(
            $before['products'],
            $after['products'],
            'Running the product seeder twice changed the product count.',
        );
        self::assertGreaterThanOrEqual(self::PRODUCT_COUNT, $after['products']);
    }

    public function testAttributeSeederCreatesAttributesIdempotently(): void
    {
        $this->runSeeder('attributes');

        $textCode   = SEED_CODE_PREFIX . 'supplier_code';
        $selectCode = SEED_CODE_PREFIX . 'grade';

        $textId   = seed_attribute_id('catalog_product', $textCode);
        $selectId = seed_attribute_id('catalog_product', $selectCode);

        self::assertNotNull($textId, sprintf('Attribute "%s" was not created.', $textCode));
        self::assertNotNull($selectId, sprintf('Attribute "%s" was not created.', $selectCode));

        $this->runSeeder('attributes');

        self::assertSame(
            $textId,
            seed_attribute_id('catalog_product', $textCode),
            'Running the attribute seeder twice replaced the text attribute.',
        );
        self::assertSame(
            $selectId,
            seed_attribute_id('catalog_product', $selectCode),
            'Running the attribute seeder twice replaced the select attribute.',
        );

        // The select attribute is worthless without its options.
        $attribute = Mage::getModel('eav/entity_attribute');

        self::assertInstanceOf(Mage_Eav_Model_Entity_Attribute::class, $attribute);

        $attribute->loadByCode('catalog_product', $selectCode);
        $source = $attribute->getSource();

        self::assertInstanceOf(Mage_Eav_Model_Entity_Attribute_Source_Abstract::class, $source);
        self::assertNotFalse(
            $source->getOptionId('Gold'),
            sprintf('Attribute "%s" has no "Gold" option.', $selectCode),
        );
    }

    /**
     * How many QA-prefixed categories currently exist.
     */
    private function countCategories(): int
    {
        $collection = seed_category_collection();
        $collection->addAttributeToFilter('name', ['like' => SEED_LABEL_PREFIX . '%']);

        return $collection->getSize();
    }
}
