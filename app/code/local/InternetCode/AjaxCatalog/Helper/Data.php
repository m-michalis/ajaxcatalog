<?php


class InternetCode_AjaxCatalog_Helper_Data extends Mage_Core_Helper_Abstract
{

    public static function getEntries()
    {
        $ajaxRoutesConfig = Mage::getConfig()->getNode(Mage_Core_Model_App_Area::AREA_FRONTEND)->ajaxentries;
        $entries = [];

        foreach($ajaxRoutesConfig->children() as $entry => $handles){
            foreach($handles->children() as $handle => $a){
                $entries[$entry][] = $handle;
            }
        }
        return $entries;
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
     * @param Mage_Catalog_Block_Product_Abstract|null $block
     * @return void
     */
    public function prepareProductOutput(
        Mage_Catalog_Model_Product          $_product,
        Mage_Catalog_Block_Product_Abstract $block = null
    )
    {

        $extraData['entity_id'] = (int)$_product->getId();


        $extraData['is_salable'] = $_product->isSaleable();
        $extraData['is_configurable'] = $_product->canConfigure();

        if (!$block) {
            $block = Mage::app()->getLayout()->createBlock('catalog/product_list');
        }
        $extraData['price_html'] = $block->getPriceHtml($_product, true);


        $extraData['add_to_card_url'] = $block->getAddToCartUrlCustom($_product, [], true);


        $newsFrom = $_product->getNewsFromDate();
        $newsTo = $_product->getNewsToDate();
        $extraData['is_new'] = date("Y-m-d H:m:s") >= $newsFrom && date("Y-m-d H:m:s") <= $newsTo;
        $extraData['is_sale'] = round((((float)$_product->getPrice() - (float)$_product->getFinalPrice()) / (float)$_product->getPrice()) * 100);

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


    public function getWebpackFilesByRoute($inclCritical = true)
    {

        $assetPath = Mage::getBaseDir() . DS . 'assets';
        $io = new Varien_Io_File();
        $io->checkAndCreateFolder($assetPath);
        $io->cd($assetPath);
        $files = $io->ls(Varien_Io_File::GREP_FILES);


        $handleFiles = [];
        $shared = [];

        if($inclCritical) {
            // find critical css
            foreach ($files as $file) {
                $parts = explode('.', $file['text']);
                if ($parts[0] == 'critical' || $parts[0] == 'uncritical') {
                    $handles = self::getEntries()[$parts[1]] ?? [];

                    switch($parts[0]){
                        case 'critical':
                            $assetType =  InternetCode_AjaxCatalog_Block_Webpack::ASSET_CRITICAL;
                            break;
                        case 'uncritical':
                            $assetType =  InternetCode_AjaxCatalog_Block_Webpack::ASSET_UNCRITICAL;
                            break;
                        default:
                            $assetType =  InternetCode_AjaxCatalog_Block_Webpack::ASSET_CSS;
                    }

                    foreach ($handles as $handle) {
                        $handleFiles[$handle][$assetType][] = $file['text'];
                    }
                }
            }
        }

        // find route specific files
        foreach ($files as $file) {
            $parts = explode('.', $file['text']);

            $handles = self::getEntries()[$parts[0]] ?? [];

            switch ($file['filetype']) {
                case "css":
                    $assetType = InternetCode_AjaxCatalog_Block_Webpack::ASSET_CSS;
                    break;
                case "js":
                    $assetType = InternetCode_AjaxCatalog_Block_Webpack::ASSET_JS;
                    break;
                default:
                    continue;
            }

            foreach ($handles as $handle) {
                if (!isset($handleFiles[$handle][$assetType])) {
                    $handleFiles[$handle][$assetType][] = $file['text'];
                }
            }
        }

        // find shared lib files
        foreach ($files as $file) {
            $parts = explode('.', $file['text']);

            if ($parts[0] !== 'shared') {
                continue;
            }
            foreach ($handleFiles as $handle => $filesByType) {
                switch ($file['filetype']) {
                    case "css":
                        $handleFiles[$handle][InternetCode_AjaxCatalog_Block_Webpack::ASSET_CSS][] = $file['text'];
                        break;
                    case "js":
                        $handleFiles[$handle][InternetCode_AjaxCatalog_Block_Webpack::ASSET_JS][] = $file['text'];
                        break;
                }
            }
        }

        // set normal css for uncritical css. these will be loaded after onload
        foreach ($handleFiles as $handle => $filesByType) {
            if(isset($filesByType[InternetCode_AjaxCatalog_Block_Webpack::ASSET_CRITICAL])){
                $handleFiles[$handle][InternetCode_AjaxCatalog_Block_Webpack::ASSET_UNCRITICAL] = $handleFiles[$handle][InternetCode_AjaxCatalog_Block_Webpack::ASSET_CSS];
                unset($handleFiles[$handle][InternetCode_AjaxCatalog_Block_Webpack::ASSET_CSS]);
            }
        }

        return $handleFiles;
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
        $assetPath = 'assets' . DS . 'media' . DS . ltrim($imagePath, '/');;
        $reqFileInfo = pathinfo($assetPath);
        $io = new Varien_Io_File();
        $io->checkAndCreateFolder(Mage::getBaseDir() . DS . $reqFileInfo['dirname']);
        $io->cd(Mage::getBaseDir() . DS . $reqFileInfo['dirname']);
        $files = $io->ls(Varien_Io_File::GREP_FILES);

        foreach ($files as $file) {
            $servFileInfo = pathinfo($file['text']);
            $parts = explode('.', $servFileInfo['filename']);

            if ($parts[0] == $reqFileInfo['filename']) {
                return Mage::getBaseUrl() . $reqFileInfo['dirname'] .DS. $file['text'];
            }
        }
        return sprintf('https://dummyimage.com/1200x1200/FF0000/ffffff.png?text=MISSING%%20IMAGE:%s',
            Mage::helper('core')->urlEncode($assetPath)
        );
    }
}
