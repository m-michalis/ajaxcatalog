<?php


class InternetCode_AjaxCatalog_CriticalController extends Mage_Core_Controller_Front_Action {


    public function indexAction()
    {


        $files = Mage::helper('ajaxcatalog')->getWebpackFilesByRoute(false);
        $entries = InternetCode_AjaxCatalog_Helper_Data::getEntries();
        $pages = InternetCode_AjaxCatalog_Helper_Data::getCriticalEntries();
        $jsonResponse = [];

        foreach($entries as $route => $handles){
            if(!isset($pages[$route])){
                continue;
            }
            $cssFiles =[];
            foreach($handles as $handle){
                $cssFiles = array_merge($cssFiles,$files[$handle][InternetCode_AjaxCatalog_Block_Webpack::ASSET_CRITICAL]);
            }

            //fetch HTML
            $api = new GuzzleHttp\Client([
                'base_uri' => Mage::getBaseUrl(),
            ]);

            $response = $api->get($pages[$route]);

            $jsonResponse[] = [
                'route' => $route,
                'css' => array_unique($cssFiles),
                'html' => (string)$response->getBody()
            ];
        }
        $this->getResponse()
            ->setHeader('content-type','application/json')
            ->setBody(Zend_Json::encode($jsonResponse));
    }
}
