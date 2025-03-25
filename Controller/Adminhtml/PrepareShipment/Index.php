<?php

namespace Flagship\Shipping\Controller\Adminhtml\PrepareShipment;

use Flagship\Shipping\Exceptions\EditShipmentException;
use Flagship\Shipping\Exceptions\PrepareShipmentException;
use Flagship\Shipping\Flagship;
use Flagship\Shipping\Model\Configuration;
use Magento\Backend\App\Action\Context;
use Magento\Sales\Model\OrderRepository;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Directory\Model\RegionFactory;
use Magento\Sales\Model\Convert\Order;
use Magento\Sales\Model\Order\Shipment\TrackFactory;
use Flagship\Shipping\Logger\Logger;
use Magento\InventorySalesAdminUi\Model\GetSalableQuantityDataBySku;
use Magento\Inventory\Model\GetSourceCodesBySkus;
use Magento\InventorySourceDeductionApi\Model\GetSourceItemBySourceCodeAndSku;
use Magento\Inventory\Model\SourceRepository;
use Flagship\Shipping\Block\DisplayPacking;
use Magento\Sales\Api\Data\ShipmentExtensionFactory;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\InventoryShipping\Model\ResourceModel\ShipmentSource\GetSourceCodeByShipmentId;
use Flagship\Shipping\Model\Carrier\FlagshipQuote;
use Magento\Catalog\Model\ProductRepository;
use Magento\Customer\Api\AddressRepositoryInterface;

class Index extends \Magento\Backend\App\Action
{
    protected $orderId;

    public function __construct(
        protected Context $context,
        protected OrderRepository $orderRepository,
        protected ScopeConfigInterface $scopeConfig,
        protected RegionFactory $regionFactory,
        protected Order $convertOrder,
        protected TrackFactory $trackFactory,
        protected Logger $logger,
        protected GetSalableQuantityDataBySku $getSalableQuantityDataBySku,
        protected GetSourceCodesBySkus $getSourceCodesBySkus,
        protected GetSourceItemBySourceCodeAndSku $getSourceItemBySourceCodeAndSku,
        protected SourceRepository $sourceRepository,
        protected DisplayPacking $displayPackings,
        protected ShipmentExtensionFactory $shipmentExtensionFactory,
        protected Flagship $flagship,
        protected StateInterface $inlineTranslation,
        protected TransportBuilder $transportBuilder,
        protected GetSourceCodeByShipmentId $getSourceCodeByShipmentId,
        protected FlagshipQuote $flagshipQuote,
        protected ProductRepository $productRepository,
        protected AddressRepositoryInterface $addressRepository,
        protected Configuration $configuration
    ) {
        $this->_logger = $logger;
        $this->flagshipQuote = $flagshipQuote;
        parent::__construct($context);
    }

    public function execute()
    {
        $update = is_null($this->getRequest()->getParam('update')) ? 0 : $this->getRequest()->getParam('update');
        $token = $this->getToken();
        $flagship = new Flagship($token, SMARTSHIP_API_URL, FLAGSHIP_MODULE, FLAGSHIP_MODULE_VERSION);

        $orderId = $this->getRequest()->getParam('order_id');

        if ($update) {
            $shipmentId = $this->getRequest()->getParam('shipmentId');
            $payload = $this->getUpdatePayload($flagship, $shipmentId);

            // $this->flagship->logInfo('Updating FlagShip Shipment#' . $shipmentId . ' for Order#' . $orderId);
            $this->updateShipment($flagship, $payload, $shipmentId);
            return $this->_redirect($this->_redirect->getRefererUrl());
        }

        $orderItems = $this->getSourceCodesForOrderItems();
        $shippingMethod = $this->getOrder()->getShippingMethod();
        $isLogistics = stripos($shippingMethod, 'logistics');
        $ltlEmail = explode(",", $this->scopeConfig->getValue('carriers/flagship/ltl_email'));
        $shipAsIsProducts = [];

        if ($isLogistics !== false && !is_null($ltlEmail)) {
            $this->createLogisticsShipment($ltlEmail, $orderItems);
            return $this->_redirect($this->_redirect->getRefererUrl());
        }

        // $this->flagship->logInfo('Preparing FlagShip Shipment for Order#' . $orderId);

        foreach ($orderItems as $orderItem) {
            $payload = $this->getPayload($orderItem);
            $this->prepareShipment($flagship, $payload, $orderItem);
        }
        $this->getOrder()->setIsInProcess(true)->save();
        return $this->_redirect($this->getUrl('sales/order/view', ['order_id' => $orderId]));
    }

    public function getToken() : ?string
    {
        return $this->configuration->getToken();
    }

    public function getOrder() : \Magento\Sales\Model\Order
    {
        $this->orderId = $this->getRequest()->getParam('order_id');
        $order = $this->orderRepository->get($this->orderId);
        $this->order = $order;
        return $order;
    }

    public function getSourceCodesForOrderItems() : array
    {
        $items = $this->getOrder()->getAllItems();
        $sku = null;
        $orderItems = [];
        $destinationAddressForSourceSelection = $this->getDestinationAddress();

        foreach ($items as $item) {
            $sku = $item->getSku();

            $sourceCodes = $this->getSourceCodesBySkus->execute([$sku]);

            $sourceCode = $this->flagshipQuote->getOptimumSource($sourceCodes, null, $item, $destinationAddressForSourceSelection);

            $orderItems[$sourceCode] = $this->skipIfItemIsDownloadable($orderItems, $sourceCode, $item);
        }
        return $orderItems;
    }

    protected function skipIfItemIsDownloadable(array $orderItems, string $sourceCode, \Magento\Sales\Model\Order\Item $item) : array
    {
        $orderItems[$sourceCode]['source'] = $this->sourceRepository->get($sourceCode);
        if ($item->getProductType() != 'downloadable') {
            $orderItems[$sourceCode]['items'][] = $item;
        }
        return $orderItems[$sourceCode];
    }

    protected function getDestinationAddress() : array
    {
        $shippingAddress = $this->getShippingAddress();

        $destinationAddress = [
            'city' => $shippingAddress->getCity(),
            'country' => $shippingAddress->getCountryId(),
            'state' => $this->getStateCode($shippingAddress->getRegionId()),
            'postal_code' => $shippingAddress->getPostCode(),
      ];

        return $destinationAddress;
    }

    protected function getShipAsIsProducts($orderItems)
    {
        $shipAsIsProducts = [];
        foreach ($orderItems['items'] as $item) {
            if ($item->getProduct()->getDataByKey('ship_as_is') == 1) {
                $shipAsIsProducts[] = $item->getProduct();
            }
        }
        return $shipAsIsProducts;
    }

    public function getPayload(array $orderItem) : array
    {
        $store = $this->getStore();
        $source = $orderItem['source'];

        $from = $this->getSender($source, $store);
        $to = $this->getReceiver($store);
        $this->shipAsIsProducts = [];

        if (array_key_exists('items', $orderItem)) {
            $this->addShipAsIsPackages($orderItem['items']);
        }

        $packages = $this->getPackages($orderItem);
        if (count($this->shipAsIsProducts) > 0) {
            $packages = $this->getPackagesForShipAsIsProducts($packages);
        }

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

    protected function getPackagesForShipAsIsProducts(array $packages)
    {
        foreach ($this->shipAsIsProducts as $value) {

            $product = $value->getProduct();
            $qtyOrdered = $value->getQtyOrdered();
            $packages = $this->addShipAsIsItemsByQty($product, $qtyOrdered, $packages);

        }
        return $packages;
    }

    protected function addShipAsIsItemsByQty($product, $qtyOrdered, array $packages) 
    {
        $length = $product->getDataByKey('ts_dimensions_length') == null ? round($product->getDataByKey('length'), 0, PHP_ROUND_HALF_UP) : round($product->getDataByKey('ts_dimensions_length'), 0, PHP_ROUND_HALF_UP);
        $width = $product->getDataByKey('ts_dimensions_width') == null ? round($product->getDataByKey('width'), 0, PHP_ROUND_HALF_UP) : round($product->getDataByKey('ts_dimensions_width'), 0, PHP_ROUND_HALF_UP);
        $height = $product->getDataByKey('ts_dimensions_height') == null ? round($product->getDataByKey('height'), 0, PHP_ROUND_HALF_UP) : round($product->getDataByKey('ts_dimensions_height'), 0, PHP_ROUND_HALF_UP);
        $height = max($height,1);

        for ($i = 0; $i < $qtyOrdered ; $i++) {
            $packages['items'][] = [
                'description' => $product->getName(),
                'length' => $length,
                'width' => $width,
                'height' => $height,
                'weight' => round($product->getWeight(),0,PHP_ROUND_HALF_UP)
            ];
    
        }
        
        return $packages;
    }

    protected function addShipAsIsPackages($orderItems)
    {
        foreach ($orderItems as $orderItem) {
            $this->getShipAsIsProductsArray($orderItem);
        }
    }

    protected function getShipAsIsProductsArray($orderItem)
    {
        if ($orderItem->getProduct()->getDataByKey('ship_as_is') == 1) {
            $this->shipAsIsProducts[] = $orderItem;
            unset($orderItem);
        }
    }

    public function getPackages(array $orderItem) : array
    {
        $items = array_key_exists('items', $orderItem) ? $orderItem['items'] : [];
        $packageItems = [];
        foreach ($items as $item) {
            $packageItems = $this->getPackageItems($item, $packageItems);
        }

        $packages = [
          'units' => $this->getPackageUnits(),
          'type' => 'package',
          'items' => $packageItems
        ];
        $packings = $this->configuration->isPackingEnabled();
        if ($packings && !is_null($this->getPackingBoxes($orderItem['source']->getSourceCode()))) {
            $packages['items'] = $this->getPayloadItems($orderItem);
        }

        return $packages;
    }

    public function getStateCode(int $regionId) : string
    {
        $state = $this->regionFactory->create()->load($regionId);
        $stateCode = $state->getCode();
        return $stateCode;
    }

    public function createShipment(int $flagship_shipment_id, array $orderItem, int $confirmed = 0) : \Magento\Sales\Model\Order\Shipment
    {
        $order = $this->getOrder();

        $shipment = $this->convertOrder->toShipment($order);

        foreach ($orderItem['items'] as $item) {
            $qtyShipped = $item->getProductType() == 'configurable' && $item->getQtyToShip() == 0 ? $item->getSimpleQtyToShip() : $item->getQtyToShip();
            $shipmentItem = $this->convertOrder->itemToShipmentItem($item)->setQty($qtyShipped);
            $shipment->addItem($shipmentItem);
        }

        $shipment->register();

        $shipment->getOrder()->setIsInProcess(true);
        $shipmentExtension = $shipment->getExtensionAttributes();
        $sourceCode = $orderItem['source']->getSourceCode();

        if (empty($shipmentExtension)) {
            $shipmentExtension = $this->shipmentExtensionFactory->create();
        }

        $shipmentExtension->setSourceCode($sourceCode);
        $shipment->setExtensionAttributes($shipmentExtension);
        $shipment->setData('flagship_shipment_id', $flagship_shipment_id);

        if ($confirmed == 0) {
            $shipment = $this->setTrackingDetails($flagship_shipment_id, $shipment);
        }
        return $shipment;
    }

    protected function getSourceForShipment(int $shipmentId) : \Magento\Inventory\Model\Source
    {
        $sourceCode = $this->getSourceCodeByShipmentId->execute($shipmentId);
        $source = $this->sourceRepository->get($sourceCode);
        return $source;
    }

    protected function sendLtlEmail(array $ltlEmail, int $shipmentId, string $dimensions, float $totalWeight) : \Magento\Framework\Message\Manager
    {
        $street = implode(",", $this->order->getShippingAddress()->getStreet());
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
                'totalWeight' => $totalWeight . ' ' . $this->getWeightUnits()
            ])
            ->setFrom('general', 1)
            ->addTo($ltlEmail)
            ->getTransport();
        $transport->sendMessage();
        $this->inlineTranslation->resume();
        return $this->messageManager->addSuccess(__('FlagShip logistics request has been created. Please allow us 2 business days to process it. Someone from FlagShip will contact you soon.'));
    }

    protected function createLogisticsShipment(array $ltlEmail, array $orderItems) : int
    {
        foreach ($orderItems as $orderItem) {
            $dimensions = '';
            $totalWeight = 0;
            $units = $this->getPackageUnits() == 'imperial' ? 'inch' : 'cm';
            $items = $this->getLogisticsPackages($orderItem["source"]->getSourceCode());

            foreach ($items as $item) {
                $dimensions .= '<br>' . $item["box_model"] . ' - ' . $item["dimensions"] . ' ' . $units;
                $totalWeight += $item["weight"];
            }

            $shipment = $this->createShipment(123456, $orderItem, 0);

            $shipment->setData('flagship_shipment_id', null);
            $shipment->setStatus(1);
            $track = $shipment->getAllTracks()[0];
            $track->setTrackNumber("Logistics");
            $track->setNumber("Logistics");
            $track->setDescription("Logistics");
            $track->save();
            $shipment->save();
            $shipmentId = $shipment->getId();
            $this->sendLtlEmail($ltlEmail, $shipmentId, $dimensions, $totalWeight);
        }
        return 0;
    }

    protected function getLogisticsPackages(string $sourceCode) : array
    {
        $items = $this->getLogisticsBoxes($sourceCode);
        return $items;
    }

    protected function getPayment() : array
    {
        $payment = [
          'payer' => 'F'
        ];
        return $payment;
    }

    protected function getOptions(\Magento\Store\Model\Store $store) : array
    {
        $options = [
          'signature_required' => false,
          'reference' => 'Magento Order# ' . $this->getOrder()->getIncrementId(),
          'address_correction' => true
        ];

        $insuranceValue = $this->getInsuranceValue();
        if ($store->getConfig('carriers/flagship/insurance') && $insuranceValue != 0) {
            $options['insurance'] = [
                'value' => $insuranceValue,
                'description' => 'insurance'
            ];
        }

        $shippingAddressCustomerAddressId = $this->order->getShippingAddress()->getCustomerAddressId();
        $address = $this->addressRepository->getById($shippingAddressCustomerAddressId);

        if($address->getCustomAttribute('tracking_email') != NULL) {      
            $options["shipment_tracking_emails"] =  $address->getCustomAttribute('tracking_email')->getValue();
        }
        
        if($store->getConfig('carriers/flagship/delivery_instructions')) {
            $options["driver_instructions"] = $store->getConfig('carriers/flagship/delivery_instructions');
        }        
        return $options;
    }

    protected function getReceiver(\Magento\Store\Model\Store $store) : array
    {
        $shippingAddress = $this->getShippingAddress();
        $suite = isset($shippingAddress->getStreet()[1]) ? $shippingAddress->getStreet()[1] : null;
        $name = is_null($shippingAddress->getCompany()) ? $shippingAddress->getFirstName() : $shippingAddress->getCompany();
        $attn = strlen($shippingAddress->getFirstName() . ' ' . $shippingAddress->getLastName()) > 21 ? $shippingAddress->getFirstName() : $shippingAddress->getFirstName() . ' ' . $shippingAddress->getLastName();

        $to = [
          'name' => substr($name, 0, 29),
          'attn' => substr($attn, 0, 20),
          'address' => substr($shippingAddress->getStreet()[0], 0, 29),
          'suite' => substr($suite, 0, 17),
          'city' => substr($shippingAddress->getCity(), 0, 29),
          'country' => $shippingAddress->getCountryId(),
          'state' => $this->getStateCode($shippingAddress->getRegionId()),
          'postal_code' => $shippingAddress->getPostCode(),
          'phone' => $shippingAddress->getTelephone(),
          'is_commercial' => true
        ];

        if ($store->getConfig('carriers/flagship/residential')) {
            $to['is_commercial'] = false;
        }
        return $to;
    }

    protected function getPackageItems(\Magento\Sales\Model\Order\Item $item, array $packageItems) : array
    {
        $qty = $item->getQtyOrdered();
        $length = $item->getProduct()->getDataByKey('ts_dimensions_length') == null ? round($item->getProduct()->getDataByKey('length'), 0, PHP_ROUND_HALF_UP) : round($item->getProduct()->getDataByKey('ts_dimensions_length'), 0, PHP_ROUND_HALF_UP);
        $width = $item->getProduct()->getDataByKey('ts_dimensions_width') == null ? round($item->getProduct()->getDataByKey('width'), 0, PHP_ROUND_HALF_UP) : round($item->getProduct()->getDataByKey('ts_dimensions_width'), 0, PHP_ROUND_HALF_UP);
        $height = $item->getProduct()->getDataByKey('ts_dimensions_height') == null ? round($item->getProduct()->getDataByKey('height'), 0, PHP_ROUND_HALF_UP) : round($item->getProduct()->getDataByKey('ts_dimensions_height'), 0, PHP_ROUND_HALF_UP);

        for ($i=0; $i<$qty;$i++) {
            $packageItems[] = [
                'length' => $length,
                'width' => $width,
                'height'=> $height,
                'weight' => max($item->getProduct()->getWeight(),1),
                'description' => $item->getProduct()->getName()
            ];
        }
        return $packageItems;
    }

    protected function getUpdatePayload(Flagship $flagship, int $shipmentId) : array
    {
        $store = $this->getStore();
        $orderId = $this->getOrder()->getId();
        $storeName = $this->scopeConfig->getValue('general/store_information/name') == null ? '' : $this->scopeConfig->getValue('general/store_information/name');
        $shipment = $flagship->getShipmentByIdRequest($shipmentId)->setStoreName($storeName)->setOrderId($orderId)->setOrderLink($this->getUrl('sales/order/view', ['order_id' => $orderId]))->execute();

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

    protected function getSender(\Magento\Inventory\Model\Source $source, \Magento\Store\Model\Store $store) : array
    {
        $country = !is_null($source) && !is_null($source->getCountryId()) ? $source->getCountryId() : $store->getConfig('general/store_information/country_id');

        $stateCode = !is_null($source) && !is_null($source->getRegionId()) ? $source->getRegionId() : $store->getConfig('general/store_information/region_id');
        $state = empty($stateCode) ? $stateCode : $this->getStateCode($stateCode);

        $name  = !is_null($source) && !is_null($source->getName()) ? substr($source->getName(), 0, 29) : substr($store->getConfig('general/store_information/name'), 0, 29);
        $attn =!is_null($source) && !is_null($source->getContactName()) ? substr($source->getContactName(), 0, 20) : substr($name, 0, 20);
        $address = !is_null($source) && !is_null($source->getStreet()) ? $source->getStreet() : $store->getConfig('general/store_information/street_line1');
        $suite = is_null($store->getConfig('general/store_information/street_line2')) ? '' : $store->getConfig('general/store_information/street_line2');
        $city = !is_null($source) && !is_null($source->getCity()) ? $source->getCity() : $store->getConfig('general/store_information/city');
        $postcode = !is_null($source) && !is_null($source->getPostcode()) ? $source->getPostcode() : $store->getConfig('general/store_information/postcode');
        $phone = !is_null($source) && !is_null($source->getPhone()) ? $source->getPhone() : $store->getConfig('general/store_information/phone');

        $from = [
          'name' => substr($name, 0, 29),
          'attn' => substr($name, 0, 20),
          'address' => substr($address, 0, 29),
          'suite' => substr($suite, 0, 17),
          'city' => substr($city, 0, 29),
          'country' => $country,
          'state' => $state,
          'postal_code' => $postcode,
          'phone' => $phone,
          'is_commercial' => 'true'
        ];

        return $from;
    }

    protected function updateShipment(Flagship $flagship, array $payload, int $shipmentId) : \Magento\Framework\Message\Manager
    {
        try {
            $orderId = $this->getOrder()->getId();
            $storeName = $this->scopeConfig->getValue('general/store_information/name') == null ? '' : $this->scopeConfig->getValue('general/store_information/name');

            $update = $flagship->editShipmentRequest($payload, $shipmentId)->setStoreName($storeName)->setOrderId($orderId)->setOrderLink($this->getUrl('sales/order/view', ['order_id' => $orderId]));
            $response = $update->execute();

            $id = $response->getId();
            $orderId = $this->getRequest()->getParam('order_id');
            $trackingid = $response->getTrackingNumber();
            $url = $this->getUrl('shipping/convertShipment', ['shipmentId'=> $id, 'order_id' => $orderId]);
            // $this->flagship->logInfo('FlagShip Shipment# ' . $id . ' associated with Order# ' . $orderId . ' is Updated. Response Code : ' . $update->getResponseCode());
            return $this->messageManager->addSuccess(__('FlagShip Shipment Updated : <a target="_blank" href="' . $url . '">' . $id . '</a>'));
        } catch (EditShipmentException $e) {
            // $this->flagship->logError($e->getMessage() . ' Response Code : ' . $update->getResponseCode());
            return $this->messageManager->addErrorMessage(__(ucfirst($e->getMessage())));
        }
    }

    protected function prepareShipment(Flagship $flagship, array $payload, array $orderItem) : \Magento\Framework\Message\Manager
    {
        try {
            $orderId = $this->getOrder()->getId();
            $storeName = $this->scopeConfig->getValue('general/store_information/name') == null ? '' : $this->scopeConfig->getValue('general/store_information/name');

            // $this->flagship->logInfo("Prepare Shipment payload sent to FlagShip: ".json_encode($payload));
            $request = $flagship->prepareShipmentRequest($payload)->setStoreName($storeName)->setOrderId($orderId)->setOrderLink($this->getUrl('sales/order/view', ['order_id' => $orderId]));
            $response = $request->execute();
            $id = $response->shipment->id;
            $this->setFlagshipShipmentId($id, $orderItem);
            $orderId = $this->getRequest()->getParam('order_id');
            $url = $this->getUrl('shipping/convertShipment', ['shipmentId'=> $id, 'order_id' => $orderId]);
            // $this->flagship->logInfo('FlagShip Shipment #' . $id . ' prepared for Order# ' . $orderId . '. Response Code : ' . $request->getResponseCode());
            return $this->messageManager->addSuccess(__('FlagShip Shipment Number:' . $id . ' . Click <a href="' . $url . '">here</a> to confirm the shipment'));
        } catch (PrepareShipmentException $e) {
            // $this->flagship->logError($e->getMessage() . '. Response Code : ' . $request->getResponseCode());
            return $this->messageManager->addErrorMessage(__(ucfirst($e->getMessage())));
        }
    }

    protected function getWeightUnits() : string
    {
        // We only use imperial units because Magento doesn't automatically convert the products' details.
        return 'lbs';
        // $weightUnit = $this->getStore()->getConfig('general/locale/weight_unit');
        // return $weightUnit;
    }

    protected function getTotalWeight() : float
    {
        $order = $this->getOrder();
        $items = $order->getAllItems();
        $weight = 0;
        foreach ($items as $item) {
            $itemWeight = is_null($item->getWeight()) ? 1 : $item->getWeight();
            $weight += ($itemWeight * $item->getQtyToShip());
        }
        if ($weight < 1) {
            return 1;
        }
        return $weight;
    }

    protected function getPackageUnits() : string
    {
        if ($this->getWeightunits() === 'kgs') {
            return 'metric';
        }
        return 'imperial';
    }

    protected function setFlagshipShipmentId(int $id, array $orderItem) : int
    {
        if (array_key_exists('items', $orderItem)) {
            $this->createShipment($id, $orderItem);
        }
        return 0;
    }

    protected function getShippingAddress() : \Magento\Sales\Model\Order\Address
    {
        $order = $this->getOrder();
        $shippingAddressDetails = $order->getShippingAddress();
        $shippingAddressDetails = is_null($shippingAddressDetails) ? $order->getBillingAddress() : $shippingAddressDetails;
        return $shippingAddressDetails;
    }

    protected function getStore() : \Magento\Framework\DataObject
    {
        $store = $this->getOrder()->getStore();
        return $store;
    }

    protected function getLogisticsBoxes(string $sourceCode) : array
    {
        $packings = $this->displayPackings->getPacking();
        $this->boxes = [];
        if (is_null($packings)) {
            return null;
        }

        foreach ($packings as $value) {
            $this->getPackingBoxesBySource($sourceCode, $value);
        }
        return $this->boxes;
    }

    protected function getPackingBoxesBySource(string $sourceCode, array $value) : array
    {
        if ($value['source_code'] == $sourceCode) {
            $this->boxes[] = $value;
        }
        return $this->boxes;
    }

    protected function getPackings() : ?array
    {
        return $this->displayPackings->getPacking();
    }

    protected function getPackingBoxes(string $sourceCode) : ?array
    {
        $packings = $this->getPackings()['packingDetails'];

        $boxes = [];
        if (is_null($packings)) {
            return null;
        }

        foreach ($packings as $value) {
            $boxes[] = $value['source_code'] == $sourceCode ? $value : null;
        }

        $boxes = array_filter($boxes, function ($value) {
            return $value != null;
        });
        return $boxes;
    }

    protected function getBoxes() : array
    {
        return $this->displayPackings->getBoxes();
    }

    protected function getBoxWeight(array $box) : array
    {
        $allBoxes = $this->getBoxes();

        $boxDimensions = [];
        foreach ($allBoxes as $allBox) {
            $boxDimensions = count($this->checkBoxModel($box, $allBox)) > 0 ? $this->checkBoxModel($box, $allBox) : $boxDimensions;
        }
        return $boxDimensions;
    }

    protected function checkBoxModel(array $box, array $allBox) : array
    {
        $boxDimensions = [];
        if ($box["box_model"] == $allBox["box_model"]) {
            $boxDimensions["weight"] = $this->getBoxTotalWeight($box, $allBox);
            $boxDimensions["length"] = $allBox["length"];
            $boxDimensions["width"] = $allBox["width"];
            $boxDimensions["height"] = $allBox["height"];
            $boxDimensions["description"] = $allBox["box_model"];
        }
        return $boxDimensions;
    }

    protected function getBoxTotalWeight(array $box, array $allBox) : float
    {
        foreach ($box["items"] as $item) {
            $weight = $allBox["weight"]+$this->getItemWeight($item);
        }
        return $weight;
    }

    protected function getItemWeight(string $product) : float
    {
        $items = $this->getItems();
        $weight = 0.0;
        foreach ($items as $item) {
            $weight = $item["description"] === $product ? $item["weight"] : $weight;
        }
        return $weight;
    }

    protected function getItems() : array
    {
        return $this->displayPackings->getItemsForPrepareShipment();
    }

    protected function getPayloadItems(array $orderItem) : array
    {
        $payloadItems = [];
        $sourceCode = $orderItem['source']->getSourceCode();
        $items = $this->getPackingBoxes($sourceCode);

        foreach ($items as $item) {
            $dimensions = explode('x', $item["dimensions"]);
            $temp = [
                "description" => $item["box_model"],
                "length" => $dimensions[0],
                "width" => $dimensions[1],
                "height" => $dimensions[2],
                "weight" => max($item["weight"],1)
            ];
            $payloadItems[] = $temp;
        }
        return $payloadItems;
    }

    protected function getInsuranceValue() : float
    {
        return $this->getOrder()->getSubtotal() > 100 ? $this->getOrder()->getSubtotal() : 0.00;
    }

    protected function setTrackingDetails(int $flagship_shipment_id, \Magento\Sales\Model\Order\Shipment $shipment) : \Magento\Sales\Model\Order\Shipment
    {
        try {
            $shipment->addTrack($this->addShipmentTracking($flagship_shipment_id));
            $shipment->addComment('FlagShip Shipment Unconfirmed');
            $shipment->save();
            return $shipment;
        } catch (\Exception $e) {
            // $this->flagship->logError($e->getMessage());
            throw new \Magento\Framework\Exception\LocalizedException(__($e->getMessage()));
        }
    }

    protected function addShipmentTracking(int $flagship_shipment_id) : \Magento\Sales\Model\Order\Shipment\Track
    {
        $trackingData = [
            'carrier_code' => 'flagship',
            'title' =>  'FlagShip',
            'number' => 'Unconfirmed -' . $flagship_shipment_id,
            'description' => $flagship_shipment_id
        ];
        $tracking = $this->trackFactory->create()->addData($trackingData);
        return $tracking;
    }
}
