<?php
namespace Flagship\Shipping\Block;

class ConvertShipment extends \Magento\Framework\View\Element\Template{

    protected $_coreRegistry;
   
    public function __construct(\Magento\Framework\View\Element\Template\Context $context, \Magento\Framework\Registry $registry,
        array $data=[]){
        $this->_coreRegistry = $registry;    
        parent::__construct($context,$data);
    }

    public function getIframe() : string {
        return $this->_coreRegistry->registry('iframeUrl');
    }

}
