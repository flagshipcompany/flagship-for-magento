<?php

namespace Flagship\Shipping\Plugin\Adminhtml\Order\Shipment\View;

// use Magento\Sales\Block\Adminhtml\Order\Create;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\Backend\Block\Widget\Button\ButtonList;
use Magento\Backend\Block\Widget\Button\Toolbar as ToolbarContext;
use Flagship\Shipping\Model\Configuration;
use Flagship\Shipping\Service\ApiService;
use Magento\Framework\UrlInterface;

class FlagshipButtonPlugin
{
    public const CONFIRMED_STATUS = [ 'dispatched', 'manifested' ];
    public function __construct(
        private UrlInterface $urlBuilder,
        private Configuration $configuration,
        private ApiService $apiService
    ) {
    }

    public function beforePushButtons(
        ToolbarContext $toolbar,
        AbstractBlock $context,
        ButtonList $buttonList
    ): array {

        $nameInLayout = $context->getNameInLayout();
        
        if (!$this->configuration->isEnabled() || 'sales_shipment_create' !== $nameInLayout) {
            return [$context, $buttonList];
        }
        
        $shipment = $context->getShipment();
        $order = $shipment->getOrder();
        $orderId = $order->getId();
        $sourceCode = $shipment->getExtensionAttributes()->getSourceCode();
        
        $url = $context->getUrl('shipping/prepareShipment', ['order_id' => $orderId, 'source_code' => $sourceCode]);
        $buttonList->add(
            'send_to_flagship',
            [
                'label' => __('Send To FlagShip &#8618;'),
                'class' => __('action action-secondary scalable'),
                'id' => 'send_to_flagship',
                'onclick' => "window.flagshipSendShipment('$url');",
            ]
        );
        
        $context->getLayout()->getBlock('head.additional')->append(
            $context->getLayout()->createBlock(\Magento\Framework\View\Element\Template::class)
                ->setTemplate('Flagship_Shipping::send_to_flagship_init.phtml')
        );

        return [$context, $buttonList];
    }

    public function getFlagshipShipment($id)
    {
        $token = $this->configuration->getToken();
        $response = $this->apiService->sendRequest('/ship/shipments/'.$id, $token, 'GET');
        $shipment = $response['response']['content'];
        return $shipment;
    }
}