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
class FireGento_MultiStock_Model_Stock extends Mage_CatalogInventory_Model_Stock
{
    /**
     * Get the id.
     *
     * @return null|int
     */
    public function getId()
    {
        return $this->getData('stock_id');
    }

    /**
     * Get the name.
     *
     * @return mixed
     */
    public function getName()
    {
        return $this->getData('stock_name');
    }

    /**
     * Delete a stock.
     *
     * @return $this|Mage_Core_Model_Abstract
     */
    public function delete()
    {
        if (intval($this->getId()) == self::DEFAULT_STOCK_ID) {
            Mage::logException(new Exception('default or unknown stock can\'t be deleted'));
        } else {
            parent::delete();
        }

        return $this;
    }

    /**
     * Prepare array($productId=>$qty) based on array($productId => array('qty'=>$qty, 'item'=>$stockItem))
     *
     * @param array $items
     * @return array
     */
    protected function _prepareProductQtys($items)
    {
        Mage::register('stock_items', $items, true);
        $qtys = array();
        foreach ($items as $productId => $item) {
            if (empty($item['item'])) {
                $stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($productId);
            } else {
                $stockItem = $item['item'];
            }
            $canSubtractQty = $stockItem->getId() && $stockItem->canSubtractQty();
            if ($canSubtractQty && Mage::helper('catalogInventory')->isQty($stockItem->getTypeId())) {
                $qtys[$productId] = $item['qty'];
            }
        }
        return $qtys;
    }

    /**
     * Add stock item objects to products
     *
     * @param   Varien_Data_Collection $productCollection
     * @return  Mage_CatalogInventory_Model_Stock
     */
    public function addItemsToProducts($productCollection)
    {
        $items      = $this->getItemCollection()
            ->addProductsFilter($productCollection)
            ->joinStockStatus($productCollection->getStoreId())
            ->load();
        $stockItems = array();
        /** @var FireGento_MultiStock_Model_Stock_Item $item */
        foreach ($items as $item) {
            if (!isset($stockItems[$item->getProductId()])) {
                $stockItems[$item->getProductId()] = $item;
            } elseif ($item->getStockId() == self::DEFAULT_STOCK_ID && $item->getIsInStock()) {
                $stockItems[$item->getProductId()] = $item;
            } elseif (!$stockItems[$item->getProductId()]->getIsInStock() && $item->getIsInStock()) {
                $stockItems[$item->getProductId()] = $item;
            }
        }
        foreach ($productCollection as $product) {
            if (isset($stockItems[$product->getId()])) {
                $stockItems[$product->getId()]->assignProduct($product);
            }
        }
        return $this;
    }

    /**
     * Subtract product qtys from stock.
     * Return array of items that require full save
     *
     * @param array $items
     * @return array
     */
    public function registerProductsSale($items)
    {
        $qtys = $this->_prepareProductQtys($items);
        $item = Mage::getModel('cataloginventory/stock_item');
        $this->_getResource()->beginTransaction();
        $stockInfo     = $this->_getResource()->getProductsStock($this, array_keys($qtys), true);
        $fullSaveItems = array();
        foreach ($stockInfo as $itemInfo) {
            $item->setData($itemInfo);
            if (!isset($qtys[$item->getProductId()])) {
                continue;
            }
            if (!$item->checkQty($qtys[$item->getProductId()])) {
                $this->_getResource()->commit();
                Mage::throwException(Mage::helper('cataloginventory')->__('Not all products are available in the requested quantity'));
            }
            $item->subtractQty($qtys[$item->getProductId()]);
            if (!$item->verifyStock() || $item->verifyNotification()) {
                $fullSaveItems[] = clone $item;
            }
        }
        $this->_getResource()->correctItemsQty($this, $qtys, '-');
        $this->_getResource()->commit();
        return $fullSaveItems;
    }
}
