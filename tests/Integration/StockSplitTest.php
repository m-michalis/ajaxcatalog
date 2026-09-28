<?php

namespace Tests\Integration;

use InternetCode_AjaxCatalog_Helper_Stock;
use Mage;
use Mage_Catalog_Model_Resource_Product_Collection;
use Mage_Catalog_Model_Product_Type;
use Mage_Core_Model_Website;
use Varien_Db_Adapter_Interface;
use Tests\Base\AjaxCatalogTestCase;

class StockSplitTest extends AjaxCatalogTestCase
{
    /** @var InternetCode_AjaxCatalog_Helper_Stock */
    private $helper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->helper = Mage::helper('ajaxcatalog/stock');
    }

    private function collection(?string $typeId = null): Mage_Catalog_Model_Resource_Product_Collection
    {
        // like the layer collection: only products assigned to the store's website
        $collection = Mage::getResourceModel('catalog/product_collection')
            ->setStoreId((int) $this->getDefaultStore()->getId())
            ->addStoreFilter($this->getDefaultStore());
        if ($typeId !== null) {
            $collection->addAttributeToFilter('type_id', $typeId);
        }

        return $collection;
    }

    private function countIndexed(string $typeId, int $status): int
    {
        $resource = Mage::getSingleton('core/resource');
        $read = $resource->getConnection('core_read');
        self::assertInstanceOf(Varien_Db_Adapter_Interface::class, $read);
        $select = $read->select()
            ->from(['e' => $resource->getTableName('catalog/product')], 'COUNT(*)')
            ->join(
                ['s' => $resource->getTableName('cataloginventory/stock_status')],
                's.product_id = e.entity_id',
                [],
            )
            ->where('e.type_id = ?', $typeId)
            ->where('s.website_id = ?', $this->getDefaultStore()->getWebsiteId())
            ->where('s.stock_status = ?', $status);

        return (int) $read->fetchOne($select);
    }

    private function website(): Mage_Core_Model_Website
    {
        $website = $this->getDefaultStore()->getWebsite();
        self::assertInstanceOf(Mage_Core_Model_Website::class, $website);

        return $website;
    }

    public function testInStockConfigurablesAreKept(): void
    {
        $collection = $this->collection(Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE);

        $this->helper->addInStockFilter($collection, $this->website());

        $expected = $this->countIndexed(Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE, 1);
        self::assertGreaterThan(0, $expected);
        self::assertSame($expected, $collection->getSize());
    }

    public function testOutOfStockCountUsesStockStatus(): void
    {
        $type = Mage_Catalog_Model_Product_Type::TYPE_SIMPLE;
        $collection = $this->collection($type);

        $count = $this->helper->getOutOfStockCount($collection, $this->website());

        self::assertSame($this->countIndexed($type, 0), $count);
    }

    public function testOutOfStockCountDoesNotAlterCollection(): void
    {
        $collection = $this->collection();
        $before = (string) $collection->getSelect();

        $this->helper->getOutOfStockCount($collection, $this->website());

        self::assertSame($before, (string) $collection->getSelect());
    }

    public function testCoexistsWithOtherStockStatusJoins(): void
    {
        $collection = $this->collection(Mage_Catalog_Model_Product_Type::TYPE_SIMPLE);
        // core joins the same table under the "stock_status_index" alias
        Mage::getModel('cataloginventory/stock_status')->addIsInStockFilterToCollection($collection);
        $website = $this->website();

        $this->helper->getOutOfStockCount($collection, $website);
        $this->helper->addInStockFilter($collection, $website);
        $this->helper->addInStockFilter($collection, $website);

        self::assertSame(
            $this->countIndexed(Mage_Catalog_Model_Product_Type::TYPE_SIMPLE, 1),
            $collection->getSize(),
        );
    }
}
