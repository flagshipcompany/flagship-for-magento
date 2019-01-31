<?php

namespace Flagship\Shipping\Controller\Adminhtml\AddBoxes;

class Index extends \Magento\Backend\App\Action{

    protected $_logger;
    protected $loggingEnabled;
    protected $flagship;

    public function __construct(\Magento\Backend\App\Action\Context $context,\Magento\Framework\View\Result\PageFactory $resultPageFactory, \Flagship\Shipping\Logger\Logger $logger) {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
        $this->objectManager = $context->getObjectManager();
        $this->flagship = $this->objectManager->get("Flagship\Shipping\Block\Flagship");
        $this->_logger = $logger;
        $this->loggingEnabled = $this->flagship->getSettings()["log"];

    }

    public function execute(){

        $model = $this->getRequest()->getParam('model');
        $length = $this->getRequest()->getParam('length');
        $width = $this->getRequest()->getParam('width');
        $height = $this->getRequest()->getParam('height');
        $weight = $this->getRequest()->getParam('weight');
        $maxWeight = $this->getRequest()->getParam('maxWeight');

        if(isset($model)){
            if($this->createBox($model,$length,$width,$height,$weight,$maxWeight)){

                return $this->_redirect($this->getUrl('shipping/listboxes/Index'),$this->messageManager->addSuccessMessage("Success!Box added"));
            }
        }
        return $this->resultPageFactory->create();
    }

    protected function createBox(string $model,string $length,string $width,string $height,string $weight,string $maxWeight) : bool {

        $box = $this->objectManager->create('Flagship\Shipping\Model\AddBoxes');

        $box->setBoxModel($model);
        $box->setLength($length);
        $box->setWidth($width);
        $box->setHeight($height);
        $box->setWeight($weight);
        $box->setMaxWeight($maxWeight);
         try{
            $box->save();
            $this->flagship->logInfo('Box '.$model.' added to database');
            return TRUE;
        } catch(\Exception $e){
            $this->flagship->logError(__($e->getMessage()));
            $this->messageManager->addErrorMessage(__($e->getMessage()));
        }

    }
}
