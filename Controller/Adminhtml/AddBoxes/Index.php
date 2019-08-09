<?php

namespace Flagship\Shipping\Controller\Adminhtml\AddBoxes;

class Index extends \Magento\Backend\App\Action{

    protected $_logger;
    protected $loggingEnabled;
    protected $flagship;

    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        \Flagship\Shipping\Block\Flagship $flagship,
        \Flagship\Shipping\Logger\Logger $logger,
        \Flagship\Shipping\Model\AddBoxesFactory $addBoxesFactory
    ) {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
        $this->flagship = $flagship;
        $this->addBoxesFactory = $addBoxesFactory;
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

        if(isset($model) && !$this->validateBox($length,$width,$height)){
            return $this->_redirect($this->getUrl('shipping/listboxes/Index'),$this->messageManager->addErrorMessage("Box is too big. Please add a smaller box"));
        }

        if(isset($model) && $this->createBox($model,$length,$width,$height,$weight,$maxWeight) ){
            return $this->_redirect($this->getUrl('shipping/listboxes/Index'),$this->messageManager->addSuccessMessage("Success!Box added"));
        }
        return $this->resultPageFactory->create();
    }

    protected function validateBox(float $length, float $width, float $height){
        $total = $length + 2*$width + 2*$height;
        if($total < 165){
            return TRUE;
        }
        return FALSE;
    }

    protected function createBox(string $model,string $length,string $width,string $height,string $weight,string $maxWeight) : bool {

        $box = $this->addBoxesFactory->create();

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
