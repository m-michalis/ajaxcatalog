<?php

namespace Tests\Integration;

use InternetCode_AjaxCatalog_Helper_Data;
use Mage;
use Mage_Core_Controller_Request_Http;
use Mage_Core_Controller_Response_Http;
use Mage_Core_Helper_Data;
use Mage_Core_Model_Config;
use Mage_Core_Model_Store;
use Tests\Base\AjaxCatalogTestCase;

/**
 * Response headers for the dual HTML/JSON routes and access to the critical-CSS endpoint.
 */
class HttpTest extends AjaxCatalogTestCase
{
    private const TOKEN_PATH = InternetCode_AjaxCatalog_Helper_Data::XML_PATH_CRITICAL_TOKEN;

    protected function tearDown(): void
    {
        Mage::setIsDeveloperMode(true);
        $this->mageConfig()->setNode(self::TOKEN_PATH, '');
        unset($_SERVER['HTTP_X_AJAXCATALOG_TOKEN']);
        parent::tearDown();
    }

    private function mageConfig(): Mage_Core_Model_Config
    {
        $config = Mage::getConfig();
        self::assertInstanceOf(Mage_Core_Model_Config::class, $config);

        return $config;
    }

    /**
     * @return array<string, string>
     */
    private function headers(Mage_Core_Controller_Response_Http $response): array
    {
        $headers = [];
        foreach ($response->getHeaders() as $header) {
            $headers[strtolower($header['name'])] = $header['value'];
        }

        return $headers;
    }

    public function testHtmlResponseVariesOnRequestedWith(): void
    {
        $response = new Mage_Core_Controller_Response_Http();

        Mage::helper('ajaxcatalog')->applyResponseHeaders($response, false);

        $headers = $this->headers($response);
        self::assertSame('X-Requested-With', $headers['vary']);
        self::assertArrayNotHasKey('cache-control', $headers);
    }

    public function testJsonResponseIsNotCacheable(): void
    {
        $response = new Mage_Core_Controller_Response_Http();

        Mage::helper('ajaxcatalog')->applyResponseHeaders($response, true);

        $headers = $this->headers($response);
        self::assertSame('X-Requested-With', $headers['vary']);
        self::assertSame('private, no-store', $headers['cache-control']);
    }

    public function testCriticalAllowedInDeveloperMode(): void
    {
        Mage::setIsDeveloperMode(true);

        self::assertTrue(Mage::helper('ajaxcatalog')->isCriticalAccessAllowed(new Mage_Core_Controller_Request_Http()));
    }

    public function testCriticalInDeveloperModeHonoursDevIpRestriction(): void
    {
        Mage::setIsDeveloperMode(true);
        $store = Mage::app()->getStore();
        self::assertInstanceOf(Mage_Core_Model_Store::class, $store);
        $store->setConfig(Mage_Core_Helper_Data::XML_PATH_DEV_ALLOW_IPS, '203.0.113.10');
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
        Mage::unregister('_helper/core/http'); // it caches the remote address

        try {
            self::assertFalse(Mage::helper('ajaxcatalog')->isCriticalAccessAllowed(new Mage_Core_Controller_Request_Http()));
        } finally {
            $store->setConfig(Mage_Core_Helper_Data::XML_PATH_DEV_ALLOW_IPS, '');
            unset($_SERVER['REMOTE_ADDR']);
            Mage::unregister('_helper/core/http');
        }
    }

    public function testCriticalDeniedInProductionWithoutToken(): void
    {
        Mage::setIsDeveloperMode(false);
        $_SERVER['HTTP_X_AJAXCATALOG_TOKEN'] = '';

        self::assertFalse(Mage::helper('ajaxcatalog')->isCriticalAccessAllowed(new Mage_Core_Controller_Request_Http()));
    }

    public function testCriticalRequiresMatchingToken(): void
    {
        Mage::setIsDeveloperMode(false);
        $this->mageConfig()->setNode(self::TOKEN_PATH, 's3cret-token');
        $helper = Mage::helper('ajaxcatalog');

        $request = new Mage_Core_Controller_Request_Http();
        $request->setParam('token', 's3cret-token');
        self::assertFalse($helper->isCriticalAccessAllowed($request), 'token must come from the header');

        $_SERVER['HTTP_X_AJAXCATALOG_TOKEN'] = 'nope';
        self::assertFalse($helper->isCriticalAccessAllowed(new Mage_Core_Controller_Request_Http()));

        $_SERVER['HTTP_X_AJAXCATALOG_TOKEN'] = 's3cret-token';
        self::assertTrue($helper->isCriticalAccessAllowed(new Mage_Core_Controller_Request_Http()));
    }
}
