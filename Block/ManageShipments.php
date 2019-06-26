<?php

namespace Flagship\Shipping\Block;

class ManageShipments extends \Magento\Framework\View\Element\Template{

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Flagship\Shipping\Block\Flagship $flagship,
        array $data=[]){
        $this->flagship = $flagship;
        parent::__construct($context,$data);

    }

    public function getIframe() : string  {
        return SMARTSHIP_WEB_URL.'/shipping/manage';
    }

}
