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
        \Flagship\Shipping\Block\Flagship $flagship,
        \Magento\Inventory\Model\GetSourceCodesBySkus $getSourceCodesBySkus,
        \Magento\InventorySourceDeductionApi\Model\GetSourceItemBySourceCodeAndSku $getSourceItemBySourceCodeAndSku,
        \Magento\Inventory\Model\SourceRepository $sourceRepository,
        \Magento\Sales\Model\Order\ShipmentRepository $shipmentRepository
    ){
        parent::__construct($context);
        $this->resource = $resource;
        $this->orderRepository = $orderRepository;
        $this->flagship = $flagship;
        $this->shipmentRepository = $shipmentRepository;
        $this->getSourceCodesBySkus = $getSourceCodesBySkus;
        $this->getSourceItemBySourceCodeAndSku = $getSourceItemBySourceCodeAndSku;
        $this->sourceRepository = $sourceRepository;

    }

    public function getPacking() : ?array {

        $flagship = new Flagship($this->flagship->getSettings()["token"],SMARTSHIP_API_URL,FLAGSHIP_MODULE,FLAGSHIP_MODULE_VERSION);

        try{
            if(is_null($this->getBoxes())){
                return NULL;
            }
            $order = $this->getOrder();

            $this->packingDetails = [];

            $orderItems = $this->getSourceCodesForOrderItems();

            foreach ($orderItems as $orderItem) {
                $sourceCode = $orderItem['source']->getSourceCode();
                $items = $orderItem['items'];
                $packings = $this->getPackingsFromFlagship($this->getPayload($items));

                $this->getPackingDetailsArray($packings,$sourceCode);
            }

            return $this->packingDetails;

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

    public function getSourceCodesForOrderItems() : array {
        $items = $this->getOrder()->getAllItems();
        $skus = null;
        $orderItems = [];
        foreach ($items as $item) {
            $skus = strcasecmp($item->getProductType(),'configurable') == 0 ? $item->getProductOptions()["simple_sku"] : $item->getProduct()->getSku();
            $sourceCode = $this->getSourceCodesBySkus->execute([$skus])[0];
            $orderItems[$sourceCode]['source'] = $this->sourceRepository->get($sourceCode);
            $orderItems[$sourceCode]['items'][] = $item;
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

    public function getItems($items) : array {

        $this->items = [];
        foreach ($items as $item) {
            $this->forComplexItem($item);
        }
        return $this->items;
    }

    public function isPackingEnabled() : int {
        return $this->flagship->getSettings()["packings"];
    }

    public function getPayload($items) : ?array {

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

    public function getPackingDetails() : array {
        $packings = $this->getPacking();
        if(array_key_exists("error",$packings)){
            return $packings["error"];
        }
        $packingDetails='';

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
        $flagship = new Flagship($this->flagship->getSettings()["token"],SMARTSHIP_API_URL,FLAGSHIP_MODULE,FLAGSHIP_MODULE_VERSION);

        try{
            $packingRequest = $flagship->packingRequest($payload);
            $packings = $packingRequest->execute();
            $this->flagship->logInfo("Retrieved packings from FlagShip. Response Code : ". $packingRequest->getResponseCode());
            return $packings;

        } catch( PackingException $e){
            $this->flagship->logError("Order #".$this->getOrder()->getId()." ".$e->getMessage().". Response Code : ".$packingRequest->getResponseCode());
        }

    }

    /*
     *@params \Magento\Sales\Model\Order\Item or \Magento\Quote\Model\Quote\Item
     */

    public function getItemsArray($item) : array
    {
        return [
                "length" => is_null($item->getProduct()->getDataByKey('length')) ? (is_null($item->getProduct()->getDataByKey('ts_dimensions_length')) ? 1 : $item->getProduct()->getDataByKey('ts_dimensions_length') ) : $item->getProduct()->getDataByKey('length'),

                "width" => is_null($item->getProduct()->getDataByKey('width')) ? is_null($item->getProduct()->getDataByKey('ts_dimensions_width')) ? 1 : $item->getProduct()->getDataByKey('ts_dimensions_width') : $item->getProduct()->getDataByKey('width'),

                "height" => is_null($item->getProduct()->getDataByKey('height')) ? is_null($item->getProduct()->getDataByKey('ts_dimensions_height')) ? 1 : $item->getProduct()->getDataByKey('ts_dimensions_height') : $item->getProduct()->getDataByKey('height'),

                "weight" => is_null($item->getProduct()->getWeight()) || $item->getProduct()->getWeight() < 1 ? 1 : $item->getProduct()->getWeight(),

                "description" => strcasecmp($item->getProductType(),'configurable') == 0 ? $item->getProductOptions()["simple_sku"].' - '.$item->getProductOptions()["simple_name"] : $item->getProduct()->getSku().' - '.$item->getProduct()->getName()
            ];
    }

    protected function getOrderItemsForSource($orderItem){
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

    protected function getPackingDetailsArray($packings,$sourceCode) : int {
        foreach ($packings as $packing) {
            $this->packingDetails[$sourceCode] = [
                "source_code" => $sourceCode,
                "box_model" => $packing->getBoxModel(),
                "items" => $packing->getItems()
            ];
        }
        return 0;
    }

    protected function getItemsforPayload(\Magento\Sales\Model\Order\Item $item) : ?array {

        $qty = $item->getQtyOrdered();
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
