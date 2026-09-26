<?php


class InternetCode_AjaxCatalog_Helper_Data extends Mage_Core_Helper_Abstract
{
    /**
     * Shared secret for the critical css endpoint outside developer mode (set it in app/etc/local.xml)
     */
    const XML_PATH_CRITICAL_TOKEN = 'global/ajaxcatalog/critical_token';
    const CRITICAL_TOKEN_HEADER = 'X-Ajaxcatalog-Token';

    /**
     * @var array<string, string[]>|null
     */
    private static $_entries;

    public static function getEntries()
    {
        if (self::$_entries !== null) {
            return self::$_entries;
        }

        $ajaxRoutesConfig = Mage::getConfig()->getNode(Mage_Core_Model_App_Area::AREA_FRONTEND)->ajaxentries;
        $entries = [];

        foreach($ajaxRoutesConfig->children() as $entry => $handles){
            foreach($handles->children() as $handle => $a){
                $entries[$entry][] = $handle;
            }
        }
        return self::$_entries = $entries;
    }
    public static function getCriticalEntries()
    {
        $ajaxRoutesConfig = Mage::getConfig()->getNode(Mage_Core_Model_App_Area::AREA_FRONTEND)->ajaxcritical;
        $criticalEntries = [];

        foreach($ajaxRoutesConfig->children() as $entry => $url){
            $criticalEntries[$entry] = (string) $url;
        }
        return $criticalEntries;
    }

    /**
     * @param Mage_Catalog_Model_Product $_product
     * @return null
     */
    public function prepareProductOutputWalker(Mage_Catalog_Model_Product $_product){
        return $this->prepareProductOutput($_product);
    }

    /**
     * @param Mage_Catalog_Model_Product $_product
     * @param Mage_Catalog_Block_Product_Abstract|null $block
     * @return void
     */
    public function prepareProductOutput(
        Mage_Catalog_Model_Product          $_product,
        ?Mage_Catalog_Block_Product_Abstract $block = null
    )
    {

        $extraData['entity_id'] = (int)$_product->getId();


        $extraData['is_salable'] = $_product->isSaleable();
        $extraData['is_configurable'] = $_product->canConfigure();

        if (!$block) {
            $block = Mage::app()->getLayout()->createBlock('catalog/product_list');
        }
        $extraData['price_html'] = $block->getPriceHtml($_product, true);


        $extraData['add_to_cart_url'] = $block->getAddToCartUrlCustom($_product, [], true);
        // misspelled key kept for frontends that still read it
        $extraData['add_to_card_url'] = $extraData['add_to_cart_url'];


        $extraData['is_new'] = $this->isProductNew($_product);
        $extraData['is_sale'] = $this->getDiscountPercent((float)$_product->getPrice(), (float)$_product->getFinalPrice());

        $extraData['product_url'] = $_product->getProductUrl();

        $extraDataObj = new Varien_Object($extraData);

        Mage::dispatchEvent('ajaxcatalog_prepare_product_output',[
            'extra_data' => $extraDataObj,
            'product' => $_product,
            'product_block' => $block
        ]);
        $productData = array_intersect_key($_product->getData(), array_flip(['name']));


        $_product->setData(array_merge($productData, $extraDataObj->getData()));
    }

    /**
     * Discount of the final price against the regular price, in whole percent.
     *
     * @param float $price
     * @param float $finalPrice
     * @return int
     */
    public function getDiscountPercent($price, $finalPrice)
    {
        // grouped products and dynamic-price bundles have no own price
        if ($price <= 0) {
            return 0;
        }

        return max(0, (int) round(($price - $finalPrice) / $price * 100));
    }

    /**
     * Whether today (store timezone) is within news_from_date..news_to_date; either end may be open.
     *
     * @param Mage_Catalog_Model_Product $product
     * @param Mage_Core_Model_Store|int|null $store
     * @return bool
     */
    public function isProductNew(Mage_Catalog_Model_Product $product, $store = null)
    {
        $from = $product->getNewsFromDate();
        $to = $product->getNewsToDate();
        if (is_empty_date($from) && is_empty_date($to)) {
            return false;
        }

        return Mage::app()->getLocale()->isStoreDateInInterval($store, $from, $to);
    }

    /**
     * Quantity that Mage_Checkout_Model_Cart::addProduct() will add for these request params.
     *
     * @param Mage_Catalog_Model_Product $product
     * @param array $params
     * @param bool $isInCart
     * @return float
     */
    public function getRequestedQty(Mage_Catalog_Model_Product $product, array $params, $isInCart)
    {
        $qty = isset($params['qty']) ? (float) $params['qty'] : 0.0;
        if ($qty <= 0) {
            $qty = (float) Mage::helper('catalog/product')->getDefaultQty($product);
        }

        $stockItem = $product->getStockItem();
        if (!$isInCart && !$product->isConfigurable() && $stockItem) {
            $qty = max($qty, (float) $stockItem->getMinSaleQty());
        }

        return $qty;
    }

    /**
     * Customer-facing message when cart qty + requested qty exceeds salable stock, null when it fits.
     * Composite products are left to core, which validates their children.
     *
     * @param Mage_Catalog_Model_Product $product
     * @param float $qtyInCart
     * @param float $requestedQty
     * @return string|null
     */
    public function getUnavailableQtyMessage(Mage_Catalog_Model_Product $product, $qtyInCart, $requestedQty)
    {
        $stockItem = $product->getStockItem();
        if (!$stockItem || !Mage::helper('cataloginventory')->isQty($product->getTypeId())) {
            return null;
        }
        if ($stockItem->checkQty($qtyInCart + $requestedQty)) {
            return null;
        }

        $maxQty = max(0, $stockItem->getQty() - $stockItem->getMinQty() - $qtyInCart);

        return $this->__('The requested quantity is not available. Maximum quantity you can add: %s', $maxQty * 1);
    }

    /**
     * Routes answer with HTML or JSON on the same URL, so caches must key on X-Requested-With;
     * the JSON embeds the session form key and must not be stored at all.
     *
     * @param Mage_Core_Controller_Response_Http $response
     * @param bool $isAjax
     * @return void
     */
    public function applyResponseHeaders(Mage_Core_Controller_Response_Http $response, $isAjax)
    {
        $response->setHeader('Vary', 'X-Requested-With', true);
        if ($isAjax) {
            $response->setHeader('Cache-Control', 'private, no-store', true);
        }
    }

    /**
     * The critical css endpoint renders pages server-side; allow it only in developer mode
     * (restricted by dev/restrict/allow_ips like other developer tools) or with the token configured in local.xml.
     *
     * @param Mage_Core_Controller_Request_Http $request
     * @return bool
     */
    public function isCriticalAccessAllowed(Mage_Core_Controller_Request_Http $request)
    {
        if (Mage::getIsDeveloperMode()) {
            return Mage::helper('core')->isDevAllowed();
        }

        $token = trim((string) Mage::getConfig()->getNode(self::XML_PATH_CRITICAL_TOKEN));
        $given = (string) $request->getHeader(self::CRITICAL_TOKEN_HEADER);

        return $token !== '' && hash_equals($token, $given);
    }


    public function getWebpackFilesByRoute($inclCritical = true)
    {
        return $this->_getAssets()->getFilesByRoute($inclCritical);
    }

    /**
     * Fetches hashed image url from webpack copy plugin
     *  webpack config:
     * new CopyPlugin({
     *    patterns: [
     *        {
     *            from: "./media/**",
     *            to: "[path][name].[contenthash][ext]"
     *        }
     *   ]
     * })
     *
     * @param string $imagePath
     * @return string
     */
    public function getImageAssetUrl(string $imagePath)
    {
        return $this->_getAssets()->getImageUrl($imagePath);
    }

    /**
     * @return InternetCode_AjaxCatalog_Model_Assets
     */
    protected function _getAssets()
    {
        return Mage::getSingleton('ajaxcatalog/assets');
    }
}
