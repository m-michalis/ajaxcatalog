<?php

require_once 'Mage/Checkout/controllers/CartController.php';

class InternetCode_AjaxCatalog_CartController extends Mage_Checkout_CartController
{
    /**
     * Only these actions are served here; the inherited core cart actions stay on checkout/cart.
     */
    public const ALLOWED_ACTIONS = ['add', 'data'];

    public function preDispatch()
    {
        if (!in_array($this->getRequest()->getActionName(), self::ALLOWED_ACTIONS, true)) {
            $this->setFlag('', self::FLAG_NO_DISPATCH, true);
            $this->getResponse()->setHttpResponseCode(404);
            return $this;
        }

        return parent::preDispatch();
    }

    /**
     * @return void
     */
    public function dataAction()
    {
        $this->loadLayout();
        $minicart = $this->_getMinicartBlock();
        $hasMinicart = $minicart instanceof Mage_Core_Block_Abstract;
        $this->getResponse()->setHeader('Content-type', 'application/json', true);
        $this->getResponse()->setHeader('Cache-Control', 'private, no-store', true);
        $this->getResponse()->setBody((string) json_encode([
            'content' => $hasMinicart ? $minicart->toHtml() : '',
            'count' => $hasMinicart ? $minicart->getSummaryCount() : $this->_getCart()->getSummaryQty(),
        ], JSON_HEX_TAG));
    }

    /**
     * @return void
     */
    public function addAction()
    {
        $result = [];
        try {
            if (!$this->_validateFormKey()) {
                Mage::throwException($this->__('Invalid form key. Please refresh the page.'));
            }

            $cart = $this->_getCart();
            $params = $this->getRequest()->getParams();

            if (isset($params['qty'])) {
                $filter = new Zend_Filter_LocalizedToNormalized(
                    ['locale' => Mage::app()->getLocale()->getLocaleCode()],
                );
                $params['qty'] = $filter->filter($params['qty']);
            }

            $product = $this->_initProduct();
            if ($product === false) {
                Mage::throwException($this->__('Product not found.'));
            }

            $this->_validateStock($product, $params);


            $cart->addProduct($product, $params);


            $this->_addRelatedProducts($cart);

            $cart->save();

            $this->_getSession()->setCartWasUpdated(true);
            Mage::dispatchEvent(
                'checkout_cart_add_product_complete',
                ['product' => $product, 'request' => $this->getRequest(), 'response' => $this->getResponse()],
            );
            if (!$cart->getQuote()->getHasError()) {
                $result['message'] = $this->__(
                    '%s was added to your shopping cart.',
                    Mage::helper('core')->escapeHtml($product->getName()),
                );
            }


            $this->loadLayout();
            $minicart = $this->_getMinicartBlock();
            $result['content'] = $minicart instanceof Mage_Core_Block_Abstract ? $minicart->toHtml() : '';
            $result['qty'] = $this->_getCart()->getSummaryQty();
            $result['success'] = 1;
        } catch (Mage_Core_Exception $e) {

            $result['success'] = 0;

            if ($this->_getSession()->getUseNotice(true)) {
                $result['notice'] = Mage::helper('core')->escapeHtml($e->getMessage());
            } else {
                $messages = array_unique(explode("\n", $e->getMessage()));
                foreach ($messages as $message) {
                    $result['error'][] = Mage::helper('core')->escapeHtml($message);
                }
            }

        } catch (Exception $e) {
            $result['success'] = 0;
            Mage::logException($e);
            $result['error'] = $this->__('Cannot add the item to shopping cart.');
        }


        $this->getResponse()->setHeader('Content-type', 'application/json', true);
        $this->getResponse()->setHeader('Cache-Control', 'private, no-store', true);
        $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($result));
    }

    /**
     * @return void
     */
    private function _addRelatedProducts(Mage_Checkout_Model_Cart $cart)
    {
        $related = $this->getRequest()->getParam('related_product');
        if (is_string($related) && $related !== '' && $related !== '0') {
            $cart->addProductsByIds(explode(',', $related));
        }
    }

    private function _getMinicartBlock(): ?Mage_Core_Block_Abstract
    {
        $block = $this->getLayout()->getBlock('minicart_content');

        return $block instanceof Mage_Core_Block_Abstract ? $block : null;
    }

    /**
     * Show how many more can be added, instead of core's generic "not available" message.
     *
     * @param  array<string, mixed> $params
     * @return void
     * @throws Mage_Core_Exception
     */
    protected function _validateStock(Mage_Catalog_Model_Product $product, array $params)
    {
        /** @var InternetCode_AjaxCatalog_Helper_Data $helper */
        $helper = Mage::helper('ajaxcatalog');
        $quote = $this->_getCart()->getQuote();

        $item = $quote->getItemByProduct($product);
        $qtyInCart = $item instanceof Mage_Sales_Model_Quote_Item ? (float) $item->getQty() : 0.0;
        $requestedQty = $helper->getRequestedQty($product, $params, $quote->hasProductId((int) $product->getId()));

        $message = $helper->getUnavailableQtyMessage($product, $qtyInCart, $requestedQty);
        if ($message !== null) {
            Mage::throwException($message);
        }
    }
}
