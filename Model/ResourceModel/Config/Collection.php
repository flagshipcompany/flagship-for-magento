<?php

namespace Flagship\Shipping\Model\ResourceModel\Config;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection{
    public function _construct(){
        $this->_init('Flagship\Shipping\Model\Config','Flagship\Shipping\Model\ResourceModel\Config');
    }
}
