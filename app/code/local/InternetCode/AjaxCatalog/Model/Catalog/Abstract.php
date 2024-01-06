<?php
abstract class InternetCode_AjaxCatalog_Model_Catalog_Abstract extends InternetCode_AjaxCatalog_Model_AjaxResponse {

    /**
     * @var Mage_Catalog_Block_Product_List_Toolbar
     */
    protected $_toolbar;

    /**
     * @var Mage_Catalog_Block_Layer_View
     */
    private $_layerBlock;
    /**
     * @var Mage_Catalog_Model_Resource_Product_Collection
     */
    protected $_productCollection;
    protected $_state;
    /**
     * @var string
     */
    private $_outOfStockProducts;


    public function __construct($args)
    {
        parent::__construct($args);

        $this->_state = new Varien_Object();

    }

    public function prepareNormalView()
    {
        return $this;
    }

    /**
     * @return void
     */
    protected function initProductCollection()
    {
        $this->_productCollection = $this->getLayer()->getProductCollection();
        $productListBlock = $this->getProductListBlock();
        $toolbar = $this->getToolbar();
        if ($productListBlock) {

            $select = $this->_productCollection->getSelectCountSql();
            if (strpos($select, 'cataloginventory_stock_status') === false) {
                Mage::getResourceModel('cataloginventory/stock_status')
                    ->addStockStatusToSelect(
                        $select, Mage::app()->getWebsite()
                    );
            }
            $select->where('stock_status.qty <= ?', 0);
            $this->_outOfStockProducts = $this->_productCollection->getConnection()->fetchOne($select);


            $showOutOfStock = (int)Mage::app()->getRequest()->getParam('out_of_stock', 0);
            $hasStockFilter = (int)Mage::app()->getRequest()->getParam('stock', 0);
            if (!$showOutOfStock && !$hasStockFilter) {
                $this->_productCollection->getSelect()->where('stock_status.qty > ?', 0);
            }


            Mage::dispatchEvent('catalog_block_product_list_collection_before_toolbar', [
                'collection' => $this->_productCollection,
                'product_list_block' => $productListBlock,
                'toolbar' => $toolbar,
                'state' => $this->_state,
            ]);
            // use sortable parameters
            if ($orders = $productListBlock->getAvailableOrders()) {
                $toolbar->setAvailableOrders($orders);
            }
            if ($sort = $productListBlock->getSortBy()) {
                $toolbar->setDefaultOrder($sort);
            }
            if ($dir = $productListBlock->getDefaultDirection()) {
                $toolbar->setDefaultDirection($dir);
            }
            if ($modes = $productListBlock->getModes()) {
                $toolbar->setModes($modes);
            }
        }

        // set collection to toolbar and apply sort
        $toolbar->setCollection($this->_productCollection);


        Mage::dispatchEvent('catalog_block_product_list_collection', [
            'collection' => $this->_productCollection
        ]);

        foreach ($this->_productCollection->getIterator() as $product) {
            $this->prepareProductOutput($product);
        }
    }

    /**
     * @return Mage_Catalog_Model_Layer|Mage_Core_Model_Abstract|mixed
     */
    protected function getLayer()
    {
        $layer = Mage::registry('current_layer');
        if ($layer) {
            return $layer;
        }
        return Mage::getSingleton('catalog/layer');
    }

    /**
     * @return Mage_Catalog_Block_Product_List
     */
    abstract function getProductListBlock();

    /**
     * @return Mage_Catalog_Block_Product_List_Toolbar
     */
    protected function getToolbar(): Mage_Catalog_Block_Product_List_Toolbar
    {
        if ($this->_toolbar) {
            return $this->_toolbar;
        }
        $block = $this->getProductListBlock();
        if ($block) {
            $this->_toolbar = $block->getToolbarBlock();
        }else {
            $this->_toolbar = $this->getLayout()->createBlock('catalog/product_list_toolbar', microtime());
        }
        return $this->_toolbar;
    }

    /**
     * @return Mage_Catalog_Block_Layer_View
     */
    protected function getLayerBlock(): Mage_Catalog_Block_Layer_View
    {
        if ($this->_layerBlock) {
            return $this->_layerBlock;
        }

        foreach($this->getLayout()->getAllBlocks() as $abstractBlock){
            if($abstractBlock instanceof Mage_Catalog_Block_Layer_View){
                $block = $abstractBlock;
            }
        }
        if ($block) {
            $this->_layerBlock = $block;
        } else {
            $this->_layerBlock = $this->getLayout()->createBlock('catalog/layer_view', microtime());
        }

        return $this->_layerBlock;
    }


    /**
     * @param Mage_Catalog_Model_Product $_product
     * @param Mage_Catalog_Block_Product_Abstract|null $customBlock
     * @return void
     */
    public function prepareProductOutput(Mage_Catalog_Model_Product $_product)
    {
        Mage::helper('ajaxcatalog')->prepareProductOutput($_product, $this->getProductListBlock());
    }


    protected function getLayerResponse(): array
    {

        $layerBlock = $this->getLayerBlock();
        $filters = [];
        /** @var Mage_Catalog_Block_Layer_Filter_Price|Mage_Catalog_Block_Layer_Filter_Abstract $_filter */
        foreach ($layerBlock->getFilters() as $_filter) {
            if (!$_filter->getItemsCount()) {
                continue;
            }

            if($_filter instanceof Mage_Catalog_Block_Layer_Filter_Price){
                $filters['price']['title'] = $_filter->getName();
                $filters['price']['param'] = 'price';
                $filters['price']['renderer'] = 'slider';
                $filters['price']['min'] = $this->getLayer()->getProductCollection()->getMinPrice();
                $filters['price']['max'] = $this->getLayer()->getProductCollection()->getMaxPrice();

                continue;
            }

            /** @var Mage_Catalog_Model_Layer_Filter_Item $_item */
            foreach ($_filter->getItems() as $_item) {
                if ($_item->getCount()) {
                    $value = $_item->getOptionId();

                    if($_filter instanceof Mage_Catalog_Block_Layer_Filter_Attribute){
                        if($_filter->getAttributeModel()->getAttributeCode() == 'color'){
                            $filters[$_item->getFilter()->getRequestVar()]['renderer'] = 'color';
                        }
                    }else if($_filter instanceof Mage_Catalog_Block_Layer_Filter_Category){
                        $value = $_item->getValue();
                    }



                    $filters[$_item->getFilter()->getRequestVar()]['title'] = $_filter->getName();
                    $filters[$_item->getFilter()->getRequestVar()]['param'] = $_item->getFilter()->getRequestVar();
                    $filters[$_item->getFilter()->getRequestVar()]['options'][] = [
                        'label' => $_item->getLabel() . ($_filter->shouldDisplayProductCount() ? ' (' . $_item->getCount() . ')' : ''),
                        'url' => $_item->getUrl(),
                        'value' => (string) $value,
                        'selected' => $_item->getIsSelected() || $value == $_filter->getRequestValue()
                    ];
                }
            }
        }

        /** @var Mage_Catalog_Block_Layer_State $stateBlock */
        $stateBlock = $layerBlock->getChild('layer_state');
        $state = [];
        /** @var Mage_Catalog_Model_Layer_Filter_Item $item */
        foreach ($stateBlock->getActiveFilters() as $item) {
            $state[$item->getFilter()->getRequestVar()]['title'] = $item->getFilter()->getName();
            $state[$item->getFilter()->getRequestVar()]['param'] = $item->getFilter()->getRequestVar();
            $state[$item->getFilter()->getRequestVar()]['options'][] = [
                'label' => $item->getLabel(),
                'value' => (string) $item->getValue(),
                'url' => $item->getRemoveUrl()
            ];
        }

        $res = new Varien_Object([
            'filters' => array_values($filters),
            'state' => array_values($state)
        ]);
        Mage::dispatchEvent('ajaxcatalog_layer_response',[
            'response' => $res
        ]);
        return $res->getData();
    }


    /**
     * @return array
     */
    protected function getToolbarResponse(): array
    {
        $response = [];

        $availableOrders = [];
        $toolbar = $this->getToolbar();
        $helper = Mage::helper('catalog');
        foreach ($toolbar->getAvailableOrders() as $k => $order) {
            foreach (['asc', 'desc'] as $dir) {
                $directionTitle = $dir == 'asc' ? 'Ascending' : 'Descending';
                $availableOrders[] = [
                    'url' => $toolbar->getOrderUrl($k, $dir),
                    'label' => $helper->__($order . ' (' . $directionTitle . ')'),
                    'selected' => $toolbar->isOrderCurrent($k) && $toolbar->getCurrentDirection() == $dir,
                    'param' => [
                        $toolbar->getDirectionVarName() => $dir,
                        $toolbar->getOrderVarName() => $k
                    ]
                ];
            }
        }

        $availableLimits = [];
        foreach ($toolbar->getAvailableLimit() as $_key => $_limit) {
            $availableLimits[] = [
                'url' => $toolbar->getLimitUrl($_limit),
                'label' => $_limit,
                'selected' => $toolbar->isLimitCurrent($_limit),
                'param' => [
                    $toolbar->getLimitVarName() => $_limit
                ]
            ];
        }

        $this->getToolbar()->getPagerHtml(); //mock to generate and assign pager html
        /** @var Mage_Page_Block_Html_Pager $pager */
        $pager = $this->getToolbar()->getChild('product_list_toolbar_pager');


        $response['available_orders'] = $availableOrders;
        $response['available_limits'] = $availableLimits;
        $response['total_items'] = $this->getToolbar()->getTotalNum();
        $response['total_pages'] = $this->getToolbar()->getLastPageNum();
        $response['pages'] = $this->getPages($pager);
        $response['currentPageNum'] = min($this->getToolbar()->getCurrentPage(),$this->getToolbar()->getLastPageNum());
        $response['isFirstPage'] = $this->getToolbar()->isFirstPage();
        $response['isLastPage'] = $this->getToolbar()->getCurrentPage() >= $this->getToolbar()->getLastPageNum();
        $response['out_of_stock_count'] = $this->_outOfStockProducts;

        $res = new Varien_Object($response);
        Mage::dispatchEvent('ajaxcatalog_toolbar_response',[
            'response' => $res
        ]);

        return $res->getData();
    }

    private function getPages(Mage_Page_Block_Html_Pager $pager)
    {
        $pages = $pager->getPages();


        if ((int)$pages[0] !== 1) {
            array_unshift($pages, 1);
        }
        if ((int)end($pages) !== (int)$pager->getLastPageNum()) {
            $pages[] = $pager->getLastPageNum();
        }
        return $pages;
    }


    public function getAjaxResponse()
    {
        return array_merge_recursive([
            'state' => $this->_state->getData()
        ], parent::getAjaxResponse());
    }


    protected function _getCatalogConfig()
    {
        return Mage::getSingleton('catalog/config');
    }


    public function getProductCollection()
    {
        return $this->_productCollection;
    }
}
