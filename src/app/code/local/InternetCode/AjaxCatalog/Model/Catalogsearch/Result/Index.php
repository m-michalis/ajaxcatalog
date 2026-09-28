<?php

class InternetCode_AjaxCatalog_Model_Catalogsearch_Result_Index extends InternetCode_AjaxCatalog_Model_Catalog_Category_View
{
    /**
     * @return null|Mage_Catalog_Block_Product_List
     */
    public function getProductListBlock()
    {
        $block = $this->getLayout()->getBlock('search_result_list');

        return $block instanceof Mage_Catalog_Block_Product_List ? $block : null;
    }

    public function prepareNormalView()
    {
        $block = $this->getLayout()->getBlock('search.result');
        if ($block !== false) {
            $block->unsetChildren();
        }

        return $this;
    }
}
