<?php
namespace Flagship\Shipping\Controller\Adminhtml\PrepareShipment;
use \Flagship\Shipping\Flagship;
use \Flagship\Shipping\Exceptions\PrepareShipmentException;
use \Flagship\Shipping\Exceptions\EditShipmentException;

class Index extends \Magento\Backend\App\Action
  {

    protected $resultPageFactory;
    protected $objectManager;
    protected $orderId;


    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory
    )
    {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
        $this->objectManager = $context->getObjectManager();
    }

    public function execute()
    {   
        $update = is_null($this->getRequest()->getParam('update')) ? 0 : $this->getRequest()->getParam('update');
        $token = $this->getToken();
        $payload = $this->getPayload();

        

        if(is_null($token)){
          $this->messageManager->addErrorMessage(__('Please set API Token'));
          return $this->_redirect($this->_redirect->getRefererUrl());
        }

        $flagship = new Flagship($token,SMARTSHIP_API_URL);
        
        if($update){
            $shipmentId = $this->getRequest()->getParam('shipmentId');
            $this->updateShipment($flagship,$payload,$shipmentId);
            return $this->_redirect($this->_redirect->getRefererUrl());
        }
        $this->prepareShipment($flagship,$payload);
        return $this->_redirect($this->_redirect->getRefererUrl());
    }

    protected function updateShipment(Flagship $flagship, array $payload,int $shipmentId) : \Magento\Framework\Message\Manager {
        try{
            $update = $flagship->editShipmentRequest($payload,$shipmentId);
            $response = $update->execute();
            $id = $response->shipment->id;
            return $this->messageManager->addSuccess(__('FlagShip Shipment Updated : <a target="_blank" href="'.SMARTSHIP_WEB_URL.'/shipping/'.$id.'/convert">'.$id.'</a>'));
        }
        catch(EditShipmentException $e){
            return $this->messageManager->addErrorMessage(__(ucfirst($e->getMessage())));
        }
    }

    protected function prepareShipment(Flagship $flagship, array $payload) : \Magento\Framework\Message\Manager {
      try{
          
          $request = $flagship->prepareShipmentRequest($payload);
          $response = $request->execute();
          $id = $response->shipment->id;
          $this->setFlagshipShipmentId($id);
          return $this->messageManager->addSuccess(__('FlagShip Shipment Prepared : <a target="_blank" href="'.SMARTSHIP_WEB_URL.'/shipping/'.$id.'/convert">'.$id.'</a>'));
        }
      catch(PrepareShipmentException $e){
        return $this->messageManager->addErrorMessage(__(ucfirst($e->getMessage())));
      }
    }

    protected function getWeightUnits() : string {

        $scopeConfig = $this->objectManager->get('Magento\Framework\App\Config\ScopeConfigInterface');
        $weightUnit = $scopeConfig->getValue('general/locale/weight_unit',\Magento\Store\Model\ScopeInterface::SCOPE_STORE);
        return $weightUnit;

    }

    protected function getTotalWeight() : float {
        $order = $this->getOrder();
        $items = $order->getAllItems();
        $weight = 0;
        foreach($items as $item){
            $weight += ($item->getWeight() * $item->getQtyToShip()) ;
        }
        if($weight < 1){
            return 1;
        }
        return $weight;
    }

    protected function getPackageUnits() :  string{
        if($this->getWeightunits() === 'kgs'){
            return 'metric';
        }
        return 'imperial';
    }

    protected function setFlagshipShipmentId(int $id) : int {
        $resource = $this->objectManager->get('Magento\Framework\App\ResourceConnection');
        $connection = $resource->getConnection();
        $tableName = $resource->getTableName('sales_order');

        $connection->update(
            $tableName,
            ["flagship_shipment_id" => $id ],
            "entity_id = ".$this->orderId
        );

        return 0;

    }

    protected function getToken() : ?string {
        $resource = $this->objectManager->get('Magento\Framework\App\ResourceConnection');
        $connection = $resource->getConnection();
        $tableName = $resource->getTableName('flagship_settings');
        $sql = $connection->select()->from(
                ["table" => $tableName]
        );
        $result = $connection->fetchAll($sql);
        if(count($result)>0){
            return $result[0]['token'];
        }
        return NULL;
    }

    protected function getOrder() : \Magento\Sales\Model\Order\Interceptor {
        $this->orderId = $this->getRequest()->getParam('order_id');
        $order = $this->objectManager->create('Magento\Sales\Api\Data\OrderInterface')->load($this->orderId);
        return $order;
    }

    protected function getShippingAddress() : \Magento\Sales\Model\Order\Address{
        $order = $this->getOrder();
        $shippingAddressDetails = $order->getShippingAddress();
        $shippingAddressDetails = is_null($shippingAddressDetails) ? $order->getBillingAddress() : $shippingAddressDetails;
        return $shippingAddressDetails;
    }

    protected function getStoreDetails() : \Magento\Framework\DataObject {
        $storeInfo = $this->objectManager->create('Magento\Store\Model\Information');
        $store = $this->objectManager->create('Magento\Store\Model\Store');
        $storeDetails = $storeInfo->getStoreInformationObject($store);
        return $storeDetails;
    }

    protected function getStateCode(int $regionId) : string {
        $regionFactory = $this->objectManager->get('Magento\Directory\Model\RegionFactory');
        $state = $regionFactory->create()->load($regionId);
        $stateCode = $state->getCode();
        return $stateCode;
    }

    protected function getPayload() : array {

        $storeDetails = $this->getStoreDetails();

        $country = is_null($storeDetails->getCountryId()) ? '' : $storeDetails->getCountryId() ;
        $stateCode = is_null($storeDetails->getRegionId()) ? '' : $storeDetails->getRegionId();
        $state = empty($stateCode) ? $stateCode : $this->getStateCode($stateCode);
        $name  = is_null($storeDetails->getName()) ? '': $storeDetails->getName();

        $from = [
          'name' => $storeDetails->getName(),
          'attn' => $storeDetails->getName(),
          'address' => $storeDetails->getDataBykey('street_line1'),
          'suite' => $storeDetails->getDataBykey('street_line2'),
          'city' => $storeDetails->getCity(),
          'country' => $country,
          'state' => $state,
          'postal_code' => $storeDetails->getDataBykey('postcode'),
          'phone' => $storeDetails->getPhone(),
          'is_commercial' => 'true'
        ];


        $shippingAddress = $this->getShippingAddress();
        $suite = isset($shippingAddress->getStreet()[1]) ? $shippingAddress->getStreet()[1] : NULL ;
        $name = is_null($shippingAddress->getCompany()) ? $shippingAddress->getFirstName() : $shippingAddress->getCompany();
        $to = [
          'is_commercial' => 'false',
          'name' => $name,
          'attn' => $shippingAddress->getFirstName().' '.$shippingAddress->getLastName(),
          'address' => $shippingAddress->getStreet()[0],
          'suite' => $suite,
          'city' => $shippingAddress->getCity(),
          'country' => $shippingAddress->getCountryId(),
          'state' => $this->getStateCode( $shippingAddress->getRegionId() ),
          'postal_code' => $shippingAddress->getPostCode(),
          'phone' => $shippingAddress->getTelephone()
        ];

        $packages = [
          'units' => $this->getPackageUnits(),
          'type' => 'package',
          'items' =>[
            0 =>
            [
            'width' => '1',
            'height' => '1',
            'length' => '1',
            'weight' => $this->getTotalWeight()
          ],
        ],
        ];

        $options = [
          'signature_required' => false,
          'reference' => 'Magento Order# '.$this->orderId
        ];


        $payment = [
          'payer' => 'F'
        ];

        $payload = [
          "from" => $from,
          "to"  => $to,
          "packages"  => $packages,
          "options" => $options,
          "payment" => $payment
        ];

        return $payload;
    }

}
