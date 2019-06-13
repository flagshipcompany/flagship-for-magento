<?php

namespace Flagship\Shipping\Plugin;

class UpdateTrackingDetails{
    public function __construct(
        \Flagship\Shipping\Plugin\HideCreateShippingLabel $tracking,
        \Flagship\Shipping\Controller\Adminhtml\PrepareShipment\Index $prepareShipment
    ){
        $this->tracking = $tracking;
        $this->prepareShipment = $prepareShipment;
    }

    public function beforeSetLayout(\Magento\Sales\Block\Adminhtml\Order\View $subject){
        $this->order = $subject->getOrder();
        $shipments = $this->order->getShipmentsCollection();
        $orderSources = $this->prepareShipment->getSourceCodesForOrderItems();

        if( count($shipments) == count($orderSources) ){
            $subject->updateButton('order_ship','class','disabled');
        }

        foreach ($shipments as $shipment) {
            $flagshipId = $shipment->getDataByKey('flagship_shipment_id');
            $this->updateTrackingDetails($flagshipId,$shipment);
        }
        $keys = array_keys($orderSources);

        if(count($orderSources) == 1 && in_array('default', $keys)){
            return;
        }

        $subject->addButton(
                'send_to_flagship',
                [
                    'label' => __('Send To FlagShip &#8618;'),
                    'class' => __('action action-secondary scalable'),
                    'id' => 'send_to_flagship',
                    'onclick' => sprintf("location.href = '%s';", $subject->getUrl('shipping/prepareShipment',['order_id' => $this->order->getId()]))
                ]
            );
        return;
    }

    protected function updateTrackingDetails($flagshipId,$shipment){
        if(is_null($flagshipId)){
            return NULL;
        }
        $flagshipShipment = $this->tracking->getFlagshipShipment($flagshipId);
        $this->updateTrackingForConfirmedShipment($flagshipShipment,$shipment);
    }

    protected function updateTrackingForConfirmedShipment($flagshipShipment,$shipment) : bool {
        if($this->tracking->isShipmentConfirmed($flagshipShipment,$this->order->getId())){
            $this->tracking->updateShipmentTrackingData($flagshipShipment,$shipment);
            return TRUE;
        }
        return FALSE;
    }

}
