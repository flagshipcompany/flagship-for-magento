<?php
namespace Flagship\Shipping\Controller\Adminhtml\ShowLog;

class Index extends \Magento\Backend\App\Action{

    protected $_logger;
    protected $loggingEnabled;

    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        \Flagship\Shipping\Logger\Logger $logger
    ){
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
        $this->_logger = $logger;
    }

    public function execute(){
        return $this->resultPageFactory->create();
    }

}
