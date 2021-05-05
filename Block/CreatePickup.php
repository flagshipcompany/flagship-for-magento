<?php

namespace Flagship\Shipping\Block;

class CreatePickup extends \Magento\Framework\View\Element\Template
{
    protected $_coreRegistry;

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Flagship\Shipping\Helper\Flagship $flagship,
        array $data=[]
    ) {
        $this->flagship = $flagship;
        parent::__construct($context, $data);
    }

    public function getIframe() : string
    {
        return SMARTSHIP_WEB_URL.'/pickup/schedule';
    }
}
