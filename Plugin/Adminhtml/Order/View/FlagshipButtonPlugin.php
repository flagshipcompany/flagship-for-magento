<?php

namespace Flagship\Shipping\Plugin\Adminhtml\Order\View;

use Magento\Sales\Block\Adminhtml\Order\Create;
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
        
        $order = false;
        $nameInLayout = $context->getNameInLayout();
        if ('sales_order_edit' == $nameInLayout) {
            $order = $context->getOrder();
        }

        if ($order) {
        
            $orderId = $order->getId();

            if (!$order->hasShipments()) {

                $buttonList->add(
                    'send_to_flagship',
                    [
                        'label' => __('Send To FlagShip &#8618;'),
                        'class' => __('action action-secondary scalable'),
                        'id' => 'send_to_flagship',
                        'onclick' => sprintf("location.href = '%s';", $context->getUrl('shipping/prepareShipment', ['order_id' => $orderId]))
                    ]
                );
                return [$context, $buttonList];
            }

            $shipments = $order->getShipmentsCollection();
            $shipment = $shipments->getFirstItem();

            $fsId = $shipment->getData('flagship_shipment_id') ?? 0;
            $fsShipment = $this->getFlagshipShipment($fsId);
            if ($fsId > 0 && !in_array($fsShipment['status'], self::CONFIRMED_STATUS)) {
                $url = $this->configuration->getUrl()."/shipping/$fsId/convert";
                $buttonList->add(
                    'confirm_shipment',
                    [
                        'label' => __('Confirm FlagShip Shipment'),
                        'class' => __('action-default scalable action-secondary'),
                        'id'  => 'confirm_flagship_shipment',
                        'onclick' => "window.open('$url', '_blank')"
                    ]
                );
                return [$context, $buttonList];
            }

            
            // if shipment is confirmed, show view FS shipment button
            if (in_array($fsShipment['status'], self::CONFIRMED_STATUS)) {
                $url = $this->configuration->getUrl()."/shipping/$fsId/overview";
                $buttonList->add(
                    'shipment_id',
                    [
                        'label' => __('View FlagShip Shipment'),
                        'class' => __('action-default scalable action-secondary'),
                        'id'  => "flagship_shipment_$fsId",
                        'onclick' => "window.open('$url', '_blank')"
                    ]
                );
            }
        }
        
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