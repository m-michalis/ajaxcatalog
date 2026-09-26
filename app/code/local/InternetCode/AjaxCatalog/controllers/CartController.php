<?php
include_once Mage::getModuleDir('controllers', 'Mage_Checkout') . DS . 'CartController.php';

class InternetCode_AjaxCatalog_CartController extends Mage_Checkout_CartController
{
    /**
     * Only these actions are served here; the inherited core cart actions stay on checkout/cart.
     */
    const ALLOWED_ACTIONS = ['add', 'data'];

    public function preDispatch()
    {
        if (!in_array($this->getRequest()->getActionName(), self::ALLOWED_ACTIONS, true)) {
            $this->setFlag('', self::FLAG_NO_DISPATCH, true);
            $this->getResponse()->setHttpResponseCode(404);
            return $this;
        }

        return parent::preDispatch();
    }

    public function dataAction()
    {
        $this->loadLayout();
        $minicart = $this->getLayout()->getBlock('minicart_content');
        $this->getResponse()->setHeader('Content-type', 'application/json',true);
        $this->getResponse()->setHeader('Cache-Control', 'private, no-store', true);
        $this->getResponse()->setBody(json_encode([
            'content' => $minicart ? $minicart->toHtml() : '',
            'count' => $minicart ? $minicart->getSummaryCount() : $this->_getCart()->getSummaryQty()
        ], JSON_HEX_TAG));
    }


    public function addAction()
    {
        try {
            if (!$this->_validateFormKey()) {
                Mage::throwException($this->__('Invalid form key. Please refresh the page.'));
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
                Mage::throwException($this->__('Product not found.'));
            }

            $this->_validateStock($product, $params);


            $cart->addProduct($product, $params);


            $related = $this->getRequest()->getParam('related_product');
            if (!empty($related)) {
                $cart->addProductsByIds(explode(',', $related));
            }
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
        $this->getResponse()->setHeader('Cache-Control', 'private, no-store', true);
        $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($result));
    }

    /**
     * Show how many more can be added, instead of core's generic "not available" message.
     *
     * @throws Mage_Core_Exception
     */
    protected function _validateStock(Mage_Catalog_Model_Product $product, array $params)
    {
        /** @var InternetCode_AjaxCatalog_Helper_Data $helper */
        $helper = Mage::helper('ajaxcatalog');
        $quote = $this->_getCart()->getQuote();

        $item = $quote->getItemByProduct($product);
        $qtyInCart = $item ? (float) $item->getQty() : 0.0;
        $requestedQty = $helper->getRequestedQty($product, $params, $quote->hasProductId($product->getId()));

        $message = $helper->getUnavailableQtyMessage($product, $qtyInCart, $requestedQty);
        if ($message !== null) {
            Mage::throwException($message);
        }
    }
}
