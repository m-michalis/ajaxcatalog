<?php

namespace Tests\Base;

use InternetCode_AjaxCatalog_Model_Assets;
use Mage;
use Mage_Catalog_Model_Product;
use Mage_Core_Model_Store;

/**
 * Module test base: temp asset directories and sample-data product lookups.
 */
abstract class AjaxCatalogTestCase extends AbstractTestCase
{
    /** @var string[] */
    private $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }

        $this->tempDirs = [];
        parent::tearDown();
    }

    /**
     * Create a temp directory populated with files; value is file contents,
     * or an int to be used as the file's mtime (contents left empty).
     *
     * @param array<string, int|string> $files
     */
    protected function createTempDir(array $files = []): string
    {
        $dir = sys_get_temp_dir() . '/ajaxcatalog_' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        foreach ($files as $name => $content) {
            $path = $dir . '/' . $name;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }

            file_put_contents($path, is_int($content) ? '' : $content);
            if (is_int($content)) {
                touch($path, $content);
            }
        }

        return $dir;
    }

    protected function createAssets(string $dir): InternetCode_AjaxCatalog_Model_Assets
    {
        /** @var InternetCode_AjaxCatalog_Model_Assets $assets */
        $assets = Mage::getModel('ajaxcatalog/assets');
        $assets->setAssetDir($dir);

        return $assets;
    }

    protected function getDefaultStore(): Mage_Core_Model_Store
    {
        $store = Mage::app()->getDefaultStoreView();
        self::assertInstanceOf(Mage_Core_Model_Store::class, $store);

        return $store;
    }

    protected function loadProductByType(string $typeId): Mage_Catalog_Model_Product
    {
        $id = Mage::getResourceModel('catalog/product_collection')
            ->addAttributeToFilter('type_id', $typeId)
            ->setPageSize(1)
            ->getFirstItem()
            ->getId();
        self::assertNotEmpty($id, "No {$typeId} product in sample data");

        return Mage::getModel('catalog/product')
            ->setStoreId($this->getDefaultStore()->getId())
            ->load($id);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }

        rmdir($dir);
    }
}
