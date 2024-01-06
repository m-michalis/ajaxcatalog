<?php

abstract class InternetCode_AjaxCatalog_Model_AjaxResponse
{
    /**
     * @var Mage_Core_Controller_Front_Action
     */
    protected $_action;
    /**
     * @var Mage_Core_Model_Layout
     */
    protected $_layout;

    /**
     * @param $args
     */
    public function __construct($args)
    {
        $this->_action = $args['action'];
    }

    public function getAjaxResponse()
    {
        return [
            'translate' => [] //todo general translations?
        ];
    }

    /**
     * @return $this
     */
    abstract public function prepareNormalView();

    /**
     * @return $this
     */
    abstract public function prepareAjaxView();


    /**
     * @return Mage_Core_Model_Layout
     */
    protected function getLayout(): Mage_Core_Model_Layout
    {
        if ($this->_layout) {
            return $this->_layout;
        }
        $this->_layout = $this->_action->getLayout();

        return $this->_layout;
    }

}
