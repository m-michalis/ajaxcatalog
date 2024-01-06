<?php
class InternetCode_AjaxCatalog_Model_Catalog_Category_View extends InternetCode_AjaxCatalog_Model_Catalog_Abstract {


    public function getProductListBlock()
    {
        return $this->getLayout()->getBlock('product_list');
    }

    public function prepareNormalView()
    {
        $this->getLayout()->getBlock('category.products')->unsetChildren();
        return $this;
    }


    public function prepareAjaxView()
    {
        $this->initProductCollection();
        return parent::prepareNormalView();
    }


    public function getAjaxResponse(): array
    {
        return array_merge_recursive([
            'collection' => [
                'items' => array_values($this->_productCollection->toArray()),
            ],
            'toolbar' => $this->getToolbarResponse(),
            'layer' => $this->getLayerResponse()
        ], parent::getAjaxResponse());
    }
}
