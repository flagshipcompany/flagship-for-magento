<?php

namespace Flagship\Shipping\Model\ResourceModel\SetToken;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection{
  public function _construct(){
    $this->_init('Flagship\Shipping\Model\SetToken','Flagship\Shipping\Model\ResourceModel\SetToken');
  }
}
