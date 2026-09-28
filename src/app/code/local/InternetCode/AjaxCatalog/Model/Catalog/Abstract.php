<?php

abstract class InternetCode_AjaxCatalog_Model_Catalog_Abstract extends InternetCode_AjaxCatalog_Model_AjaxResponse
{
    /**
     * @var null|Mage_Catalog_Block_Product_List_Toolbar
     */
    protected $_toolbar;

    /**
     * @var null|Mage_Catalog_Block_Layer_View
     */
    private $_layerBlock;

    /**
     * @var Mage_Catalog_Model_Resource_Product_Collection
     */
    protected $_productCollection;

    /**
     * @var Varien_Object
     */
    protected $_state;

    /**
     * @var null|int
     */
    private $_outOfStockProducts;

    /**
     * @param array{action: Mage_Core_Controller_Front_Action} $args
     */
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
        // subclasses may still return false from getLayout()->getBlock()
        if ($productListBlock instanceof Mage_Catalog_Block_Product_List) {
            if (Mage::getStoreConfigFlag('catalog/frontend/split_frontend_catalog')) {
                $this->splitByStock();
            }


            Mage::dispatchEvent('catalog_block_product_list_collection_before_toolbar', [
                'collection' => $this->_productCollection,
                'product_list_block' => $productListBlock,
                'toolbar' => $toolbar,
                'state' => $this->_state,
            ]);
            $this->applySortableParams($productListBlock, $toolbar);
        }

        // set collection to toolbar and apply sort; core documents the param as a flat Db collection,
        // but core's own product list passes this same EAV product collection
        $toolbar->setCollection($this->_productCollection); // @phpstan-ignore argument.type


        Mage::dispatchEvent('catalog_block_product_list_collection', [
            'collection' => $this->_productCollection,
        ]);

        foreach ($this->_productCollection->getIterator() as $product) {
            $this->prepareProductOutput($product);
        }
    }

    /**
     * Use the list block's sortable parameters on the toolbar.
     *
     * @return void
     */
    private function applySortableParams(
        Mage_Catalog_Block_Product_List $productListBlock,
        Mage_Catalog_Block_Product_List_Toolbar $toolbar
    ) {
        $orders = $productListBlock->getAvailableOrders();
        if ($orders) {
            $toolbar->setAvailableOrders($orders);
        }

        $sort = $productListBlock->getSortBy();
        if ($sort) {
            $toolbar->setDefaultOrder($sort);
        }

        $dir = $productListBlock->getDefaultDirection();
        if ($dir) {
            $toolbar->setDefaultDirection($dir);
        }

        $modes = $productListBlock->getModes();
        if ($modes) {
            $toolbar->setModes($modes);
        }
    }

    /**
     * Count the unsalable products, then hide them unless requested (out_of_stock=1) or a stock filter is active.
     *
     * @return void
     */
    protected function splitByStock()
    {
        /** @var InternetCode_AjaxCatalog_Helper_Stock $stockHelper */
        $stockHelper = Mage::helper('ajaxcatalog/stock');
        $website = Mage::app()->getWebsite();
        $request = Mage::app()->getRequest();

        $this->_outOfStockProducts = $stockHelper->getOutOfStockCount($this->_productCollection, $website);

        $showOutOfStock = (int) $request->getParam('out_of_stock', 0);
        $hasStockFilter = (int) $request->getParam('stock', 0);
        if ($showOutOfStock === 0 && $hasStockFilter === 0) {
            $stockHelper->addInStockFilter($this->_productCollection, $website);
        }
    }

    /**
     * @return Mage_Catalog_Model_Layer
     */
    protected function getLayer()
    {
        $layer = Mage::registry('current_layer');
        if ($layer instanceof Mage_Catalog_Model_Layer) {
            return $layer;
        }

        return Mage::getSingleton('catalog/layer');
    }

    /**
     * @return null|Mage_Catalog_Block_Product_List
     */
    abstract public function getProductListBlock();

    protected function getToolbar(): Mage_Catalog_Block_Product_List_Toolbar
    {
        if ($this->_toolbar !== null) {
            return $this->_toolbar;
        }

        $block = $this->getProductListBlock();
        if ($block instanceof Mage_Catalog_Block_Product_List) {
            $toolbar = $block->getToolbarBlock();
        } else {
            $toolbar = $this->getLayout()->createBlock('catalog/product_list_toolbar', microtime());
        }

        if (!$toolbar instanceof Mage_Catalog_Block_Product_List_Toolbar) {
            Mage::throwException('Product list toolbar block is not available.');
        }

        $this->_toolbar = $toolbar;

        return $this->_toolbar;
    }

    protected function getLayerBlock(): Mage_Catalog_Block_Layer_View
    {
        if ($this->_layerBlock !== null) {
            return $this->_layerBlock;
        }

        $block = null;
        foreach ($this->getLayout()->getAllBlocks() as $abstractBlock) {
            if ($abstractBlock instanceof Mage_Catalog_Block_Layer_View) {
                $block = $abstractBlock;
            }
        }

        if ($block instanceof Mage_Catalog_Block_Layer_View) {
            $this->_layerBlock = $block;
        } else {
            $this->_layerBlock = $this->getLayout()->createBlock('catalog/layer_view', microtime());
        }

        return $this->_layerBlock;
    }


    /**
     * @return void
     */
    public function prepareProductOutput(Mage_Catalog_Model_Product $_product)
    {
        Mage::helper('ajaxcatalog')->prepareProductOutput($_product, $this->getProductListBlock());
    }


    /**
     * @return array<string, list<mixed>>
     */
    protected function getLayerResponse(): array
    {
        $layerBlock = $this->getLayerBlock();

        $res = new Varien_Object([
            'filters' => $this->getLayerFilters($layerBlock),
            'state' => $this->getLayerState($layerBlock),
        ]);
        Mage::dispatchEvent('ajaxcatalog_layer_response', [
            'response' => $res,
        ]);
        return [
            'filters' => array_values($res->getData('filters')),
            'state' => array_values($res->getData('state')),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getLayerFilters(Mage_Catalog_Block_Layer_View $layerBlock): array
    {
        /** @var array<string, array<string, mixed>> $filters */
        $filters = [];
        /** @var Mage_Catalog_Block_Layer_Filter_Abstract|Mage_Catalog_Block_Layer_Filter_Price $_filter */
        foreach ($layerBlock->getFilters() as $_filter) {
            if ((int) $_filter->getItemsCount() === 0) {
                continue;
            }

            if ($_filter instanceof Mage_Catalog_Block_Layer_Filter_Price) {
                $filters['price']['title'] = $_filter->getName();
                $filters['price']['param'] = 'price';
                $filters['price']['renderer'] = 'slider';
                $filters['price']['min'] = $this->getLayer()->getProductCollection()->getMinPrice();
                $filters['price']['max'] = $this->getLayer()->getProductCollection()->getMaxPrice();

                continue;
            }

            /** @var Mage_Catalog_Model_Layer_Filter_Item $_item */
            foreach ($_filter->getItems() as $_item) {
                if (!$_item->getCount()) {
                    continue;
                }

                $requestVar = $_item->getFilter()->getRequestVar();
                if ($this->isColorFilter($_filter)) {
                    $filters[$requestVar]['renderer'] = 'color';
                }

                $filters[$requestVar]['title'] = $_filter->getName();
                $filters[$requestVar]['param'] = $requestVar;
                $filters[$requestVar]['options'][] = $this->getFilterOption($_filter, $_item);
            }
        }

        return $filters;
    }

    private function isColorFilter(Mage_Catalog_Block_Layer_Filter_Abstract $filter): bool
    {
        return $filter instanceof Mage_Catalog_Block_Layer_Filter_Attribute
            && (string) $filter->getAttributeModel()->getAttributeCode() === 'color';
    }

    /**
     * @return array<string, mixed>
     */
    private function getFilterOption(
        Mage_Catalog_Block_Layer_Filter_Abstract $filter,
        Mage_Catalog_Model_Layer_Filter_Item $item
    ): array {
        $value = $filter instanceof Mage_Catalog_Block_Layer_Filter_Category
            ? $item->getValue()
            : $item->getOptionId() ?? $item->getValue();
        $count = $filter->shouldDisplayProductCount() ? ' (' . $item->getCount() . ')' : '';

        return [
            'label' => $this->decodeLabel($item->getLabel()) . $count,
            'url' => $item->getUrl(),
            'value' => (string) $value,
            'selected' => $item->getIsSelected() || (string) $value === (string) $filter->getRequestValue(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getLayerState(Mage_Catalog_Block_Layer_View $layerBlock): array
    {
        $stateBlock = $layerBlock->getChild('layer_state');
        $activeFilters = $stateBlock instanceof Mage_Catalog_Block_Layer_State ? $stateBlock->getActiveFilters() : [];
        $state = [];
        /** @var Mage_Catalog_Model_Layer_Filter_Item $item */
        foreach ($activeFilters as $item) {
            $state[$item->getFilter()->getRequestVar()]['title'] = $item->getFilter()->getName();
            $state[$item->getFilter()->getRequestVar()]['param'] = $item->getFilter()->getRequestVar();
            $state[$item->getFilter()->getRequestVar()]['options'][] = [
                'label' => $this->decodeLabel($item->getLabel()),
                'value' => (string) $item->getValue(),
                'url' => $item->getRemoveUrl(),
            ];
        }

        return $state;
    }

    /**
     * Filter labels come HTML-escaped from the layer; the JSON consumer gets plain text.
     *
     * @param mixed $label
     */
    private function decodeLabel($label): string
    {
        // phpcs:ignore Ecg.Security.ForbiddenFunction.Found -- decoding (not encoding) a label for JSON output; no safer core equivalent
        return html_entity_decode((string) $label);
    }


    /**
     * @return array<string, mixed>
     */
    protected function getToolbarResponse(): array
    {
        $response = [];

        $availableOrders = [];
        $toolbar = $this->getToolbar();
        $helper = Mage::helper('catalog');
        foreach ($toolbar->getAvailableOrders() as $k => $order) {
            foreach (['asc', 'desc'] as $dir) {
                $directionTitle = $dir === 'asc' ? 'Ascending' : 'Descending';
                $availableOrders[] = [
                    'url' => $toolbar->getOrderUrl($k, $dir),
                    'label' => $helper->__($order . ' (' . $directionTitle . ')'),
                    'selected' => $toolbar->isOrderCurrent($k) && $toolbar->getCurrentDirection() === $dir,
                    'param' => [
                        $toolbar->getDirectionVarName() => $dir,
                        $toolbar->getOrderVarName() => $k,
                    ],
                ];
            }
        }

        $availableLimits = [];
        foreach ($toolbar->getAvailableLimit() as $_limit) {
            $availableLimits[] = [
                'url' => $toolbar->getLimitUrl($_limit),
                'label' => $_limit,
                'selected' => $toolbar->isLimitCurrent($_limit),
                'param' => [
                    $toolbar->getLimitVarName() => $_limit,
                ],
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
        $response['currentPageNum'] = min($this->getToolbar()->getCurrentPage(), $this->getToolbar()->getLastPageNum());
        $response['isFirstPage'] = $this->getToolbar()->isFirstPage();
        $response['isLastPage'] = $this->getToolbar()->getCurrentPage() >= $this->getToolbar()->getLastPageNum();
        $response['out_of_stock_count'] = $this->_outOfStockProducts;

        $res = new Varien_Object($response);
        Mage::dispatchEvent('ajaxcatalog_toolbar_response', [
            'response' => $res,
        ]);

        return $res->getData();
    }

    /**
     * @return array<int, mixed>
     */
    private function getPages(Mage_Page_Block_Html_Pager $pager)
    {
        $pages = $pager->getPages();


        if ((int) $pages[0] !== 1) {
            array_unshift($pages, 1);
        }

        if ((int) end($pages) !== (int) $pager->getLastPageNum()) {
            $pages[] = $pager->getLastPageNum();
        }

        return $pages;
    }


    /**
     * @return array<string, mixed>
     */
    public function getAjaxResponse()
    {
        return array_merge_recursive([
            'state' => $this->_state->getData(),
        ], parent::getAjaxResponse());
    }


    /**
     * @return Mage_Catalog_Model_Config
     */
    protected function _getCatalogConfig()
    {
        return Mage::getSingleton('catalog/config');
    }


    /**
     * @return Mage_Catalog_Model_Resource_Product_Collection
     */
    public function getProductCollection()
    {
        return $this->_productCollection;
    }
}
