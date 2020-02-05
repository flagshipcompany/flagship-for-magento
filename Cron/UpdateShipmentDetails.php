<?php

namespace Flagship\Shipping\Cron;
use \Flagship\Shipping\Flagship;

class UpdateShipmentDetails{

    public function __construct(
        \Magento\Sales\Model\Order\Shipment $shipment,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Flagship\Shipping\Plugin\HideCreateShippingLabel $tracking,
        \Flagship\Shipping\Helper\Flagship $flagship
    ){
        $this->shipment = $shipment;
        $this->tracking = $tracking;
        $this->flagship = $flagship;
        $this->scopeConfig = $scopeConfig;
    }

    public function execute(){
        $collection = $this->shipment->getCollection()->addFieldToFilter('flagship_shipment_id',['notnull' => true])->addFieldToFilter('shipping_label',['null' => true ]);

        foreach ($collection as $shipment) {
            $flagshipShipmentId = $shipment->getDataByKey('flagship_shipment_id');
        }
        return;
    }

    protected function isShipmentConfirmed(int $flagshipShipmentId) : int {
        $token = $this->flagship->getSettings()["token"];
        $storeName = $this->scopeConfig->getValue('general/store_information/name') == null ? '' : $this->scopeConfig->getValue('general/store_information/name');

        $flagship = new Flagship($token,SMARTSHIP_API_URL,FLAGSHIP_MODULE,FLAGSHIP_MODULE_VERSION);
        $flagshipShipment = $flagship->getShipmentByIdRequest($flagshipShipmentId)->setStoreName($storeName)->execute();

        if(strcasecmp($flagshipShipment->getStatus(),'Prequoted') != 0){
            $this->getTrackingStatus($flagshipShipmentId);
        }
        return 0;
    }

    protected function getTrackingStatus(int $flagshipShipmentId) : int {
        $this->tracking->updateShipmentTrackingData($flagshipShipment);
        return 0;
    }
}
