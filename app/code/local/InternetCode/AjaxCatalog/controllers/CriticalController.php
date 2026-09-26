<?php


/**
 * Build-time endpoint for critical css generation: returns, per critical route, its css files and rendered HTML.
 * Available in developer mode, or with the X-Ajaxcatalog-Token header matching global/ajaxcatalog/critical_token.
 */
class InternetCode_AjaxCatalog_CriticalController extends Mage_Core_Controller_Front_Action {

    const HTTP_TIMEOUT = 30;

    public function indexAction()
    {
        /** @var InternetCode_AjaxCatalog_Helper_Data $helper */
        $helper = Mage::helper('ajaxcatalog');
        if (!$helper->isCriticalAccessAllowed($this->getRequest())) {
            $this->norouteAction();
            return;
        }

        $files = $helper->getWebpackFilesByRoute(false);
        $entries = InternetCode_AjaxCatalog_Helper_Data::getEntries();
        $pages = InternetCode_AjaxCatalog_Helper_Data::getCriticalEntries();
        $jsonResponse = [];

        $api = new GuzzleHttp\Client([
            'base_uri' => Mage::getBaseUrl(),
            'timeout' => self::HTTP_TIMEOUT,
            'connect_timeout' => self::HTTP_TIMEOUT,
        ]);

        foreach($entries as $route => $handles){
            if(!isset($pages[$route])){
                continue;
            }
            $cssFiles =[];
            foreach($handles as $handle){
                $cssFiles = array_merge($cssFiles, $files[$handle][InternetCode_AjaxCatalog_Block_Webpack::ASSET_CSS] ?? []);
            }

            try {
                $html = (string) $api->get($pages[$route])->getBody();
            } catch (GuzzleHttp\Exception\GuzzleException $e) {
                Mage::logException($e);
                $html = '';
            }

            $jsonResponse[] = [
                'route' => $route,
                'css' => array_values(array_unique($cssFiles)),
                'html' => $html
            ];
        }
        $this->getResponse()
            ->setHeader('content-type','application/json')
            ->setHeader('Cache-Control', 'private, no-store', true)
            ->setBody(Zend_Json::encode($jsonResponse));
    }
}
