<?php
namespace Flagship\Shipping\Controller\Adminhtml\PrepareShipment;
use \Flagship\Shipping\Flagship;
use \Flagship\Shipping\Exceptions\PrepareShipmentException;
use \Flagship\Shipping\Exceptions\EditShipmentException;
use \Flagship\Shipping\Block\DisplayPacking;

class Index extends \Magento\Backend\App\Action
  {

    protected $objectManager;
    protected $orderId;
    protected $orderRepository;
    protected $scopeConfig;
    protected $regionfactory;
    protected $convertOrder;
    protected $trackFactory;
    protected $_logger;
    protected $loggingEnabled;
    protected $displayPackings;
    protected $flagship;

    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Sales\Model\OrderRepository $orderRepository,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Directory\Model\RegionFactory $regionFactory,
        \Magento\Sales\Model\Convert\Order $convertOrder,
        \Magento\Sales\Model\Order\Shipment\TrackFactory $trackFactory,
        \Flagship\Shipping\Logger\Logger $logger,
        \Magento\InventorySalesAdminUi\Model\GetSalableQuantityDataBySku $getSalableQuantityDataBySku,
        \Magento\Inventory\Model\GetSourceCodesBySkus $getSourceCodesBySkus,
        \Magento\InventorySourceDeductionApi\Model\GetSourceItemBySourceCodeAndSku $getSourceItemBySourceCodeAndSku
    )
    {
        $this->objectManager = $context->getObjectManager();
        $this->orderRepository = $orderRepository;
        $this->scopeConfig = $scopeConfig;
        $this->regionFactory = $regionFactory;
        $this->convertOrder = $convertOrder;
        $this->trackFactory = $trackFactory;
        $this->_logger = $logger;
        $this->displayPackings = $this->objectManager->get("Flagship\Shipping\Block\DisplayPacking");
        $this->flagship = $this->objectManager->get("Flagship\Shipping\Block\Flagship");
        $this->loggingEnabled = $this->flagship->getSettings()["log"];
        $this->getSalableQuantityDataBySku = $getSalableQuantityDataBySku;
        $this->getSourceCodesBySkus = $getSourceCodesBySkus;
        $this->getSourceItemBySourceCodeAndSku = $getSourceItemBySourceCodeAndSku;
        parent::__construct($context);
    }

    public function execute(){

        $update = is_null($this->getRequest()->getParam('update')) ? 0 : $this->getRequest()->getParam('update');
        $token = $this->getToken();
        $payload = $this->getPayload();
        $orderId = $this->getRequest()->getParam('order_id');

        $flagship = new Flagship($token,SMARTSHIP_API_URL,FLAGSHIP_MODULE,FLAGSHIP_MODULE_VERSION);

        if($update){
            $shipmentId = $this->getRequest()->getParam('shipmentId');
            $this->flagship->logInfo('Updating FlagShip Shipment#'.$shipmentId.' for Order#'.$orderId);
            $this->updateShipment($flagship,$payload,$shipmentId);
            return $this->_redirect($this->_redirect->getRefererUrl());
        }
        $this->flagship->logInfo('Preparing FlagShip Shipment for Order#'.$orderId);
        $this->prepareShipment($flagship,$payload);
        return $this->_redirect($this->getUrl('sales/order/view',['order_id' => $orderId]));
    }

    public function getToken() : ?string {
        return $this->flagship->getSettings()["token"];
    }

    public function getOrder() : \Magento\Sales\Model\Order {
        $this->orderId = $this->getRequest()->getParam('order_id');
        $order = $this->orderRepository->get($this->orderId);
        return $order;
    }

    public function getSourceCode(){
        $items = $this->getOrder()->getAllItems();
        $skus = [];
        foreach ($items as $item) {
            $skus[] = strcasecmp($item->getProductType(),'configurable') == 0 ? $item->getProductOptions()["simple_sku"] : $item->getProduct()->getSku();
        }
        
        return $this->getSourceCodesBySkus->execute($skus)[0];
    }

    public function getPayload() : array {

        $store = $this->getStore();

        $country = is_null($store->getConfig('general/store_information/country_id')) ? '' : $store->getConfig('general/store_information/country_id') ;
        $stateCode = is_null($store->getConfig('general/store_information/region_id')) ? '' : $store->getConfig('general/store_information/region_id');
        $state = empty($stateCode) ? $stateCode : $this->getStateCode($stateCode);
        $name  = is_null($store->getConfig('general/store_information/name')) ? '': $store->getConfig('general/store_information/name');
        $address = is_null($store->getConfig('general/store_information/street_line1')) ? '': $store->getConfig('general/store_information/street_line1');
        $suite = is_null($store->getConfig('general/store_information/street_line2')) ? '': $store->getConfig('general/store_information/street_line2');
        $city = is_null($store->getConfig('general/store_information/city')) ? '': $store->getConfig('general/store_information/city');
        $postcode = is_null($store->getConfig('general/store_information/postcode')) ? '': $store->getConfig('general/store_information/postcode');
        $phone = is_null($store->getConfig('general/store_information/phone')) ? '': $store->getConfig('general/store_information/phone');

        $from = [
          'name' => $name,
          'attn' => $name,
          'address' => $address,
          'suite' => $suite,
          'city' => $city,
          'country' => $country,
          'state' => $state,
          'postal_code' => $postcode,
          'phone' => $phone,
          'is_commercial' => 'true'
        ];


        $shippingAddress = $this->getShippingAddress();
        $suite = isset($shippingAddress->getStreet()[1]) ? $shippingAddress->getStreet()[1] : NULL ;
        $name = is_null($shippingAddress->getCompany()) ? $shippingAddress->getFirstName() : $shippingAddress->getCompany();
        $attn = strlen($shippingAddress->getFirstName().' '.$shippingAddress->getLastName()) > 21 ? $shippingAddress->getFirstName() : $shippingAddress->getFirstName().' '.$shippingAddress->getLastName();
        $to = [
          'name' => $name,
          'attn' => $attn,
          'address' => $shippingAddress->getStreet()[0],
          'suite' => $suite,
          'city' => $shippingAddress->getCity(),
          'country' => $shippingAddress->getCountryId(),
          'state' => $this->getStateCode( $shippingAddress->getRegionId() ),
          'postal_code' => $shippingAddress->getPostCode(),
          'phone' => $shippingAddress->getTelephone(),
          'is_commercial' => true
        ];

        if($store->getConfig('carriers/flagship/force_residential')){
            $to['is_commercial'] = false;
        }

        $packages = [
          'units' => $this->getPackageUnits(),
          'type' => 'package',
          'items' => [
            0 =>
            [
                'length' => 1,
                'width' => 1,
                'height'=> 1,
                'weight' => $this->getTotalWeight()
            ]
          ]
        ];

        if($this->flagship->getSettings()["packings"] && !is_null($this->getPackingBoxes())){
            $packages['items'] = $this->getPayloadItems();
        }

        $options = [
          'signature_required' => false,
          'reference' => 'Magento Order# '.$this->getOrder()->getIncrementId(),
          'address_correction' => true
        ];

        $insuranceValue = $this->getInsuranceValue();
        if($store->getConfig('carriers/flagship/insuranceflag') && $insuranceValue != 0){
            $options['insurance'] = [
                'value' => $insuranceValue,
                'description' => 'insurance'
            ];
        }

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

    protected function updateShipment(Flagship $flagship, array $payload,int $shipmentId) : \Magento\Framework\Message\Manager {
        try{

            $update = $flagship->editShipmentRequest($payload,$shipmentId);
            $response = $update->execute();

            $id = $response->getId();
            $orderId = $this->getRequest()->getParam('order_id');
            $trackingid = $response->getTrackingNumber();
            $url = $this->getUrl('shipping/convertShipment',['shipmentId'=> $id, 'order_id' => $orderId]);
            $this->flagship->logInfo('FlagShip Shipment# '.$id.' associated with Order# '.$orderId.' is Updated. Response Code : '.$update->getResponseCode());
            return $this->messageManager->addSuccess(__('FlagShip Shipment Updated : <a target="_blank" href="'.$url.'">'.$id.'</a>'));
        }
        catch(EditShipmentException $e){
            $this->flagship->logError($e->getMessage().' Response Code : '.$update->getResponseCode());
            return $this->messageManager->addErrorMessage(__(ucfirst($e->getMessage())));
        }
    }

    protected function prepareShipment(Flagship $flagship, array $payload) : \Magento\Framework\Message\Manager {
        try{

            $request = $flagship->prepareShipmentRequest($payload);
            $response = $request->execute();
            $id = $response->shipment->id;
            $this->setFlagshipShipmentId($id);
            $orderId = $this->getRequest()->getParam('order_id');
            $url = $this->getUrl('shipping/convertShipment',['shipmentId'=> $id, 'order_id' => $orderId]);
            $this->flagship->logInfo('FlagShip Shipment #'.$id.' prepared for Order# '.$orderId.'. Response Code : '.$request->getResponseCode());
            return $this->messageManager->addSuccess(__('FlagShip Shipment Number:'.$id.' . Click <a href="'.$url.'">here</a> to confirm the shipment'));
        }
        catch(PrepareShipmentException $e){
            $this->flagship->logError($e->getMessage().'. Response Code : '.$request->getResponseCode());
            return $this->messageManager->addErrorMessage(__(ucfirst($e->getMessage())));
        }
    }

    protected function getWeightUnits() : string {

        $weightUnit = $this->getStore()->getConfig('general/locale/weight_unit');
        return $weightUnit;
    }

    protected function getTotalWeight() : float {
        $order = $this->getOrder();
        $items = $order->getAllItems();
        $weight = 0;
        foreach($items as $item){
            $itemWeight = is_null($item->getWeight()) ? 1 : $item->getWeight();
            $weight += ($itemWeight * $item->getQtyToShip());
        }
        if($weight < 1){
            return 1;
        }
        return $weight;
    }

    protected function getPackageUnits() : string {
        if($this->getWeightunits() === 'kgs'){
            return 'metric';
        }
        return 'imperial';
    }

    protected function setFlagshipShipmentId(int $id) : int {

        $this->getOrder()->setData('flagship_shipment_id',$id);
        $this->createShipment($id);
        return 0;
    }

    protected function getShippingAddress() : \Magento\Sales\Model\Order\Address {
        $order = $this->getOrder();
        $shippingAddressDetails = $order->getShippingAddress();
        $shippingAddressDetails = is_null($shippingAddressDetails) ? $order->getBillingAddress() : $shippingAddressDetails;
        return $shippingAddressDetails;
    }

    protected function getStore() : \Magento\Framework\DataObject {

        $store = $this->getOrder()->getStore();
        return $store;
    }

    protected function getStateCode(int $regionId) : string {
        $state = $this->regionFactory->create()->load($regionId);
        $stateCode = $state->getCode();
        return $stateCode;
    }

    protected function getPackingItems() : array {
        return $this->displayPackings->getItems();
    }

    protected function getPackingBoxes() : ?array {

        $packings = $this->displayPackings->getPacking();
        $boxes = [];
        if(is_null($packings)){
            return NULL;
        }
        foreach($packings as $packing){
            $packing["dimensions"] = $this->getBoxWeight($packing);
            $boxes[] = $packing;
        }
        return $boxes;
    }

    protected function getBoxes() : array {
        return $this->displayPackings->getBoxes();
    }

    protected function getBoxWeight(array $box) : array {

        $allBoxes = $this->getBoxes();

        $boxDimensions = [];
        foreach ($allBoxes as $allBox) {
            $boxDimensions = count($this->checkBoxModel($box,$allBox)) > 0 ? $this->checkBoxModel($box,$allBox) : $boxDimensions;
        }
        return $boxDimensions;
    }

    protected function checkBoxModel(array $box,array $allBox) : array {

        $boxDimensions = [];
        if($box["box_model"] == $allBox["box_model"]){
            $boxDimensions["weight"] = $this->getBoxTotalWeight($box,$allBox);
            $boxDimensions["length"] = $allBox["length"];
            $boxDimensions["width"] = $allBox["width"];
            $boxDimensions["height"] = $allBox["height"];
        }
        return $boxDimensions;
    }

    protected function getBoxTotalWeight(array $box,array $allBox) : float {

        foreach($box["items"] as $item){
            $weight = $allBox["weight"]+$this->getItemWeight($item);
        }
        return $weight;
    }

    protected function getItemWeight(string $product) : float {
        $items = $this->getItems();
        $weight = 0.0;
        foreach ($items as $item) {
            $weight = $item["description"] === $product ? $item["weight"] : $weight;
        }
        return $weight;
    }

    protected function getItems() : array {
        return $this->displayPackings->getItems();
    }

    protected function getPayloadItems() : array {
        $items = $this->getPackingBoxes();
        $payloadItems = [];
        foreach ($items as $item) {
            unset($item["items"]);
            $payloadItems[] = $item["dimensions"];
        }
        return $payloadItems;
    }

    protected function getInsuranceValue() : float {
        return $this->getOrder()->getSubtotal() > 100 ? $this->getOrder()->getSubtotal() : 0.00;
    }

    protected function createShipment(int $flagship_shipment_id) : int {

        $order = $this->getOrder();

        $shipment = $this->convertOrder->toShipment($order);

        foreach($order->getAllItems() as $orderItem){

            $qtyShipped = $orderItem->getQtyToShip() == 0 ? 1 : $orderItem->getQtyToShip() ;
            $shipmentItem = $this->convertOrder->itemToShipmentItem($orderItem)->setQty($qtyShipped);
            $shipment->addItem($shipmentItem);
        }

        $shipment->register();

        $shipment->getOrder()->setIsInProcess(true);

        try {
            $shipment->save();
            $shipment->getOrder()->save();
            $shipment->addTrack($this->addShipmentTracking($flagship_shipment_id))->save();
            $shipment->addComment('FlagShip Shipment Unconfirmed')->save();
            return 0;
        } catch (\Exception $e) {
            $this->flagship->logError($e->getMessage());
            throw new \Magento\Framework\Exception\LocalizedException(__($e->getMessage()));
        }
    }

    protected function addShipmentTracking(int $flagship_shipment_id) : \Magento\Sales\Model\Order\Shipment\Track {

        $trackingData = [
            'carrier_code' => 'flagship',
            'title' =>  'FlagShip',
            'number' => 'Unconfirmed -'.$flagship_shipment_id,
            'description' => $flagship_shipment_id
        ];
        $tracking = $this->trackFactory->create()->addData($trackingData);
        return $tracking;
    }

}
