<?php

use InternetCode_AjaxCatalog_Block_Webpack as Webpack;

class AssetsTest extends AjaxCatalogTestCase
{
    public function testManifestIsPreferredOverDirectoryListing(): void
    {
        $dir = $this->createTempDir([
            'manifest.json' => json_encode([
                'home.js' => 'home.111.js',
                'home.css' => '/assets/home.111.css',
                'home.js.map' => 'home.111.js.map',
                'shared.js' => 'shared.222.js',
                'shared.css' => 'shared.222.css',
            ]),
            // stale build output that the manifest does not reference
            'home.000.js' => 1,
            'shared.000.js' => 1,
        ]);

        $files = $this->createAssets($dir)->getFilesByRoute();

        $this->assertSame(['home.111.js', 'shared.222.js'], $files['cms_index_index'][Webpack::ASSET_JS]);
        $this->assertSame(['home.111.css', 'shared.222.css'], $files['cms_index_index'][Webpack::ASSET_CSS]);
        $this->assertSame(['shared.222.js'], $files['default'][Webpack::ASSET_JS]);
    }

    public function testFallbackWithoutManifestPrefersNewestBuild(): void
    {
        $dir = $this->createTempDir([
            'home.000.js' => 1000,
            'home.111.js' => 2000,
            'shared.000.js' => 1000,
            'shared.222.js' => 2000,
            'home.111.js.map' => 2000,
            'notes.txt' => 2000,
        ]);

        $files = $this->createAssets($dir)->getFilesByRoute();

        $this->assertSame(['home.111.js', 'shared.222.js'], $files['cms_index_index'][Webpack::ASSET_JS]);
    }

    public function testInvalidManifestFallsBackToDirectoryListing(): void
    {
        $dir = $this->createTempDir([
            'manifest.json' => '{not json',
            'home.111.js' => 2000,
        ]);

        $files = $this->createAssets($dir)->getFilesByRoute();

        $this->assertSame(['home.111.js'], $files['cms_index_index'][Webpack::ASSET_JS]);
    }

    public function testCriticalCssMovesRouteCssToUncritical(): void
    {
        $dir = $this->createTempDir([
            'critical.home.aaa.css' => 2000,
            'critical.home.aaa.css.map' => 2000,
            'home.111.css' => 2000,
            'shared.222.css' => 2000,
        ]);

        $files = $this->createAssets($dir)->getFilesByRoute();
        $home = $files['cms_index_index'];

        $this->assertSame(['critical.home.aaa.css'], $home[Webpack::ASSET_CRITICAL]);
        $this->assertSame(['home.111.css', 'shared.222.css'], $home[Webpack::ASSET_UNCRITICAL]);
        $this->assertArrayNotHasKey(Webpack::ASSET_CSS, $home);
    }

    public function testCriticalCssOutsideManifestIsStillFound(): void
    {
        // critical css is generated after the webpack build, so it may be missing from the manifest
        $dir = $this->createTempDir([
            'manifest.json' => json_encode(['home.css' => 'home.111.css']),
            'critical.home.old.css' => 1000,
            'critical.home.new.css' => 2000,
        ]);

        $home = $this->createAssets($dir)->getFilesByRoute()['cms_index_index'];

        $this->assertSame(['critical.home.new.css'], $home[Webpack::ASSET_CRITICAL]);
        $this->assertSame(['home.111.css'], $home[Webpack::ASSET_UNCRITICAL]);
    }

    public function testOnlyNewestCriticalAndUncriticalFilesAreUsed(): void
    {
        $dir = $this->createTempDir([
            'critical.home.old.css' => 1000,
            'critical.home.new.css' => 2000,
            'uncritical.home.old.css' => 1000,
            'uncritical.home.new.css' => 2000,
        ]);

        $home = $this->createAssets($dir)->getFilesByRoute()['cms_index_index'];

        $this->assertSame(['critical.home.new.css'], $home[Webpack::ASSET_CRITICAL]);
        $this->assertSame(['uncritical.home.new.css'], $home[Webpack::ASSET_UNCRITICAL]);
    }

    public function testExplicitUncriticalCssIsKept(): void
    {
        $dir = $this->createTempDir([
            'critical.home.aaa.css' => 2000,
            'uncritical.home.bbb.css' => 2000,
            'home.111.css' => 2000,
        ]);

        $home = $this->createAssets($dir)->getFilesByRoute()['cms_index_index'];

        $this->assertSame(['uncritical.home.bbb.css'], $home[Webpack::ASSET_UNCRITICAL]);
    }

    public function testCriticalWithoutRouteCssDoesNotWarn(): void
    {
        $dir = $this->createTempDir(['critical.home.aaa.css' => 2000]);

        $home = $this->createAssets($dir)->getFilesByRoute()['cms_index_index'];

        $this->assertSame([], $home[Webpack::ASSET_UNCRITICAL]);
    }

    public function testExcludingCriticalKeepsRouteCss(): void
    {
        $dir = $this->createTempDir([
            'critical.home.aaa.css' => 2000,
            'home.111.css' => 2000,
        ]);

        $home = $this->createAssets($dir)->getFilesByRoute(false)['cms_index_index'];

        $this->assertSame(['home.111.css'], $home[Webpack::ASSET_CSS]);
        $this->assertArrayNotHasKey(Webpack::ASSET_CRITICAL, $home);
    }

    public function testMissingAssetDirIsNotCreated(): void
    {
        $dir = sys_get_temp_dir() . '/ajaxcatalog_missing_' . bin2hex(random_bytes(6));

        $assets = $this->createAssets($dir);

        $this->assertSame([], $assets->getFilesByRoute());
        $this->assertSame(
            'https://dummyimage.com/1200x1200/FF0000/ffffff.png?text=MISSING%20IMAGE:'
                . Mage::helper('core')->urlEncode('assets/media/logo.png'),
            $assets->getImageUrl('logo.png')
        );
        $this->assertDirectoryDoesNotExist($dir);
    }

    public function testUrlUsesWebBaseUrlWithoutStoreCode(): void
    {
        $store = $this->getDefaultStore();
        $previousStore = Mage::app()->getStore()->getId();
        $store->setConfig(Mage_Core_Model_Store::XML_PATH_STORE_IN_URL, '1');
        Mage::app()->setCurrentStore($store);

        try {
            $url = $this->createAssets($this->createTempDir())->getUrl('home.111.js');
            $this->assertSame($store->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB) . 'assets/home.111.js', $url);
            $this->assertStringNotContainsString('/' . $store->getCode() . '/', $url);
        } finally {
            $store->setConfig(Mage_Core_Model_Store::XML_PATH_STORE_IN_URL, '0');
            Mage::app()->setCurrentStore($previousStore);
        }
    }

    public function testAbsoluteManifestUrlsArePassedThrough(): void
    {
        $assets = $this->createAssets($this->createTempDir());

        $this->assertSame('https://cdn.example.com/home.js', $assets->getUrl('https://cdn.example.com/home.js'));
        $this->assertSame('//cdn.example.com/home.js', $assets->getUrl('//cdn.example.com/home.js'));
    }

    public function testImageUrlFromManifest(): void
    {
        $dir = $this->createTempDir([
            'manifest.json' => json_encode(['media/icons/logo.png' => 'media/icons/logo.abc.png']),
        ]);

        $url = $this->createAssets($dir)->getImageUrl('/icons/logo.png');

        $this->assertStringEndsWith('/assets/media/icons/logo.abc.png', $url);
    }

    public function testImageUrlFallbackPrefersNewestAndMatchesExtension(): void
    {
        $dir = $this->createTempDir([
            'media/icons/logo.old.png' => 1000,
            'media/icons/logo.new.png' => 2000,
            'media/icons/logo.new.svg' => 3000,
        ]);

        $url = $this->createAssets($dir)->getImageUrl('icons/logo.png');

        $this->assertStringEndsWith('/assets/media/icons/logo.new.png', $url);
    }

    public function testHelperDelegatesToAssetsModel(): void
    {
        $dir = $this->createTempDir(['home.111.js' => 2000]);
        Mage::getSingleton('ajaxcatalog/assets')->setAssetDir($dir);

        try {
            $files = Mage::helper('ajaxcatalog')->getWebpackFilesByRoute();
            $this->assertSame(['home.111.js'], $files['cms_index_index'][Webpack::ASSET_JS]);
        } finally {
            Mage::getSingleton('ajaxcatalog/assets')->setAssetDir(null);
        }
    }
}
