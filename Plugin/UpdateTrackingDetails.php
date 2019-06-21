<?php

namespace Flagship\Shipping\Plugin;

class UpdateTrackingDetails{
    public function __construct(
        \Flagship\Shipping\Plugin\HideCreateShippingLabel $tracking,
        \Flagship\Shipping\Plugin\SendToFlagshipButton $sendToFlagshipButton,
        \Flagship\Shipping\Controller\Adminhtml\PrepareShipment\Index $prepareShipment,
        \Magento\Framework\Module\Manager $moduleManager,
        \Magento\Sales\Model\Order\ShipmentRepository $shipmentRepository,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ){
        $this->tracking = $tracking;
        $this->prepareShipment = $prepareShipment;
        $this->moduleManager = $moduleManager;
        $this->sendToFlagshipButton = $sendToFlagshipButton;
        $this->shipmentRepository = $shipmentRepository;
        $this->scopeConfig = $scopeConfig;
    }

    public function beforeSetLayout(\Magento\Sales\Block\Adminhtml\Order\View $subject){
        $this->order = $subject->getOrder();
        $shipments = $this->order->getShipmentsCollection();
        $orderSources = $this->prepareShipment->getSourceCodesForOrderItems();

        if( count($shipments) == count($orderSources) ){
            $subject->updateButton('order_ship','class','disabled');
        }
        $keys = array_keys($orderSources);

        if(count($orderSources) == 1 && in_array('default', $keys)){
            return;
        }

        foreach ($shipments as $shipment) {
            $shipment = $this->shipmentRepository->get($shipment->getId());
            $flagshipId = $shipment->getDataByKey('flagship_shipment_id');
            $this->updateTrackingDetails($flagshipId,$shipment);
        }

        if(!$this->scopeConfig->getValue('carriers/flagship/show_button')){
            $this->sendToFlagshipButton->addSendToFlagshipButton($subject);
        }

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
