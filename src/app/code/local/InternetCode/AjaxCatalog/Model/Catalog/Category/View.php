<?php

class InternetCode_AjaxCatalog_Model_Catalog_Category_View extends InternetCode_AjaxCatalog_Model_Catalog_Abstract
{
    /**
     * @return null|Mage_Catalog_Block_Product_List
     */
    public function getProductListBlock()
    {
        $block = $this->getLayout()->getBlock('product_list');

        return $block instanceof Mage_Catalog_Block_Product_List ? $block : null;
    }

    public function prepareNormalView()
    {
        $block = $this->getLayout()->getBlock('category.products');
        if ($block !== false) {
            $block->unsetChildren();
        }

        return $this;
    }


    public function prepareAjaxView()
    {
        $this->initProductCollection();
        return parent::prepareNormalView();
    }


    /**
     * @return array<string, mixed>
     */
    public function getAjaxResponse(): array
    {
        return array_merge_recursive([
            'collection' => [
                'items' => array_values($this->_productCollection->toArray()),
            ],
            'toolbar' => $this->getToolbarResponse(),
            'layer' => $this->getLayerResponse(),
        ], parent::getAjaxResponse());
    }
}
