<?php

/**
 * Class FireGento_MultiStock_Model_Resource_Stock_Item
 */
class FireGento_MultiStock_Model_Resource_Stock_Item extends Mage_CatalogInventory_Model_Resource_Stock_Item
{
    /**
     * Add join for catalog in stock field to product collection
     *
     * @param Mage_Catalog_Model_Resource_Product_Collection $productCollection
     * @return Mage_CatalogInventory_Model_Resource_Stock_Item
     */
    public function addCatalogInventoryToProductCollection($productCollection)
    {
        $adapter       = $this->_getReadAdapter();
        $isManageStock = (int)Mage::getStoreConfig(Mage_CatalogInventory_Model_Stock_Item::XML_PATH_MANAGE_STOCK);
        $stockExpr     = $adapter->getCheckSql('SUM(cisi.use_config_manage_stock) >= 1', $isManageStock, 'SUM(cisi.manage_stock)');
        $stockExpr     = $adapter->getCheckSql("({$stockExpr} >= 1)", 'SUM(cisi.is_in_stock)', '1');

        $productCollection->joinTable(
            array('cisi' => 'cataloginventory/stock_item'),
            'product_id=entity_id',
            array(
                'is_saleable'        => new Zend_Db_Expr($stockExpr),
                'inventory_in_stock' => new Zend_Db_Expr('SUM(cisi.is_in_stock)')
            ),
            null,
            'left'
        );
        $productCollection->getSelect()->group('e.entity_id');
        return $this;
    }

    /**
     * Loading stock item data by product
     *
     * @param Mage_CatalogInventory_Model_Stock_Item $item
     * @param int                                    $productId
     * @return Mage_CatalogInventory_Model_Resource_Stock_Item
     */
    public function loadByProductIdOnFrontend(Mage_CatalogInventory_Model_Stock_Item $item, $productId)
    {
        $select = $this->_getLoadSelect('product_id', $productId, $item);
        $select->order('is_in_stock DESC');

        $data = $this->_getReadAdapter()->fetchRow($select);
        if ($data) {
            $item->setData($data);
        }
        $this->_afterLoad($item);
        return $this;
    }
}
