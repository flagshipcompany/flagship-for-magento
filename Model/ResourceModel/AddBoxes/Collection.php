<?php

namespace Flagship\Shipping\Model\ResourceModel\AddBoxes;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection{
    public function _construct(){
        $this->_init('Flagship\Shipping\Model\AddBoxes','Flagship\Shipping\Model\ResourceModel\AddBoxes');
    }
}
