<?php

namespace Flagship\Shipping\Block;

use \Flagship\Shipping\Flagship;
use \Flagship\Shipping\Exceptions\PackingException;

class DisplayPacking extends \Magento\Framework\View\Element\Template{

    protected $resource;
    protected $flagship;

    public function __construct(\Magento\Framework\View\Element\Template\Context $context, \Magento\Framework\App\ResourceConnection $resource, \Magento\Sales\Model\OrderRepository $orderRepository, \Flagship\Shipping\Block\Flagship $flagship){
        parent::__construct($context);
        $this->resource = $resource;
        $this->orderRepository = $orderRepository;
        $this->flagship = $flagship;
    }

    public function getPacking() : ?array {

        $flagship = new Flagship($this->flagship->getSettings()["token"],SMARTSHIP_API_URL,FLAGSHIP_MODULE,FLAGSHIP_MODULE_VERSION);

        try{
            if(is_null($this->getBoxes())){
                return NULL;
            }
            $packings = $this->getPackingsFromFlagship($this->getPayload());

            $packingDetails = [];
             foreach ($packings as $packing) {
                $packingDetails[] = [
                    "box_model" => $packing->getBoxModel(),
                    "items" => $packing->getItems()
                ];
            }
            return $packingDetails;

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

    public function getItems() : array {
        $order = $this->getOrder();
        $orderItems = $order->getAllItems();
        $this->items = [];
        foreach ($orderItems as $item) {
            $this->items = $this->getItemsforPayload($item);
        }
        $items = $this->items;
        return $items;
    }

    public function isPackingEnabled() : int {
        return $this->flagship->getSettings()["packings"];
    }

    public function getPayload() : ?array {

        if(is_null($this->getBoxes())){
            $this->flagship->logError("Packing Boxes not set");
            return NULL;
        }

        $payload = [
            "items" => $this->getItems(),
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
        $packingItemDescription = $packings[0]["items"][0];

        foreach ($packings as $packing) {

            $itemsCount = array_count_values($packing["items"]);

            $packingContent[] = [
                $packing["box_model"] => $this->getPackingList($itemsCount)
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

                "weight" => is_null($item->getProduct()->getWeight()) ? 1 : $item->getProduct()->getWeight(),

                "description" => $item->getProduct()->getSku().' - '.$item->getProduct()->getName()
            ];
    }

    protected function getItemsforPayload(\Magento\Sales\Model\Order\Item $item) : ?array {
        if(strcasecmp($item->getProductType(), 'configurable') == 0){
            return NULL;
        }
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
