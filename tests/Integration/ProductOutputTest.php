<?php

class ProductOutputTest extends AjaxCatalogTestCase
{
    /** @var InternetCode_AjaxCatalog_Helper_Data */
    private $helper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->helper = Mage::helper('ajaxcatalog');
    }

    public function testDiscountPercent(): void
    {
        $this->assertSame(20, $this->helper->getDiscountPercent(100.0, 80.0));
        $this->assertSame(0, $this->helper->getDiscountPercent(100.0, 100.0));
    }

    public function testDiscountPercentWithZeroPriceDoesNotDivideByZero(): void
    {
        $this->assertSame(0, $this->helper->getDiscountPercent(0.0, 0.0));
        $this->assertSame(0, $this->helper->getDiscountPercent(0.0, 10.0));
    }

    public function testDiscountPercentIsNeverNegative(): void
    {
        $this->assertSame(0, $this->helper->getDiscountPercent(100.0, 120.0));
    }

    /**
     * @dataProvider newsDateProvider
     */
    public function testIsProductNew(?string $from, ?string $to, bool $expected): void
    {
        $product = Mage::getModel('catalog/product')
            ->setNewsFromDate($from)
            ->setNewsToDate($to);

        $this->assertSame($expected, $this->helper->isProductNew($product, $this->getDefaultStore()));
    }

    public static function newsDateProvider(): array
    {
        $day = 86400;
        $today = date('Y-m-d 00:00:00');
        $yesterday = date('Y-m-d 00:00:00', time() - $day);
        $tomorrow = date('Y-m-d 00:00:00', time() + $day);

        return [
            'no dates' => [null, null, false],
            'open-ended from past' => [$yesterday, null, true],
            'starts tomorrow' => [$tomorrow, null, false],
            'ended yesterday' => [null, $yesterday, false],
            'ends today (inclusive)' => [$yesterday, $today, true],
            'within interval' => [$yesterday, $tomorrow, true],
        ];
    }

    /**
     * @dataProvider compositeTypeProvider
     */
    public function testPrepareProductOutputHandlesProductsWithoutPrice(string $typeId): void
    {
        $product = $this->loadProductByType($typeId);
        $product->setPrice(null);

        $this->helper->prepareProductOutput($product);

        $this->assertSame(0, $product->getData('is_sale'));
        $this->assertNotEmpty($product->getData('add_to_cart_url'));
        $this->assertSame($product->getData('add_to_cart_url'), $product->getData('add_to_card_url'));
    }

    public static function compositeTypeProvider(): array
    {
        return [
            'grouped' => [Mage_Catalog_Model_Product_Type::TYPE_GROUPED],
            'bundle' => [Mage_Catalog_Model_Product_Type::TYPE_BUNDLE],
        ];
    }
}
