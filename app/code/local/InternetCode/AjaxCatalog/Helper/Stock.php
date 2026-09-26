<?php

/**
 * Stock split for the frontend catalog ("Split frontend catalog by stock status").
 *
 * Uses the indexed stock_status flag, not qty: configurable, grouped and bundle rows always
 * have qty 0, and backorderable products are salable without qty.
 */
class InternetCode_AjaxCatalog_Helper_Stock extends Mage_Core_Helper_Abstract
{
    /**
     * Own alias, so joins added by core (stock_status_index) or other modules never clash.
     */
    const TABLE_ALIAS = 'ajaxcatalog_stock';

    /**
     * Number of products in the collection that are not salable, without altering the collection.
     * Call it before addInStockFilter(), which would otherwise be part of the counted select.
     *
     * @param Mage_Catalog_Model_Resource_Product_Collection $collection
     * @param Mage_Core_Model_Website $website
     * @return int
     */
    public function getOutOfStockCount($collection, Mage_Core_Model_Website $website)
    {
        $select = $collection->getSelectCountSql();
        $this->_joinStockStatus($select, $website);
        // products without a stock status row are not salable either
        $select->where(
            'COALESCE(' . self::TABLE_ALIAS . '.stock_status, 0) <> ?',
            Mage_CatalogInventory_Model_Stock_Status::STATUS_IN_STOCK
        );

        return (int) $collection->getConnection()->fetchOne($select);
    }

    /**
     * Restrict the collection to salable products.
     *
     * @param Mage_Catalog_Model_Resource_Product_Collection $collection
     * @param Mage_Core_Model_Website $website
     * @return void
     */
    public function addInStockFilter($collection, Mage_Core_Model_Website $website)
    {
        $select = $collection->getSelect();
        if ($this->_joinStockStatus($select, $website)) {
            $select->where(
                self::TABLE_ALIAS . '.stock_status = ?',
                Mage_CatalogInventory_Model_Stock_Status::STATUS_IN_STOCK
            );
        }
    }

    /**
     * @return bool false when the join was already there
     */
    private function _joinStockStatus(Varien_Db_Select $select, Mage_Core_Model_Website $website)
    {
        if (isset($select->getPart(Zend_Db_Select::FROM)[self::TABLE_ALIAS])) {
            return false;
        }

        $adapter = $select->getAdapter();
        $select->joinLeft(
            [self::TABLE_ALIAS => Mage::getSingleton('core/resource')->getTableName('cataloginventory/stock_status')],
            implode(' AND ', [
                'e.entity_id = ' . self::TABLE_ALIAS . '.product_id',
                $adapter->quoteInto(self::TABLE_ALIAS . '.website_id = ?', (int) $website->getId()),
                $adapter->quoteInto(self::TABLE_ALIAS . '.stock_id = ?', Mage_CatalogInventory_Model_Stock::DEFAULT_STOCK_ID),
            ]),
            []
        );

        return true;
    }
}
