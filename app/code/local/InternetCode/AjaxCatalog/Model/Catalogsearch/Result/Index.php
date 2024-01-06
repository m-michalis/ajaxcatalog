<?php

class InternetCode_AjaxCatalog_Model_Catalogsearch_Result_Index extends InternetCode_AjaxCatalog_Model_Catalog_Category_View
{

    public function getProductListBlock()
    {
        return $this->getLayout()->getBlock('search_result_list');
    }

    public function prepareNormalView()
    {
        $this->getLayout()->getBlock('search.result')->unsetChildren();
        return $this;
    }
}
