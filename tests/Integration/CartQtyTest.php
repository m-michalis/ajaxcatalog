<?php

class CartQtyTest extends AjaxCatalogTestCase
{
    /** @var InternetCode_AjaxCatalog_Helper_Data */
    private $helper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->helper = Mage::helper('ajaxcatalog');
        // stock checks are skipped in the admin store
        Mage::app()->setCurrentStore($this->getDefaultStore());
    }

    protected function tearDown(): void
    {
        Mage::app()->setCurrentStore(Mage_Core_Model_App::ADMIN_STORE_ID);
        parent::tearDown();
    }

    private function simpleWithStock(float $qty, float $minQty = 0, float $minSaleQty = 1): Mage_Catalog_Model_Product
    {
        $product = $this->loadProductByType(Mage_Catalog_Model_Product_Type::TYPE_SIMPLE);
        // fresh stock item: the loaded one has already cached its min_sale_qty
        $stockItem = Mage::getModel('cataloginventory/stock_item')->setData($product->getStockItem()->getData());
        $product->setStockItem($stockItem);
        $stockItem
            ->setUseConfigManageStock(0)->setManageStock(1)
            ->setUseConfigBackorders(0)->setBackorders(Mage_CatalogInventory_Model_Stock::BACKORDERS_NO)
            ->setUseConfigMinQty(0)->setMinQty($minQty)
            ->setUseConfigMinSaleQty(0)->setMinSaleQty($minSaleQty)
            ->setQty($qty);

        return $product;
    }

    public function testRequestedQtyDefaultsWhenMissing(): void
    {
        $product = $this->simpleWithStock(10);

        $this->assertSame(1.0, $this->helper->getRequestedQty($product, [], false));
    }

    public function testRequestedQtyUsesParam(): void
    {
        $product = $this->simpleWithStock(10);

        $this->assertSame(3.0, $this->helper->getRequestedQty($product, ['qty' => '3'], false));
    }

    public function testRequestedQtyMirrorsCoreMinSaleQty(): void
    {
        $product = $this->simpleWithStock(10, 0, 4);

        // Mage_Checkout_Model_Cart::addProduct(): default qty is min_sale_qty, and an explicit
        // lower qty is raised to it only when the product is not in the cart yet
        $this->assertSame(4.0, $this->helper->getRequestedQty($product, [], true));
        $this->assertSame(4.0, $this->helper->getRequestedQty($product, ['qty' => 1], false));
        $this->assertSame(1.0, $this->helper->getRequestedQty($product, ['qty' => 1], true));
    }

    public function testAvailableQtyAccountsForMinQtyAndCart(): void
    {
        $product = $this->simpleWithStock(10, 2);

        $this->assertNull($this->helper->getUnavailableQtyMessage($product, 7, 1));
        $this->assertSame(
            $this->helper->__('The requested quantity is not available. Maximum quantity you can add: %s', 2),
            $this->helper->getUnavailableQtyMessage($product, 6, 3)
        );
    }

    public function testFullCartReportsZero(): void
    {
        $product = $this->simpleWithStock(5);

        $this->assertSame(
            $this->helper->__('The requested quantity is not available. Maximum quantity you can add: %s', 0),
            $this->helper->getUnavailableQtyMessage($product, 5, 1)
        );
    }

    public function testBackordersAreNotBlocked(): void
    {
        $product = $this->simpleWithStock(0);
        $product->getStockItem()->setBackorders(Mage_CatalogInventory_Model_Stock::BACKORDERS_YES_NONOTIFY);

        $this->assertNull($this->helper->getUnavailableQtyMessage($product, 0, 5));
    }

    public function testCompositeProductsAreLeftToCore(): void
    {
        // parent stock rows always have qty 0 — core validates the children instead
        $product = $this->loadProductByType(Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE);

        $this->assertNull($this->helper->getUnavailableQtyMessage($product, 0, 1));
    }
}
