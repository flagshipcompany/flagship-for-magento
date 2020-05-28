<?php

namespace Flagship\Shipping\Block;

use \Flagship\Shipping\Flagship;
use \Flagship\Shipping\Exceptions\PackingException;

class DisplayPacking extends \Magento\Framework\View\Element\Template{

    protected $resource;
    protected $flagship;

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Framework\App\ResourceConnection $resource,
        \Magento\Sales\Model\OrderRepository $orderRepository,
        \Flagship\Shipping\Helper\Flagship $flagship,
        \Magento\Inventory\Model\GetSourceCodesBySkus $getSourceCodesBySkus,
        \Magento\InventorySourceDeductionApi\Model\GetSourceItemBySourceCodeAndSku $getSourceItemBySourceCodeAndSku,
        \Magento\Inventory\Model\SourceRepository $sourceRepository,
        \Magento\Sales\Model\Order\ShipmentRepository $shipmentRepository,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\Message\ManagerInterface $messageManager,
        \Magento\InventoryShipping\Model\ResourceModel\ShipmentSource\GetSourceCodeByShipmentId $getSourceCodeByShipmentId,
        \Magento\Catalog\Model\ProductRepository $productRepository
    ){
        parent::__construct($context);
        $this->resource = $resource;
        $this->orderRepository = $orderRepository;
        $this->flagship = $flagship;
        $this->shipmentRepository = $shipmentRepository;
        $this->getSourceCodesBySkus = $getSourceCodesBySkus;
        $this->getSourceItemBySourceCodeAndSku = $getSourceItemBySourceCodeAndSku;
        $this->sourceRepository = $sourceRepository;
        $this->messageManager = $messageManager;
        $this->scopeConfig = $scopeConfig;
        $this->getSourceCodeByShipmentId = $getSourceCodeByShipmentId;
        $this->productRepository = $productRepository;
    }

    public function getPacking(int $shipmentId=0) : ?array {

        try{
            if(is_null($this->getBoxes())){
                return NULL;
            }
            $order = $this->getOrder();

            $this->packingDetails = [];
            $this->shipAsIsProducts = [];
            $orderItems = $shipmentId != 0 ? $this->getSourceCodesForShipmentItems($shipmentId) : $this->getSourceCodesForOrderItems();

            foreach ($orderItems as $key => $orderItem) {
                $sourceCode = $key;  //$orderItem['source']->getSourceCode();
                $items = $orderItem['items'];
                $packings = $this->getPackingsFromFlagship($this->getPayload($items));

                $this->getPackingDetailsArray($packings,$sourceCode);
            }

            return [
                "packingDetails" => $this->packingDetails,
                "shipAsIs" => $this->shipAsIsProducts
            ];

        } catch( \Exception $e){
            $packingDetails = [
                "error" => $e->getMessage()
            ];
            return $packingDetails;
        }
    }

    public function getBoxes() : ?array  {
        $connection = $this->resource->getConnection();
        $tableName = $this->resource->getTableName('flagship_boxes');
        try{
            $sql = $connection->select()->from(
                ["table" => $tableName]
            );
            $result = $connection->fetchAll($sql);
            $this->flagship->logInfo("Retrieved boxes from databases");
            if(count($result) > 0){
                return $this->getBoxesValue($result);
            }
            return NULL;
        } catch (\Exception $e){
            $this->flagship->logError("Order #".$this->getOrder()->getId()." ".$e->getMessage());
        }
    }

    public function getSourceCodesForOrderItems() : array { //revisit
        $order = $this->getOrder();
        $orderItems = [];
        $items = $order->getAllItems();

        foreach ($items as $item) {
            $skus = strcasecmp($item->getProductType(),'configurable') == 0 ? $item->getProductOptions()["simple_sku"] : $item->getProduct()->getSku();
            $sourceCode = $this->getSourceCodesBySkus->execute([$skus])[0];
            $orderItems[$sourceCode]['source'] = $this->sourceRepository->get($sourceCode);
            if($item->getProductType() != 'configurable') $orderItems[$sourceCode]['items'][] = $item;
        }
        return $orderItems;
    }

    public function getItemsForPrepareShipment() : array {

        $orderItems = $this->getSourceCodesForOrderItems();

        $this->items = [];

        foreach ($orderItems as $orderItem) {
            $this->getOrderItemsForSource($orderItem);
        }

        return $this->items;
    }

    public function getItems(array $items) : array {

        $this->items = [];
        foreach ($items as $item) {
            $sku = $item->getSku();
            $product = $this->productRepository->get($sku);
            $productsShippingAsIs = $product->getDataByKey('ship_as_is') == 1 ? $this->addToShipAsIsProducts($item) : $this->forComplexItem($item);
        }

        return $this->items;
    }

    protected function getSourceCodesForShipmentItems($shipmentId){
        $shipment = $this->shipmentRepository->get($shipmentId);
        $items = $shipment->getAllItems();
        $shipmentItems = [];

        $sourceCode = $this->getSourceCodeByShipmentId->execute($shipmentId);

        foreach ($items as $item) {
            $orderItem = $item->getOrderItem();
            $shipmentItems[$sourceCode]['source'] = $this->sourceRepository->get($sourceCode);

            if($orderItem->getProductType() != 'configurable') $shipmentItems[$sourceCode]['items'][] = $orderItem;
        }

        return $shipmentItems;
    }

    protected function addToShipAsIsProducts($item){
        $sku = $item->getSku();
        $product = $this->productRepository->get($sku);
        $this->shipAsIsProducts[] = $product->getName();
    }

    public function isPackingEnabled() : int {
        return $this->flagship->getSettings()["packings"];
    }

    public function getPayload(array $items) : ?array {

        if(is_null($this->getBoxes())){
            $this->flagship->logError("Packing Boxes not set");
            return NULL;
        }

        $payload = [
            "items" => $this->getItems($items),
            "boxes" => $this->getBoxes(),
            "units" => $this->getUnits()
        ];

        return $payload;
    }

    public function getPackingDetails(int $shipmentId = 0) : array {
        $packingContent = [];
        $packings = $this->getPacking($shipmentId)['packingDetails'];
        if(array_key_exists("error",$packings)){
            return $packings["error"];
        }

        foreach ($packings as $packing) {
            $itemsCount = array_count_values($packing["items"]);

            $packingContent[] = [
                'source_code' => $packing["source_code"],
                'detail' => [ $packing["box_model"] => $this->getPackingList($itemsCount) ]
            ];
        }
        return $packingContent;
    }

    public function getPackingsFromFlagship(?array $payload) : ?\Flagship\Shipping\Collections\PackingCollection
    {
        if(is_null($payload)){
            $this->flagship->logError("Payload is NULL. Payload returned is - ".json_encode($payload));
            return NULL;
        }
        $this->flagship->logInfo("Packings payload: ".json_encode($payload));
        
        $flagship = new Flagship($this->flagship->getSettings()["token"],SMARTSHIP_API_URL,FLAGSHIP_MODULE,FLAGSHIP_MODULE_VERSION);

        try{
            $packingRequest = $flagship->packingRequest($payload)->setStoreName('Magento - '.$this->scopeConfig->getValue('general/store_information/name'));

            $packings = $packingRequest->execute();
            $this->flagship->logInfo("Retrieved packings from FlagShip. Response Code : ". $packingRequest->getResponseCode());
            return $packings;

        } catch( PackingException $e){
            $this->flagship->logError("Order #".$this->getOrder()->getId()." ".$e->getMessage().". Response Code : ".$packingRequest->getResponseCode());
            return null;
        }

    }

    /*
     *@params \Magento\Sales\Model\Order\Item or \Magento\Quote\Model\Quote\Item or \Magento\Sales\Model\Order\Shipment\Item
     */

     public function getItemsArray($item) : array
     {
        $sku = $item->getSku();

        $product = $this->productRepository->get($sku);

        $length = is_null($product->getDataByKey('length')) ? (is_null($product->getDataByKey('ts_dimensions_length')) ? 1 : intval($product->getDataByKey('ts_dimensions_length')) ) : intval($product->getDataByKey('length'));

        $width = is_null($product->getDataByKey('width')) ? is_null($product->getDataByKey('ts_dimensions_width')) ? 1 : intval($product->getDataByKey('ts_dimensions_width')) : intval($product->getDataByKey('width'));

        $height = is_null($product->getDataByKey('height')) ? is_null($product->getDataByKey('ts_dimensions_height')) ? 1 : intval($product->getDataByKey('ts_dimensions_height')) : intval($product->getDataByKey('height'));

        return [
                "length" => $length <= 0 ? 1 : $length,

                "width" => $width <= 0 ? 1 : $width,

                "height" => $height <= 0 ? 1 : $height,

                "weight" => is_null($product->getWeight()) || $product->getWeight() < 1 ? 1 : $product->getWeight(),

                "description" => strcasecmp($item->getProductType(),'configurable') == 0 ? $item->getProductOptions()["simple_sku"].' - '.$item->getProductOptions()["simple_name"] : $product->getSku().' - '.$product->getName()
            ];
     }


    protected function getOrderItemsForSource(array $orderItem) : int {
        foreach ($orderItem['items'] as $value) {
            $this->forComplexItem($value);
        }
        return 0;
    }

    protected function forComplexItem($item) : array {
        if($item->getProductType() == 'bundle'){
            $children = $item->getChildrenItems();
            foreach ($children as $child) {
                $this->items = $this->getItemsforPayload($child);
            }
            return $this->items;
        }
        $this->items = $this->getItemsforPayload($item);
        return $this->items;

    }

    protected function getPackingDetailsArray(?\Flagship\Shipping\Collections\PackingCollection $packings,string $sourceCode) : array {
        if($packings == NULL){
            return [];
        }
        foreach ($packings as $packing) {
            $this->packingDetails[] = [
                "source_code" => $sourceCode,
                "box_model" => $packing->getBoxModel(),
                "items" => $packing->getItems(),
                "dimensions" => $packing->getLength().'x'.$packing->getWidth().'x'.$packing->getHeight(),
                "weight" => $packing->getWeight()
            ];
        }

        return $this->packingDetails;
    }

    /*
        @params \Magento\Sales\Model\Order\Item or \Magento\Sales\Model\Order\Shipment\Item
     */
    protected function getItemsforPayload($item) : ?array {

        $qty = $item->getQtyOrdered(); //getQtyShipped
        $itemsArray = $this->getItemsArray($item);
        for ($i = 0; $i < $qty ; $i++) {
            $this->items[] = $itemsArray;
        }

        return $this->items;
    }


    protected function getPackingList(array $itemsCount) : array {
        $packingContent = [];
        foreach ($itemsCount as $key => $value) {
            $packingContent[$key] = $value;
        }
        return $packingContent;
    }

    protected function getBoxesValue(array $result) : ?array {
        if(count($result) > 0){
            return $this->getBoxesArray($result);
        }
        return NULL;
    }

    protected function getUnits() : string {
        $units = $this->getOrder()->getStore()->getConfig('general/locale/weight_unit');
        if($units === 'kgs'){
            return 'metric';
        }
        return 'imperial';
    }

    protected function getOrder() : \Magento\Sales\Model\Order {
        $orderId = $this->getRequest()->getParam('order_id');
        $order = $this->orderRepository->get($orderId);
        return $order;
    }

    protected function getBoxesArray(array $result) : array {
        $boxes = [];
        foreach ($result as $row) {
            $boxes[] = [
                "box_model" => $row["box_model"],
                "length"    => $row["length"],
                "width"     => $row["width"],
                "height"    => $row["height"],
                "weight"    => $row["weight"],
                "max_weight"    =>  $row["max_weight"]
            ];
        }
        return $boxes;
    }
}
