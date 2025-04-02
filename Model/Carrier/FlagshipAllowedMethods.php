<?php

namespace Flagship\Shipping\Model\Carrier;

use \Flagship\Shipping\Model\Carrier\FlagshipQuote;

class FlagshipAllowedMethods implements \Magento\Framework\Option\ArrayInterface
{
    protected $allowedMethods;
    
    public function __construct(FlagshipQuote $methods)
    {
        $this->allowedMethods = $methods;
    }

    public function toOptionArray() : array
    {
        return $this->allowedMethods->allowedMethods();
    }
}
