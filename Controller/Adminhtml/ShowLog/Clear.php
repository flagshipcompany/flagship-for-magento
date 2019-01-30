<?php
 
namespace Flagship\Shipping\Controller\Adminhtml\ShowLog;
 
use Magento\Framework\App\Action\Context;
 
class Clear extends \Magento\Framework\App\Action\Action
{
    public function __construct(Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory
    ) {
        $this->resultPageFactory = $resultPageFactory;
        parent::__construct($context);
    }

    public function execute(){
        $fileName = BP.'/var/log/flagship.log';

        $fileHandle = fopen($fileName,"r+");
        ftruncate($fileHandle,0);
        rewind($fileHandle);
        fclose($fileHandle);
        return $this->_redirect($this->_redirect->getRefererUrl());
    }
}