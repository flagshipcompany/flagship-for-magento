<?php
namespace Flagship\Shipping\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Config extends AbstractDb
{
    public function _construct()
    {
        $this->_init('flagship_settings', 'id');
    }
}
