<?php

namespace Flagship\Shipping\Controller\Adminhtml\ListBoxes;

class Delete extends \Magento\Backend\App\Action{

    protected $_logger;
    protected $loggingEnabled;
    protected $resource;
    protected $flagship;

    public function __construct(
        \Magento\Backend\App\Action\Context $context, \Flagship\Shipping\Logger\Logger $logger
    ) {

         parent::__construct($context);
         $this->objectManager = $context->getObjectManager();
         $this->flagship = $this->objectManager->get("Flagship\Shipping\Block\Flagship");
         $this->_logger = $logger;
         $this->resource = $this->objectManager->get('Magento\Framework\App\ResourceConnection');
         $this->loggingEnabled = $this->flagship->getSettings()["log"];
    }

    public function execute(){
        
        $id = $this->getRequest()->getParam('id');
        $log = $this->flagship->logInfo('Deleting box from database');
        try{
            $box = $this->objectManager->create('Flagship\Shipping\Model\AddBoxes');
            $box->load($id);
            $box->delete();
            $this->flagship->logInfo('Box deleted');
            return $this->_redirect($this->_redirect->getRefererUrl($this->messageManager->addNoticeMessage( __('Box deleted'))));
        } catch(\Exception $e){
            $this->flagship->logError($e->getMessage());
            $this->messageManager->addErrorMessage($e->getMessage());
        }
    }
}
