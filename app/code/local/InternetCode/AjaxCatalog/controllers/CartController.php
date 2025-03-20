<?php
include_once Mage::getModuleDir('controllers', 'Mage_Checkout') . DS . 'CartController.php';

class InternetCode_AjaxCatalog_CartController extends Mage_Checkout_CartController
{

    public function dataAction()
    {
        $this->loadLayout();
        $minicart = $this->getLayout()->getBlock('minicart_content');
        $this->getResponse()->setHeader('Content-type', 'application/json',true);
        $this->getResponse()->setBody(json_encode([
            'content' => $minicart->toHtml(),
            'count' => $minicart->getSummaryCount()
        ], JSON_HEX_TAG));
    }


    public function addAction()
    {
        try {
            if (!$this->_validateFormKey()) {
                Mage::throwException('Invalid form key');
            }
            $result = [];
            $cart = $this->_getCart();
            $params = $this->getRequest()->getParams();

            if (isset($params['qty'])) {
                $filter = new Zend_Filter_LocalizedToNormalized(
                    array('locale' => Mage::app()->getLocale()->getLocaleCode())
                );
                $params['qty'] = $filter->filter($params['qty']);
            }

            $product = $this->_initProduct();
            if (!$product) {
                Mage::throwException('Product not found');
            }

            /**
             * Check Qty Custom and show message of max qty to add
             */
            $stockItem = $product->getStockItem();
            $item = $cart->getQuote()->getItemByProduct($product);
            $qtyInCart = 0;
            if($item){
                $qtyInCart = $item->getQty();
            }
            if(!$stockItem->checkQty($params['qty']+$qtyInCart)){
                Mage::throwException(Mage::helper('ajaxcatalog')->__('Η ποσότητα που ζητήσατε δεν είναι διαθέσιμη. Μέγιστη ποσότητα: %s',(int) $stockItem->getQty()));
            }


            $cart->addProduct($product, $params);
            $cart->save();

            $this->_getSession()->setCartWasUpdated(true);
            Mage::dispatchEvent(
                'checkout_cart_add_product_complete',
                array('product' => $product, 'request' => $this->getRequest(), 'response' => $this->getResponse())
            );
            if (!$cart->getQuote()->getHasError()) {
                $result['message'] = $this->__('%s was added to your shopping cart.',
                    Mage::helper('core')->escapeHtml($product->getName()));
            }


            $this->loadLayout();
            $result['content'] = $this->getLayout()->getBlock('minicart_content')->toHtml();
            $result['qty'] = $this->_getCart()->getSummaryQty();
            $result['success'] = 1;
        }catch (Mage_Core_Exception $e){

            $result['success'] = 0;

            if ($this->_getSession()->getUseNotice(true)) {
                $result['notice'] = Mage::helper('core')->escapeHtml($e->getMessage());
            }else {
                $messages = array_unique(explode("\n", $e->getMessage()));
                foreach ($messages as $message) {
                    $result['error'][] = Mage::helper('core')->escapeHtml($message);
                }
            }

        }catch (Exception $e){
            $result['success'] = 0;
            Mage::logException($e);
            $result['error'] = $this->__('Cannot add the item to shopping cart.');
        }


        $this->getResponse()->setHeader('Content-type', 'application/json',true);
        $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($result));
    }
}
