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
            $packingRequest = $flagship->packingRequest($this->getPayload());
            $packings = $packingRequest->execute();

            $packingDetails = [];
             foreach ($packings as $packing) {
                $packingDetails[] = [
                    "box_model" => $packing->getBoxModel(),
                    "items" => $packing->getItems()
                ];
            }
            $this->flagship->logInfo("Retrieved packings from FlagShip. Response Code : ". $packingRequest->getResponseCode());
            return $packingDetails;

        } catch( PackingException $e){
            $this->flagship->logError("Order #".$this->getOrder()->getId()." ".$e->getMessage().". Response Code : ".$packingRequest->getResponseCode());
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
        $items = [];
        foreach ($orderItems as $item) {

            $items[] =[
                "length" => is_null($item->getProduct()->getDataByKey('length')) ? $item->getProduct()->getDataByKey('ts_dimensions_length') : $item->getProduct()->getDataByKey('length'),
                "width" => is_null($item->getProduct()->getDataByKey('width')) ? $item->getProduct()->getDataByKey('ts_dimensions_width') : $item->getProduct()->getDataByKey('width'),
                "height" => is_null($item->getProduct()->getDataByKey('height')) ? $item->getProduct()->getDataByKey('ts_dimensions_height') : $item->getProduct()->getDataByKey('height'),
                "weight" => $item->getProduct()->getWeight(),
                "description" => $item->getProduct()->getName()
            ];
        }
        return $items;
    }

    public function isPackingEnabled() : int {
        return !$this->flagship->getSettings()["packings"];
    }

    public function getPackingDetails() : string {
        $packings = $this->getPacking();
        if(array_key_exists("error",$packings)){
            return $packings["error"];
        }
        $packingDetails='';
        foreach ($packings as $packing) {
            $packingDetails .= 'Use Box Model : <b>'.$packing["box_model"].'</b> to pack <b>'.implode(",",$packing["items"]).'</b><br>';
        }
        return $packingDetails;
    }

    protected function getBoxesValue(array $result) : ?array {
        if(count($result) > 0){
            return $this->getBoxesArray($result);
        }
        return NULL;
    }

    protected function getPayload() : array {

        $payload = [
            "items" => $this->getItems(),
            "boxes" => $this->getBoxes(),
            "units" => $this->getUnits()
        ];
        return $payload;
    }

    protected function getUnits() : string {
        $units = $this->getOrder()->getStore()->getConfig('general/locale/weight_unit');
        if($units === 'kgs'){
            return 'metric';
        }
        return 'imperial';
    }

    protected function getOrder() : \Magento\Sales\Model\Order\Interceptor {
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
