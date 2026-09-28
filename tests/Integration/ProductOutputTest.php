<?php

namespace Tests\Integration;

use Iterator;
use InternetCode_AjaxCatalog_Helper_Data;
use Mage;
use Mage_Catalog_Model_Product_Type;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Base\AjaxCatalogTestCase;

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
        self::assertSame(20, $this->helper->getDiscountPercent(100.0, 80.0));
        self::assertSame(0, $this->helper->getDiscountPercent(100.0, 100.0));
    }

    public function testDiscountPercentWithZeroPriceDoesNotDivideByZero(): void
    {
        self::assertSame(0, $this->helper->getDiscountPercent(0.0, 0.0));
        self::assertSame(0, $this->helper->getDiscountPercent(0.0, 10.0));
    }

    public function testDiscountPercentIsNeverNegative(): void
    {
        self::assertSame(0, $this->helper->getDiscountPercent(100.0, 120.0));
    }

    #[DataProvider('newsDateProvider')]
    public function testIsProductNew(?string $from, ?string $to, bool $expected): void
    {
        $product = Mage::getModel('catalog/product')
            ->setNewsFromDate($from)
            ->setNewsToDate($to);

        self::assertSame($expected, $this->helper->isProductNew($product, $this->getDefaultStore()));
    }

    public static function newsDateProvider(): Iterator
    {
        $day = 86400;
        $today = date('Y-m-d 00:00:00');
        $yesterday = date('Y-m-d 00:00:00', time() - $day);
        $tomorrow = date('Y-m-d 00:00:00', time() + $day);
        yield 'no dates' => [null, null, false];
        yield 'open-ended from past' => [$yesterday, null, true];
        yield 'starts tomorrow' => [$tomorrow, null, false];
        yield 'ended yesterday' => [null, $yesterday, false];
        yield 'ends today (inclusive)' => [$yesterday, $today, true];
        yield 'within interval' => [$yesterday, $tomorrow, true];
    }

    #[DataProvider('compositeTypeProvider')]
    public function testPrepareProductOutputHandlesProductsWithoutPrice(string $typeId): void
    {
        $product = $this->loadProductByType($typeId);
        $product->setPrice(null);

        $this->helper->prepareProductOutput($product);

        self::assertSame(0, $product->getData('is_sale'));
        self::assertNotEmpty($product->getData('add_to_cart_url'));
        self::assertSame($product->getData('add_to_cart_url'), $product->getData('add_to_card_url'));
    }

    public static function compositeTypeProvider(): Iterator
    {
        yield 'grouped' => [Mage_Catalog_Model_Product_Type::TYPE_GROUPED];
        yield 'bundle' => [Mage_Catalog_Model_Product_Type::TYPE_BUNDLE];
    }
}
