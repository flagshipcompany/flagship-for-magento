<?php
namespace Flagship\Shipping\Controller\Adminhtml\PrepareShipment;
use \Flagship\Shipping\Flagship;
use \Flagship\Shipping\Exceptions\PrepareShipmentException;
use \Flagship\Shipping\Exceptions\EditShipmentException;
use \Flagship\Shipping\Block\DisplayPacking;

class Index extends \Magento\Backend\App\Action
  {

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
        \Magento\InventorySourceDeductionApi\Model\GetSourceItemBySourceCodeAndSku $getSourceItemBySourceCodeAndSku,
        \Magento\Inventory\Model\SourceRepository $sourceRepository,
        \Flagship\Shipping\Block\DisplayPacking $displayPackings,
        \Magento\Sales\Api\Data\ShipmentExtensionFactory $shipmentExtensionFactory,
        \Flagship\Shipping\Block\Flagship $flagship,
        \Magento\Framework\Translate\Inline\StateInterface $inlineTranslation,
        \Magento\Framework\Mail\Template\TransportBuilder $transportBuilder,
        \Magento\InventoryShipping\Model\ResourceModel\ShipmentSource\GetSourceCodeByShipmentId $getSourceCodeByShipmentId
    )
    {
        $this->orderRepository = $orderRepository;
        $this->scopeConfig = $scopeConfig;
        $this->regionFactory = $regionFactory;
        $this->convertOrder = $convertOrder;
        $this->trackFactory = $trackFactory;
        $this->_logger = $logger;
        $this->displayPackings = $displayPackings;
        $this->flagship = $flagship;
        $this->loggingEnabled = $this->flagship->getSettings()["log"];
        $this->getSalableQuantityDataBySku = $getSalableQuantityDataBySku;
        $this->getSourceCodesBySkus = $getSourceCodesBySkus;
        $this->getSourceItemBySourceCodeAndSku = $getSourceItemBySourceCodeAndSku;
        $this->shipmentExtensionFactory = $shipmentExtensionFactory;
        $this->sourceRepository = $sourceRepository;
        $this->inlineTranslation = $inlineTranslation;
        $this->transportBuilder = $transportBuilder;
        $this->getSourceCodeByShipmentId = $getSourceCodeByShipmentId;
        parent::__construct($context);
    }

    public function execute(){

        $update = is_null($this->getRequest()->getParam('update')) ? 0 : $this->getRequest()->getParam('update');
        $token = $this->getToken();
        $flagship = new Flagship($token,SMARTSHIP_API_URL,FLAGSHIP_MODULE,FLAGSHIP_MODULE_VERSION);

        $orderId = $this->getRequest()->getParam('order_id');

        if($update){
            $shipmentId = $this->getRequest()->getParam('shipmentId');
            $payload = $this->getUpdatePayload($flagship,$shipmentId);

            $this->flagship->logInfo('Updating FlagShip Shipment#'.$shipmentId.' for Order#'.$orderId);
            $this->updateShipment($flagship,$payload,$shipmentId);
            return $this->_redirect($this->_redirect->getRefererUrl());
        }

        $orderItems = $this->getSourceCodesForOrderItems();
        $shippingMethod = $this->getOrder()->getShippingMethod();
        $isLogistics = stripos($shippingMethod,'logistics');
        $ltlEmail = explode(",",$this->scopeConfig->getValue('carriers/flagship/ltl_email'));

        if($isLogistics !== FALSE && !is_null($ltlEmail)){

            $this->createLogisticsShipment($ltlEmail,$orderItems);
            return $this->_redirect($this->_redirect->getRefererUrl());
        }

        $this->flagship->logInfo('Preparing FlagShip Shipment for Order#'.$orderId);

        foreach ($orderItems as $orderItem) {
            $payload = $this->getPayload($orderItem);
            $this->prepareShipment($flagship,$payload,$orderItem);
        }
        $this->getOrder()->setIsInProcess(true)->save();
        return $this->_redirect($this->getUrl('sales/order/view',['order_id' => $orderId]));
    }

    public function getToken() : ?string {
        return $this->flagship->getSettings()["token"];
    }

    public function getOrder() : \Magento\Sales\Model\Order {
        $this->orderId = $this->getRequest()->getParam('order_id');
        $order = $this->orderRepository->get($this->orderId);
        $this->order = $order;
        return $order;
    }

    public function getSourceCodesForOrderItems() : array {
        $items = $this->getOrder()->getAllItems();
        $skus = null;
        $orderItems = [];
        foreach ($items as $item) {
            $sku = strcasecmp($item->getProductType(),'configurable') == 0 ? $item->getProductOptions()["simple_sku"] : $item->getProduct()->getSku();
            $sourceCode = $this->getSourceCodesBySkus->execute([$sku])[0];
            $orderItems[$sourceCode]['source'] = $this->sourceRepository->get($sourceCode);
            $orderItems[$sourceCode]['items'][] = $item;
        }
        return $orderItems;
    }

    public function getPayload(array $orderItem) : array {
        $store = $this->getStore();
        $source = $orderItem['source'];

        $from = $this->getSender($source,$store);
        $to = $this->getReceiver($store);

        $packages = $this->getPackages($orderItem);
        $options = $this->getOptions($store);

        $payment = $this->getPayment();

        $payload = [
          "from" => $from,
          "to"  => $to,
          "packages"  => $packages,
          "options" => $options,
          "payment" => $payment
        ];

        return $payload;
    }

    public function getPackages(array $orderItem) : array {
        $items = $orderItem['items'];
        $packageItems = [];
        foreach ($items as $item) {
            $packageItems = $this->getPackageItems($item,$packageItems);
        }

        $packages = [
          'units' => $this->getPackageUnits(),
          'type' => 'package',
          'items' => $packageItems
        ];
        if($this->flagship->getSettings()["packings"] && !is_null($this->getPackingBoxes($orderItem['source']->getSourceCode()))){
            $packages['items'] = $this->getPayloadItems($orderItem['source']->getSourceCode());
        }

        return $packages;
    }

    public function getStateCode(int $regionId) : string {
        $state = $this->regionFactory->create()->load($regionId);
        $stateCode = $state->getCode();
        return $stateCode;
    }

    public function createShipment(int $flagship_shipment_id,array $orderItem,int $confirmed = 0) : \Magento\Sales\Model\Order\Shipment {

        $order = $this->getOrder();

        $shipment = $this->convertOrder->toShipment($order);

        foreach($orderItem['items'] as $item){

            $qtyShipped = $item->getProductType() == 'configurable' && $item->getQtyToShip() == 0 ? $item->getSimpleQtyToShip() :$item->getQtyToShip();
            $shipmentItem = $this->convertOrder->itemToShipmentItem($item)->setQty($qtyShipped);
            $shipment->addItem($shipmentItem);

        }

        $shipment->register();

        $shipment->getOrder()->setIsInProcess(true);
        $shipmentExtension = $shipment->getExtensionAttributes();
        $sourceCode = $orderItem['source']->getSourceCode();

        if(empty($shipmentExtension)){
            $shipmentExtension = $this->shipmentExtensionFactory->create();
        }

        $shipmentExtension->setSourceCode($sourceCode);
        $shipment->setExtensionAttributes($shipmentExtension);
        $shipment->setData('flagship_shipment_id',$flagship_shipment_id);

        if($confirmed == 0){
            $shipment = $this->setTrackingDetails($flagship_shipment_id,$shipment);
        }
        return $shipment;
    }

    protected function getSourceForShipment(int $shipmentId) : \Magento\Inventory\Model\Source {
        $sourceCode = $this->getSourceCodeByShipmentId->execute($shipmentId);
        $source = $this->sourceRepository->get($sourceCode);
        return $source;
    }

    protected function sendLtlEmail(array $ltlEmail,int $shipmentId,string $dimensions,float $totalWeight) : \Magento\Framework\Message\Manager {

        $street = implode(",",$this->order->getShippingAddress()->getStreet());
        $sourceCode = $this->getSourceCodeByShipmentId->execute($shipmentId);
        $source = $this->getSourceForShipment($shipmentId);
        $this->inlineTranslation->suspend();
        $transport = $this->transportBuilder->setTemplateIdentifier('Flagship_Shipping_logistics')
            ->setTemplateOptions([ 'area'=> 'frontend','store' => $this->getOrder()->getStoreId() ])
            ->setTemplateVars([
                'shippingAddress' => $this->order->getShippingAddress(),
                'street' => $street,
                'source' => $source,
                'regionCode' => $this->getStateCode($source->getRegionId()),
                'dimensions' => $dimensions,
                'totalWeight' => $totalWeight.' '.$this->getWeightUnits()
            ])
            ->setFrom('general',1)
            ->addTo($ltlEmail)
            ->getTransport();
        $transport->sendMessage();
        $this->inlineTranslation->resume();
        return $this->messageManager->addSuccess(__('FlagShip logistics request has been created. Please allow us 2 business days to process it. Someone from FlagShip will contact you soon.'));
    }

    protected function createLogisticsShipment(array $ltlEmail,array $orderItems) : int {
        foreach ($orderItems as $orderItem ) {

            $dimensions = '';
            $totalWeight = 0;
            $units = $this->getPackageUnits() == 'imperial' ? 'inch' : 'cm';
            $items = $this->getLogisticsPackages($orderItem["source"]->getSourceCode());

            foreach ($items as $item) {
                $dimensions .= '<br>'.$item["box_model"].' - '.$item["dimensions"].' '.$units;
                $totalWeight += $item["weight"];
            }

            $shipment = $this->createShipment(123456,$orderItem,0);

            $shipment->setData('flagship_shipment_id',NULL);
            $shipment->setStatus(1);
            $track = $shipment->getAllTracks()[0];
            $track->setTrackNumber("Logistics");
            $track->setNumber("Logistics");
            $track->setDescription("Logistics");
            $track->save();
            $shipment->save();
            $shipmentId = $shipment->getId();
            $this->sendLtlEmail($ltlEmail,$shipmentId,$dimensions,$totalWeight);
        }
        return 0;
    }

    protected function getLogisticsPackages(string $sourceCode) : array {
        $items = $this->getLogisticsBoxes($sourceCode);
        return $items;
    }

    protected function getPayment() : array {
        $payment = [
          'payer' => 'F'
        ];
        return $payment;
    }

    protected function getOptions(\Magento\Store\Model\Store $store) : array {
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
        return $options;
    }

    protected function getReceiver(\Magento\Store\Model\Store $store) : array {
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
        return $to;
    }

    protected function getPackageItems(\Magento\Sales\Model\Order\Item $item,array $packageItems) : array {
        $qty = $item->getQtyOrdered();
        for($i=0; $i<$qty;$i++){
            $packageItems[] = [
                'length' => intval($item->getProduct()->getDataByKey('ts_dimensions_length')),
                'width' => intval($item->getProduct()->getDataByKey('ts_dimensions_width')),
                'height'=> intval($item->getProduct()->getDataByKey('ts_dimensions_height')),
                'weight' => $item->getProduct()->getWeight(),
                'description' => $item->getProduct()->getName()
            ];
        }
        return $packageItems;
    }

    protected function getUpdatePayload(Flagship $flagship,int $shipmentId) : array {
        $store = $this->getStore();
        $shipment = $flagship->getShipmentByIdRequest($shipmentId)->execute();

        $from = (array)$shipment->shipment->from;
        unset($from['phone_ext']);
        $to = $this->getReceiver($store);

        $packages = (array) $shipment->shipment->packages;

        $items = $packages["items"];

        foreach ($items as $item) {
            unset($item->pin);
            $item->length = intval($item->length);
            $item->width = intval($item->width);
            $item->height = intval($item->height);
        }
        $options = $this->getOptions($store);

        $payment = $this->getPayment();

        $payload = [
            "from" => $from,
            "to" => $to,
            "packages" => $packages,
            "options" => $options,
            "payment" => $payment
        ];
        return $payload;
    }

    protected function getSender(\Magento\Inventory\Model\Source $source,\Magento\Store\Model\Store $store) : array {


        $country = !is_null($source) && !is_null($source->getCountryId()) ? $source->getCountryId() : $store->getConfig('general/store_information/country_id') ;

        $stateCode = !is_null($source) && !is_null($source->getRegionId()) ? $source->getRegionId() : $store->getConfig('general/store_information/region_id');
        $state = empty($stateCode) ? $stateCode : $this->getStateCode($stateCode);

        $name  = !is_null($source) && !is_null($source->getName()) ? $source->getName() : $store->getConfig('general/store_information/name');
        $attn =!is_null($source) && !is_null($source->getContactName()) ? $source->getContactName() : $name ;
        $address = !is_null($source) && !is_null($source->getStreet()) ? $source->getStreet() : $store->getConfig('general/store_information/street_line1');
        $suite = is_null($store->getConfig('general/store_information/street_line2')) ? '' : $store->getConfig('general/store_information/street_line2');
        $city = !is_null($source) && !is_null($source->getCity()) ? $source->getCity() : $store->getConfig('general/store_information/city');
        $postcode = !is_null($source) && !is_null($source->getPostcode()) ? $source->getPostcode() : $store->getConfig('general/store_information/postcode');
        $phone = !is_null($source) && !is_null($source->getPhone()) ? $source->getPhone() : $store->getConfig('general/store_information/phone');

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

        return $from;

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

    protected function prepareShipment(Flagship $flagship, array $payload, array $orderItem) : \Magento\Framework\Message\Manager {
        try{

            $request = $flagship->prepareShipmentRequest($payload);
            $response = $request->execute();
            $id = $response->shipment->id;
            $this->setFlagshipShipmentId($id,$orderItem);
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

    protected function setFlagshipShipmentId(int $id,array $orderItem) : int {

        $this->createShipment($id,$orderItem);
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

    protected function getLogisticsBoxes(string $sourceCode) : array {
        $packings = $this->displayPackings->getPacking();
        $this->boxes = [];
        if(is_null($packings)){
            return NULL;
        }

        foreach ($packings as $value) {
            $this->getPackingBoxesBySource($sourceCode,$value);
        }
        return $this->boxes;
    }

    protected function getPackingBoxesBySource(string $sourceCode,array $value) : array {

        if($value['source_code'] == $sourceCode){
            $this->boxes[] = $value;
        }
        return $this->boxes;
    }

    protected function getPackings() : ?array {
        return $this->displayPackings->getPacking();
    }

    protected function getPackingBoxes(string $sourceCode) : ?array {

        $packings = $this->getPackings();

        $boxes = [];
        if(is_null($packings)){
            return NULL;
        }

        foreach ($packings as $value) {
            $boxes[] = $value['source_code'] == $sourceCode ? $value : NULL;
        }

        $boxes = array_filter($boxes,function($value){ return $value != NULL; });
        return $boxes;
    }

    protected function getBoxes() : array {
        return $this->displayPackings->getBoxes();
    }

    protected function getBoxWeight(array $box ) : array {

        $allBoxes = $this->getBoxes();

        $boxDimensions = [];
        foreach ($allBoxes as $allBox) {
            $boxDimensions = count($this->checkBoxModel($box,$allBox )) > 0 ? $this->checkBoxModel($box,$allBox ) : $boxDimensions;
        }
        return $boxDimensions;
    }

    protected function checkBoxModel(array $box,array $allBox ) : array {

        $boxDimensions = [];
        if($box["box_model"] == $allBox["box_model"]){
            $boxDimensions["weight"] = $this->getBoxTotalWeight($box,$allBox );
            $boxDimensions["length"] = $allBox["length"];
            $boxDimensions["width"] = $allBox["width"];
            $boxDimensions["height"] = $allBox["height"];
            $boxDimensions["description"] = $allBox["box_model"];
        }
        return $boxDimensions;
    }

    protected function getBoxTotalWeight(array $box,array $allBox ) : float {

        foreach($box["items"] as $item){
            $weight = $allBox["weight"]+$this->getItemWeight($item );
        }
        return $weight;
    }

    protected function getItemWeight(string $product ) : float {

        $items = $this->getItems( );
        $weight = 0.0;
        foreach ($items as $item) {
            $weight = $item["description"] === $product ? $item["weight"] : $weight;
        }
        return $weight;
    }

    protected function getItems( ) : array {

        return $this->displayPackings->getItemsForPrepareShipment();
    }

    protected function getPayloadItems(string $sourceCode) : array {

        $items = $this->getPackingBoxes( $sourceCode );
        $payloadItems = [];

        foreach ($items as $item) {

            $dimensions = explode('x',$item["dimensions"]);
            $temp = [
                "description" => $item["box_model"],
                "length" => $dimensions[0],
                "width" => $dimensions[1],
                "height" => $dimensions[2],
                "weight" => $item["weight"]
            ];
            $payloadItems[] = $temp;
        }
        return $payloadItems;
    }

    protected function getInsuranceValue() : float {
        return $this->getOrder()->getSubtotal() > 100 ? $this->getOrder()->getSubtotal() : 0.00;
    }

    protected function setTrackingDetails(int $flagship_shipment_id,\Magento\Sales\Model\Order\Shipment $shipment) : \Magento\Sales\Model\Order\Shipment {
        try {
            $shipment->addTrack($this->addShipmentTracking($flagship_shipment_id));
            $shipment->addComment('FlagShip Shipment Unconfirmed');
            $shipment->save();
            return $shipment;
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
