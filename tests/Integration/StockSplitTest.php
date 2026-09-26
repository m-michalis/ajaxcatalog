<?php

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
            ->setStoreId($this->getDefaultStore()->getId())
            ->addStoreFilter($this->getDefaultStore());
        if ($typeId) {
            $collection->addAttributeToFilter('type_id', $typeId);
        }

        return $collection;
    }

    private function countIndexed(string $typeId, int $status): int
    {
        $resource = Mage::getSingleton('core/resource');
        $read = $resource->getConnection('core_read');
        $select = $read->select()
            ->from(['e' => $resource->getTableName('catalog/product')], 'COUNT(*)')
            ->join(
                ['s' => $resource->getTableName('cataloginventory/stock_status')],
                's.product_id = e.entity_id',
                []
            )
            ->where('e.type_id = ?', $typeId)
            ->where('s.website_id = ?', $this->getDefaultStore()->getWebsiteId())
            ->where('s.stock_status = ?', $status);

        return (int) $read->fetchOne($select);
    }

    public function testInStockConfigurablesAreKept(): void
    {
        $collection = $this->collection(Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE);

        $this->helper->addInStockFilter($collection, $this->getDefaultStore()->getWebsite());

        $expected = $this->countIndexed(Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE, 1);
        $this->assertGreaterThan(0, $expected);
        $this->assertSame($expected, $collection->getSize());
    }

    public function testOutOfStockCountUsesStockStatus(): void
    {
        $type = Mage_Catalog_Model_Product_Type::TYPE_SIMPLE;
        $collection = $this->collection($type);

        $count = $this->helper->getOutOfStockCount($collection, $this->getDefaultStore()->getWebsite());

        $this->assertSame($this->countIndexed($type, 0), $count);
    }

    public function testOutOfStockCountDoesNotAlterCollection(): void
    {
        $collection = $this->collection();
        $before = (string) $collection->getSelect();

        $this->helper->getOutOfStockCount($collection, $this->getDefaultStore()->getWebsite());

        $this->assertSame($before, (string) $collection->getSelect());
    }

    public function testCoexistsWithOtherStockStatusJoins(): void
    {
        $collection = $this->collection(Mage_Catalog_Model_Product_Type::TYPE_SIMPLE);
        // core joins the same table under the "stock_status_index" alias
        Mage::getModel('cataloginventory/stock_status')->addIsInStockFilterToCollection($collection);
        $website = $this->getDefaultStore()->getWebsite();

        $this->helper->getOutOfStockCount($collection, $website);
        $this->helper->addInStockFilter($collection, $website);
        $this->helper->addInStockFilter($collection, $website);

        $this->assertSame(
            $this->countIndexed(Mage_Catalog_Model_Product_Type::TYPE_SIMPLE, 1),
            $collection->getSize()
        );
    }
}
