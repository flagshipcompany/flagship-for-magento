<?php

namespace Flagship\Shipping\Controller\Adminhtml\ListBoxes;

class Delete extends \Magento\Backend\App\Action{

    protected $_logger;
    protected $loggingEnabled;
    protected $resource;
    protected $flagship;

    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Flagship\Shipping\Logger\Logger $logger,
        \Flagship\Shipping\Block\Flagship $flagship,
        \Flagship\Shipping\Model\AddBoxes $addBoxes
    ) {

         parent::__construct($context);
         $this->flagship = $flagship;
         $this->addBoxes = $addBoxes;
         $this->_logger = $logger;
         $this->loggingEnabled = $this->flagship->getSettings()["log"];
    }

    public function execute(){

        $id = $this->getRequest()->getParam('id');
        $log = $this->flagship->logInfo('Deleting box from database');
        try{
            $box = $this->addBoxes->load($id);
            $box->delete();
            $this->flagship->logInfo('Box deleted');
            return $this->_redirect($this->_redirect->getRefererUrl($this->messageManager->addNoticeMessage( __('Box deleted'))));
        } catch(\Exception $e){
            $this->flagship->logError($e->getMessage());
            $this->messageManager->addErrorMessage($e->getMessage());
        }
    }
}
