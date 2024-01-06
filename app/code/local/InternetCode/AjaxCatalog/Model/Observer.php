<?php

class InternetCode_AjaxCatalog_Model_Observer
{

    /**
     * @var Mage_Core_Controller_Varien_Action
     */
    private $_action;

    public function prepareForAjaxCatalog($event)
    {
        Varien_Profiler::start('PREPARE_AJAX_CATALOG');
        $this->_action = $event->getAction();

        $module = $this->getRequest()->getModuleName();
        $controller = $this->getRequest()->getControllerName();
        $action = $this->getRequest()->getActionName();

        $route = implode('_', [$module, $controller, $action]);

        $ajaxModel = $this->getAjaxRoute($route);
        if (!($ajaxModel instanceof InternetCode_AjaxCatalog_Model_AjaxResponse)) {
            return;
        }

        if (!$this->getRequest()->isAjax()) {
            $ajaxModel->prepareNormalView();
            return;
        }

        $ajaxModel->prepareAjaxView();

        $response = $ajaxModel->getAjaxResponse();
        Mage::app()->getFrontController()->setNoRender(true);
        $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($response));
        $this->getResponse()->setHeader('content-type', 'application/json');
        $this->getRequest()->setDispatched(true);
        Varien_Profiler::stop('PREPARE_AJAX_CATALOG');
    }

    /**
     * @param $route
     * @return false|InternetCode_AjaxCatalog_Model_AjaxResponse
     */
    private function getAjaxRoute($route)
    {
        $ajaxRoutesConfig = Mage::getConfig()->getNode(Mage_Core_Model_App_Area::AREA_FRONTEND)->ajaxroutes;

        if ($ajaxRoutesConfig->{$route}) {
            return Mage::getModel((string)$ajaxRoutesConfig->{$route}->class, ['action' => $this->_action]);
        }
        return false;
    }


    /**
     * @return Mage_Core_Controller_Response_Http
     */
    private function getResponse(): Mage_Core_Controller_Response_Http
    {
        return $this->_action->getResponse();
    }

    /**
     * @return Mage_Core_Controller_Request_Http
     */
    private function getRequest(): Mage_Core_Controller_Request_Http
    {
        return $this->_action->getRequest();
    }
}
