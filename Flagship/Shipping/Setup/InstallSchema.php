<?php
/**
* Copyright © 2016 Magento. All rights reserved.
* See COPYING.txt for license details.
*/

namespace Flagship\Shipping\Setup;
use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

/**
 * @codeCoverageIgnore
 */
class InstallSchema implements InstallSchemaInterface
{
    /**
    * {@inheritdoc}
    * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
    */
    public function install(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
          /**
          * Create table 'flagship_settings'
          */
          $setup->startSetup();
          $table = $setup->getConnection()
              ->newTable($setup->getTable('flagship_settings'))
              ->addColumn(
                  'id',
                  \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER,
                  null,
                  ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true],
                  'Greeting ID'
              )
              ->addColumn(
                  'token',
                  \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                  60,
                  ['nullable' => false, 'default' => ''],
                    'Message'
              )->setComment("Flagship Settings table");
          $setup->getConnection()->createTable($table);
          $setup->endSetup();
      }
}
