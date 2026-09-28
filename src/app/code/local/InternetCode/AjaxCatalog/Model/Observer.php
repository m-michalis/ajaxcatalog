<?php

class InternetCode_AjaxCatalog_Model_Observer
{
    /**
     * @var Mage_Core_Controller_Varien_Action
     */
    private $_action;

    /**
     * @param  Varien_Event_Observer $event
     * @return void
     */
    public function prepareForAjaxCatalog($event)
    {
        Varien_Profiler::start('PREPARE_AJAX_CATALOG');
        try {
            $this->_prepareForAjaxCatalog($event);
        } finally {
            Varien_Profiler::stop('PREPARE_AJAX_CATALOG');
        }
    }

    private function _prepareForAjaxCatalog(Varien_Event_Observer $event): void
    {
        $this->_action = $event->getAction();

        $module = $this->getRequest()->getModuleName();
        $controller = $this->getRequest()->getControllerName();
        $action = $this->getRequest()->getActionName();

        $route = implode('_', [$module, $controller, $action]);

        $ajaxModel = $this->getAjaxRoute($route);
        if (!($ajaxModel instanceof InternetCode_AjaxCatalog_Model_AjaxResponse)) {
            return;
        }

        $isAjax = $this->getRequest()->isAjax();
        Mage::helper('ajaxcatalog')->applyResponseHeaders($this->getResponse(), $isAjax);

        if (!$isAjax) {
            $ajaxModel->prepareNormalView();
            return;
        }

        $ajaxModel->prepareAjaxView();

        $response = $ajaxModel->getAjaxResponse();
        Mage::app()->getFrontController()->setNoRender(true);
        $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($response));
        $this->getResponse()->setHeader('content-type', 'application/json', true);
        $this->getRequest()->setDispatched(true);
    }

    /**
     * @param  string                                            $route
     * @return false|InternetCode_AjaxCatalog_Model_AjaxResponse
     */
    private function getAjaxRoute($route)
    {
        $config = Mage::getConfig();
        $routeConfig = $config instanceof Mage_Core_Model_Config
            ? $config->getNode(Mage_Core_Model_App_Area::AREA_FRONTEND . '/ajaxroutes/' . $route)
            : false;
        if ($routeConfig === false) {
            return false;
        }

        $class = (string) $routeConfig->class;
        $model = $class !== '' ? Mage::getModel($class, ['action' => $this->_action]) : false;

        return $model instanceof InternetCode_AjaxCatalog_Model_AjaxResponse ? $model : false;
    }


    private function getResponse(): Mage_Core_Controller_Response_Http
    {
        return $this->_action->getResponse();
    }

    private function getRequest(): Mage_Core_Controller_Request_Http
    {
        return $this->_action->getRequest();
    }
}
