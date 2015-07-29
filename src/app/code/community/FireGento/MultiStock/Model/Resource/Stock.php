<?php
/**
 * This file is part of a FireGento e.V. module.
 *
 * This FireGento e.V. module is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 3 as
 * published by the Free Software Foundation.
 *
 * This script is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * PHP version 5
 *
 * @category  FireGento
 * @package   FireGento_MultiStock
 * @author    FireGento Team <team@firegento.com>
 * @copyright 2014 FireGento Team (http://www.firegento.com)
 * @license   http://opensource.org/licenses/gpl-3.0 GNU General Public License, version 3 (GPLv3)
 */

/**
 * Helper Class
 *
 * @category FireGento
 * @package  FireGento_MultiStock
 * @author   FireGento Team <team@firegento.com>
 */
class FireGento_MultiStock_Model_Resource_Stock extends Mage_CatalogInventory_Model_Resource_Stock
{
    /**
     * @return null|array
     */
    protected function _getStockItems()
    {
        return Mage::registry('stock_items');
    }

    /**
     * add join to select only in stock products
     *
     * @param  Mage_Catalog_Model_Resource_Product_Link_Product_Collection $collection collection to add filter
     *
     * @return Mage_CatalogInventory_Model_Resource_Stock
     */
    public function setInStockFilterToCollection($collection)
    {
        $this->_initConfig();
        $manageStock = Mage::getStoreConfig(Mage_CatalogInventory_Model_Stock_Item::XML_PATH_MANAGE_STOCK);
        $cond        = array('{{table}}.use_config_manage_stock = 0 AND {{table}}.manage_stock=1 AND {{table}}.is_in_stock=1',
            '{{table}}.use_config_manage_stock = 0 AND {{table}}.manage_stock=0',);

        if ($manageStock) {
            $cond[] = '{{table}}.use_config_manage_stock = 1 AND {{table}}.is_in_stock=1';
        } else {
            $cond[] = '{{table}}.use_config_manage_stock = 1';
        }

        $collection->joinField(
            'inventory_in_stock', 'cataloginventory/stock_item', 'is_in_stock', 'product_id=entity_id', join(
                ' AND ', array('(' . join(') OR (', $cond) . ')',
                    $this->getReadConnection()->quoteInto('stock_id = ? ', $this->_stock->getId()))
            )
        );

        return $this;
    }

    /**
     * Get stock items data for requested products
     *
     * @param Mage_CatalogInventory_Model_Stock $stock
     * @param array                             $productIds
     * @param bool                              $lockRows
     * @return array
     */
    public function getProductsStock($stock, $productIds, $lockRows = false)
    {
        if (empty($productIds)) {
            return array();
        }
        $itemTable    = $this->getTable('cataloginventory/stock_item');
        $productTable = $this->getTable('catalog/product');
        $select       = $this->_getWriteAdapter()->select()
            ->from(array('si' => $itemTable))
            ->join(array('p' => $productTable), 'p.entity_id=si.product_id', array('type_id'));
        if ($stockItems = $this->_getStockItems()) {
            foreach ($stockItems as $productId => $qty) {
                if (isset($qty['item'])) {
                    /** @var FireGento_MultiStock_Model_Stock_Item $stockItem */
                    $stockItem = $qty['item'];
                    $select->orWhere('item_id = ?', $stockItem->getId());
                } else {
                    Mage::throwException(Mage::helper('core')->__('Wrong product (%s) stock item', $productId));
                }
            }
        } else { //if 'stock_items' registry is empty fallback to standard magento
            $select->where('stock_id=?', $stock->getId())
                ->where('product_id IN(?)', $productIds);
        }
        $select->forUpdate($lockRows);
        return $this->_getWriteAdapter()->fetchAll($select);
    }

    /**
     * Correct particular stock products qty based on operator
     *
     * @param Mage_CatalogInventory_Model_Stock $stock
     * @param array                             $productQtys
     * @param string                            $operator +/-
     * @return Mage_CatalogInventory_Model_Resource_Stock
     */
    public function correctItemsQty($stock, $productQtys, $operator = '-')
    {
        if (empty($productQtys)) {
            return $this;
        }

        $adapter    = $this->_getWriteAdapter();
        $conditions = array();
        if ($stockItems = $this->_getStockItems()) {
            foreach ($stockItems as $productId => $qty) {
                if (isset($qty['item'])) {
                    /** @var FireGento_MultiStock_Model_Stock_Item $stockItem */
                    $stockItem         = $qty['item'];
                    $case              = $adapter->quoteInto('?', $stockItem->getId());
                    $result            = $adapter->quoteInto("qty{$operator}?", $qty['qty']);
                    $conditions[$case] = $result;
                }
            }
            $value = $adapter->getCaseSql('item_id', $conditions, 'qty');
        } else { //if 'stock_items' registry is empty fallback to standard magento
            foreach ($productQtys as $productId => $qty) {
                $case              = $adapter->quoteInto('?', $productId);
                $result            = $adapter->quoteInto("qty{$operator}?", $qty);
                $conditions[$case] = $result;
            }
            $value = $adapter->getCaseSql('product_id', $conditions, 'qty');
        }

        $where = array('product_id IN (?)' => array_keys($productQtys));

        $adapter->beginTransaction();
        $adapter->update($this->getTable('cataloginventory/stock_item'), array('qty' => $value), $where);
        $adapter->commit();

        return $this;
    }
}
