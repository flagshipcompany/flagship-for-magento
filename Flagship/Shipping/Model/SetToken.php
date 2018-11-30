<?php
namespace Flagship\Shipping\Model;

use Magento\Cron\Exception;
use Magento\Framework\Model\AbstractModel;

class SetToken extends AbstractModel{
  protected $_dateTime;

  protected function _construct(){
    $this->_init(\Flagship\Shipping\Model\ResourceModel\SetToken::class);
  }
}
