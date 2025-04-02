<?php

namespace Flagship\Shipping\Plugin\Adminhtml\Order\View;

use Magento\Sales\Block\Adminhtml\Order\View;
use Magento\Framework\View\Element\UiComponent\Context;
use Magento\Framework\UrlInterface;
use Flagship\Shipping\Model\Configuration;
use Flagship\Shipping\Service\ApiService;

class FlagshipButton
{
    const CONFIRMED_STATUS = [ 'dispatched', 'manifested' ];
    public function __construct(
        private UrlInterface $urlBuilder,
        private Configuration $configuration,
        private ApiService $apiService
    )
    {}

    public function beforeGetLayout(View $subject)
    {
        $order = $subject->getOrder();
        $orderId = $order->getId();

        if(!$order->hasShipments()){
            $subject->addButton(
                'send_to_flagship',
                [
                    'label' => __('Send To FlagShip &#8618;'),
                    'class' => __('action action-secondary scalable'),
                    'id' => 'send_to_flagship',
                    'onclick' => sprintf("location.href = '%s';", $subject->getUrl('shipping/prepareShipment', ['order_id' => $orderId]))
                ]
            );
            return $subject;
        }
        
        $shipments = $order->getShipmentsCollection();
        $shipment = $shipments->getFirstItem();
        
        $fsId = $shipment->getData('flagship_shipment_id') ?? 0;
        $fsShipment = $this->getFlagshipShipment($fsId);
        if($fsId > 0 && !in_array($fsShipment['status'], self::CONFIRMED_STATUS)){
            $url = $this->configuration->getUrl()."/shipping/$fsId/convert";
            $subject->addButton(
                'confirm_shipment',
                [
                      'label' => __('Confirm FlagShip Shipment'),
                      'class' => __('action-default scalable action-secondary'),
                      'id'  => 'confirm_flagship_shipment',
                      'onclick' => "window.open('$url', '_blank')"
                  ]
            );
            return $subject;
        }

        //if shipment is confirmed, update carrier in shipment and button text
        
        if(in_array($fsShipment['status'], self::CONFIRMED_STATUS)) {
            $url = $this->configuration->getUrl()."/shipping/$fsId/overview";
            $subject->addButton(
                'shipment_id',
                [
                      'label' => __('View FlagShip Shipment'),
                      'class' => __('action-default scalable action-secondary'),
                      'id'  => "flagship_shipment_$fsId",
                      'onclick' => "window.open('$url', '_blank')"
                  ]
            );

        $tracks = $shipment->getAllTracks();
        $trackingNumber = $fsShipment['tracking_number'];
        foreach ($tracks as $track) {
            $title = strcasecmp($fsShipment['service']['courier_name'], 'fedex') == 0 ? 'FedEx '.$fsShipment['service']['courier_desc'] : $fsShipment['service']['courier_desc'];
            $track->setTitle($title);
            $track->setTrackNumber($trackingNumber);
            $track->setCarrierCode('flagship');
            $track->setDescription('foobar!!!');
            $shipment->save();
        }
        }
        return $subject;
    }

    public function getFlagshipShipment($id) 
    {
        $token = $this->configuration->getToken();
        $response = $this->apiService->sendRequest('/ship/shipments/'.$id,$token, 'GET');
        $shipment = $response['response']['content'];
        return $shipment;
    }
}