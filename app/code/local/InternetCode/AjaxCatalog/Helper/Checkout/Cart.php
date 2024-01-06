<?php

class InternetCode_AjaxCatalog_Helper_Checkout_Cart extends Mage_Checkout_Helper_Cart
{

    /**
     * Retrieve url for add product to cart with or without Form Key
     *
     * @param Mage_Catalog_Model_Product $product
     * @param array $additional
     * @param bool $addFormKey
     * @return string
     */
    public function getAddUrlCustom($product, $additional = [], $addFormKey = true)
    {
        $routeParams = [
            'product' => $product->getEntityId(),
        ];
        if ($addFormKey) {
            $routeParams[Mage_Core_Model_Url::FORM_KEY] = $this->_getSingletonModel('core/session')->getFormKey();
        }
        if (!empty($additional)) {
            $routeParams = array_merge($routeParams, $additional);
        }
        if ($product->hasUrlDataObject()) {
            $routeParams['_store'] = $product->getUrlDataObject()->getStoreId();
            $routeParams['_store_to_url'] = true;
        }
        if (
            $this->_getRequest()->getRouteName() == 'checkout'
            && $this->_getRequest()->getControllerName() == 'cart'
        ) {
            $routeParams['in_cart'] = 1;
        }

        return $this->_getUrl('ajaxcatalog/cart/add', $routeParams);
    }
}
