<?php
namespace Flagship\Shipping\Controller\Adminhtml\ConvertShipment;

class Index extends \Magento\Backend\App\Action
{
    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Framework\Registry $_coreRegistry,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        \Flagship\Shipping\Helper\Flagship $flagship
    ) {
        parent::__construct($context);
        $this->context = $context;
        $this->flagship = $flagship;
        $this->resultPageFactory = $resultPageFactory;
        $this->_coreRegistry = $_coreRegistry;
    }

    public function execute()
    {
        $resultPage = $this->resultPageFactory->create();
        $shipmentId = $this->getRequest()->getParam('shipmentId');

        $iframeUrl = SMARTSHIP_WEB_URL.'/shipping/'.$shipmentId.'/convert';
        $this->_coreRegistry->register('iframeUrl', $iframeUrl);

        $resultPage->getLayout()->getBlock('Flagship\Shipping\Block\ConvertShipment');

        return $resultPage;
    }
}
