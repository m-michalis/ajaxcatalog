<?php

abstract class InternetCode_AjaxCatalog_Model_AjaxResponse
{
    /**
     * @var Mage_Core_Controller_Front_Action
     */
    protected $_action;

    /**
     * @var null|Mage_Core_Model_Layout
     */
    protected $_layout;

    /**
     * @param array{action: Mage_Core_Controller_Front_Action} $args
     */
    public function __construct($args)
    {
        $this->_action = $args['action'];
    }

    /**
     * @return array<string, mixed>
     */
    public function getAjaxResponse()
    {
        return [
            'translate' => [],
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


    protected function getLayout(): Mage_Core_Model_Layout
    {
        if ($this->_layout !== null) {
            return $this->_layout;
        }

        $this->_layout = $this->_action->getLayout();

        return $this->_layout;
    }

}
