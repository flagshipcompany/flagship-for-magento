<?php

namespace Flagship\Shipping\Plugin;

class UpdateTrackingDetails{
    public function __construct(\Flagship\Shipping\Plugin\HideCreateShippingLabel $tracking){
        $this->tracking = $tracking;
    }

    public function beforeSetLayout(\Magento\Sales\Block\Adminhtml\Order\View $subject){
        $this->order = $subject->getOrder();

        if(!is_null($this->order->getDataByKey('flagship_shipment_id'))){

            $flagshipId = $this->order->getDataByKey('flagship_shipment_id');
            $flagshipShipment = $this->tracking->getFlagshipShipment($flagshipId);
            $this->updateTrackingForConfirmedShipment($flagshipShipment);
        }
        return;
    }

    protected function updateTrackingForConfirmedShipment($flagshipShipment) : bool {
        if($this->tracking->isShipmentConfirmed($flagshipShipment,$this->order->getId())){
            $this->tracking->updateShipmentTrackingData($flagshipShipment,$this->getOrderShipment());
            return TRUE;
        }
        return FALSE;
    }

    protected function getOrderShipment() : \Magento\Sales\Model\Order\Shipment {
        $shipments = $this->order->getShipmentsCollection();
        foreach ($shipments as $shipment) {
            $orderShipment = $shipment;
        }
        return $orderShipment;
    }
}
