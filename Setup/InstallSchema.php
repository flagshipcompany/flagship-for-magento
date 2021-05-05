<?php

namespace Flagship\Shipping\Setup;

use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

class InstallSchema implements InstallSchemaInterface
{
    public function install(SchemaSetupInterface $setup, ModuleContextInterface $context) : bool
    {
        $setup->startSetup();
        $table = $setup->getConnection()
                ->newTable($setup->getTable('flagship_settings'))
                ->addColumn(
                    'id',
                    \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER,
                    null,
                    ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true],
                    'Setting ID'
                )
                ->addColumn(
                    'name',
                    \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                    30,
                    ['nullable' => false, 'default' => ''],
                    'Setting Name'
                )
                ->addColumn(
                    'value',
                    \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                    60,
                    ['nullable' => false, 'default' => ''],
                    'Setting Value'
                )->setComment("Flagship Settings table");
        
        $setup->getConnection()->createTable($table);

        $table = $setup->getConnection()
                ->newTable($setup->getTable('flagship_boxes'))
                ->addColumn(
                    'id',
                    \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER,
                    null,
                    ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true],
                    'ID'
                )
                ->addColumn(
                    'box_model',
                    \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                    30,
                    ['nullable' => false, 'default' => ''],
                    'Box model'
                )
                ->addColumn(
                    'length',
                    \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                    10,
                    ['nullable' => false, 'default' => ''],
                    'Box length'
                )
                ->addColumn(
                    'width',
                    \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                    10,
                    ['nullable' => false, 'default' => ''],
                    'Box width'
                )
                ->addColumn(
                    'height',
                    \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                    10,
                    ['nullable' => false, 'default' => ''],
                    'Box height'
                )
                ->addColumn(
                    'weight',
                    \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                    10,
                    ['nullable' => false, 'default' => ''],
                    'Box weight'
                )
                ->addColumn(
                    'max_weight',
                    \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                    10,
                    ['nullable' => false, 'default' => ''],
                    'Box Max Weight'
                )->setComment("Flagship Boxes table")
                ->addColumn(
                    'price',
                    \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                    10,
                    ['nullable' => false, 'default' => '0.00'],
                    'Box Price'
                )->setComment("Flagship Boxes table");
        $setup->getConnection()->createTable($table);
        $setup->endSetup();
        return true;
    }
}
