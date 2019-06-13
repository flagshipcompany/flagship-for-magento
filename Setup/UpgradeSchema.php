<?php

namespace Flagship\Shipping\Setup;

use Magento\Framework\Setup\UpgradeSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

class UpgradeSchema implements UpgradeSchemaInterface{
  public function upgrade(SchemaSetupInterface $setup, ModuleContextInterface $context) : bool
    {
        $setup->startSetup();
        $tableName = $setup->getTable('sales_shipment');
        $columnName = 'flagship_shipment_id';
        if ($setup->getConnection()->tableColumnExists($tableName,$columnName) === false) {
            $setup->getConnection()->addColumn(
                $tableName,
                $columnName,
                [
                    'type' => \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER,
                    'nullable' => true,
                    'unsigned' => true,
                    'default' => null,
                    'comment' => 'Flagship Shipment Id'
                ]
            );
        }
        $setup->endSetup();
        return TRUE;
    }
}
