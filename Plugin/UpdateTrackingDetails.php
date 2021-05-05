<?php

namespace Flagship\Shipping\Plugin;

class UpdateTrackingDetails
{
    public function __construct(
        \Flagship\Shipping\Plugin\HideCreateShippingLabel $tracking,
        \Flagship\Shipping\Plugin\SendToFlagshipButton $sendToFlagshipButton,
        \Flagship\Shipping\Controller\Adminhtml\PrepareShipment\Index $prepareShipment,
        \Magento\Framework\Module\Manager $moduleManager,
        \Magento\Sales\Model\Order\ShipmentRepository $shipmentRepository,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ) {
        $this->tracking = $tracking;
        $this->prepareShipment = $prepareShipment;
        $this->moduleManager = $moduleManager;
        $this->sendToFlagshipButton = $sendToFlagshipButton;
        $this->shipmentRepository = $shipmentRepository;
        $this->scopeConfig = $scopeConfig;
    }

    public function beforeSetLayout(\Magento\Sales\Block\Adminhtml\Order\View $subject)
    {
        $this->order = $subject->getOrder();
        $shipments = $this->order->getShipmentsCollection();
        $orderSources = $this->prepareShipment->getSourceCodesForOrderItems();

        if (!$this->moduleManager->isEnabled('Flagship_Fulfillment')) {
            $this->sendToFlagshipButton->addSendToFlagshipButton($subject);
        }

        if (count($shipments) == count($orderSources)) {
            $subject->updateButton('order_ship', 'class', 'disabled');
        }
        $keys = array_keys($orderSources);

        if (count($orderSources) == 1 && in_array('default', $keys)) {
            return;
        }

        foreach ($shipments as $shipment) {
            $shipment = $this->shipmentRepository->get($shipment->getId());
            $flagshipId = $shipment->getDataByKey('flagship_shipment_id');
            $this->updateTrackingDetails($flagshipId, $shipment);
        }
        return;
    }

    protected function updateTrackingDetails(?int $flagshipId, \Magento\Sales\Model\Order\Shipment $shipment)
    {
        if (is_null($flagshipId)) {
            return null;
        }
        $flagshipShipment = $this->tracking->getFlagshipShipment($flagshipId, $shipment->getOrder());
        $this->updateTrackingForConfirmedShipment($flagshipShipment, $shipment);
    }

    protected function updateTrackingForConfirmedShipment(\Flagship\Shipping\Objects\Shipment $flagshipShipment, \Magento\Sales\Model\Order\Shipment $shipment) : bool
    {
        if ($this->tracking->isShipmentConfirmed($flagshipShipment, $this->order->getId())) {
            $this->tracking->updateShipmentTrackingData($flagshipShipment, $shipment);
            return true;
        }
        return false;
    }
}
