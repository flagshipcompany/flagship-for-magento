<?php

declare(strict_types=1);

namespace Flagship\Shipping\Model\Carrier;

use Flagship\Shipping\Exceptions\AvailableServicesException;
use Flagship\Shipping\Exceptions\GetShipmentListException;
use Flagship\Shipping\Exceptions\QuoteException;
use Flagship\Shipping\Flagship;
use Flagship\Shipping\Model\Configuration;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\Method;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Rate\ResultFactory;
use Psr\Log\LoggerInterface;

class FlagshipQuote extends AbstractCarrier implements CarrierInterface
{
    const SHIPPING_CODE = 'flagship';
    protected $_code = self::SHIPPING_CODE;
    protected $_isFixed = true;

    private ResultFactory $rateResultFactory;

    private MethodFactory $rateMethodFactory;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        ResultFactory $rateResultFactory,
        MethodFactory $rateMethodFactory,
        Configuration $config,
        array $data = []
    ) {
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);

        $this->rateResultFactory = $rateResultFactory;
        $this->rateMethodFactory = $rateMethodFactory;
    }

    public function isShippingLabelsAvailable() : bool
    {
        return true;
    }

    public function isTrackingAvailable() : bool
    {
        return true;
    }

    public function getTracking(string $tracking) : \Magento\Shipping\Model\Tracking\Result
    {
        $result = $this->_trackFactory->create();
        $status = $this->_trackStatusFactory->create();
        $status->setCarrier($this->getCarrierCode());

        if (stristr($tracking, 'Unconfirmed') !== false) {
            $shipmentId = substr($tracking, strpos($tracking, '-')+1);
            $orderId = $this->getOrderId($shipmentId);
            $url = $this->url->getUrl('shipping/convertShipment', ['shipmentId'=> $shipmentId, 'order_id' => $orderId]);
            $status->setCarrierTitle('Your FlagShip shipment is still Unconfirmed');
        }
        if (stristr($tracking, 'Unconfirmed') === false
            && stristr($tracking, 'Free Shipping') == false
            && stristr($tracking, 'Consolidated Weekly Shipping') == false
            && $this->getShipmentFromFlagship($tracking) != NULL
        ) { //shipment confirmed
            $shipment = $this->getShipmentFromFlagship($tracking);
            $status->setCarrierTitle($shipment->getCourierDescription());
            $courierName = $shipment->getCourierName();
            $trackingNumber = $shipment->getTrackingNumber();
            $url = $this->getTrackingUrl($courierName, $trackingNumber);
        }

        if ( $this->getShipmentFromFlagship($tracking) == NULL || (stristr($tracking, 'Unconfirmed') === false
            && (
                stristr($tracking, 'Free Shipping') !== false
                || stristr($tracking, 'Consolidated Weekly Shipping') !== false
            ) )
        ) {
            $url = 'https://www.flagshipcompany.com';
            $tracking = "Tracking is not available for this shipment. Please check with FlagShip";
        }
        $status->setTracking($tracking);
        $status->setUrl($url);
        $result->append($status);
        return $result;
    }

    public function proccessAdditionalValidation(\Magento\Framework\DataObject $request) : bool
    {
        return $this->processAdditionalValidation($request);
    }

    public function processAdditionalValidation(\Magento\Framework\DataObject $request) : bool
    {
        return true;
    }

    public function allowedMethods() : array
    {
        if (empty($this->getToken())) {
            return [];
        }
        $token = $this->getToken();
        $flagship = new Flagship($token, SMARTSHIP_API_URL, FLAGSHIP_MODULE, FLAGSHIP_MODULE_VERSION);
        $storeName = $this->_scopeConfig->getValue('general/store_information/name');
        $storeName = $storeName == null ? '' : $storeName;

        try {
            $availableServices = $flagship->availableServicesRequest()->setStoreName($storeName);
            $services = $availableServices->execute();
            // $this->flagship->logInfo("Retrieved available services from FlagShip. Response Code : " . $availableServices->getResponseCode());
            return $this->getAllowedMethodsArray($services);
        } catch (AvailableServicesException $e) {
            return [];
            // $this->flagship->logError($e->getMessage());
        }
    }

    public function getAllowedMethods() : array
    {
        $allowed = explode(',', $this->getConfiguration('allowed_methods'));
        $methods = [];
        foreach ($allowed as $value) {
            $methods[$value] = $value;
        }
        return $methods;
    }

    public function collectRates(RateRequest $request) : \Magento\Shipping\Model\Rate\Result
    {
        $orderItems = [];
        $cartItems = $this->cart->getQuote()->getAllVisibleItems();
        $quotes = [];
        $boxesTotal = [];

        foreach ($cartItems as $item) {
            $prodId = $item->getProductType() == 'configurable' ? $item->getOptionByCode('simple_product')->getProduct()->getId() : $item->getProduct()->getId();
            $sku = $this->productRepository->getById($prodId)->getSku();
            $productQtyPerSource = $this->getQuantityInformationPerSource->execute($sku);
            $sourceCodes = $this->getSourceCodesForCartItems($productQtyPerSource, $item, $sku);
            $sourceCode = $this->getOptimumSource($sourceCodes, $request, $item);
            $orderItems[$sourceCode] = $this->skipIfItemIsDownloadable($orderItems, $sourceCode, $item);
        }

        // $ltlFlagArray = [];
        $dataArray = [];
        foreach ($orderItems as $orderItem) { //key is sku - source code
            $dataArray = $this->getDataForQuote($orderItem, $request, $dataArray);

            // $ltlFlagArray = array_key_exists('ltlFlagArray', $dataArray) ? $dataArray['ltlFlagArray'] : [];
            $boxesTotal = array_key_exists('boxesTotal', $dataArray) ? $dataArray['boxesTotal'] : [];
            $quotes = array_key_exists('quotes', $dataArray) ? $dataArray['quotes'] : [];
        }

        $rates = [];

        foreach ($quotes as $quote) {
            $rates = $this->getRates($quote, $rates);
        }
        // $ltlFlag = in_array(1, $ltlFlagArray);
        // if ($ltlFlag) {
        //     return $this->rateForLtl($ltlFlag);
        // }

        return $this->getRatesResult($rates, $boxesTotal);
    }

    /*
     *  type for @param item can vary \Magento\Quote\Model\Quote\Item
     */
    public function getOptimumSource(array $sourceCodes, RateRequest $request=null, $item, $destinationAddress = null)
    {
        $sourceCode = $sourceCodes[0];
        $quotes = [];
        if (count($sourceCodes) > 1) {
            $quotes = $this->getQuotesForAllSources($sourceCodes, $item, $request, $destinationAddress);
            $sourceCode = $this->findCheapestSource($quotes);
        }

        return $sourceCode;
    }

    protected function getDataForQuote(array $orderItem, RateRequest $request, array $dataArray)
    {
        if (array_key_exists('items', $orderItem)) {
            $payload = $this->getPayload( $orderItem["source"], $orderItem["items"],$request);
            $sourceCode = $orderItem["source"]->getSourceCode();
            $dataArray['ltlFlagArray'][]  = $this->checkPayloadForLtl($payload);
            $dataArray['boxesTotal'][] = $this->getBoxesTotalFromPayload($payload);
            $dataArray['quotes'][$sourceCode] = $this->getQuotes($payload);
        }
        return $dataArray;
    }

    /*
     *  type for @param item can vary \Magento\Quote\Model\Quote\Item
     */
    protected function skipIfItemIsDownloadable(array $orderItems, string $sourceCode, $item)
    {
        $orderItems[$sourceCode]['source'] = $this->sourceRepository->get($sourceCode);
        if ($item->getProductType() != 'downloadable') {
            $orderItems[$sourceCode]['items'][] = $item;
        }
        return $orderItems[$sourceCode];
    }

    protected function getBoxesTotalFromPayload(array $payload)
    {
        $items = $payload["packages"]["items"];
        $boxes = $this->packing->getBoxesWithPrices();
        $total = 0.00;
        foreach ($boxes as $box) {
            $boxesPrices[$box["box_model"]] = $box["price"];
        }
        $boxesNames = array_keys($boxesPrices);
        foreach ($items as $item) {
            $total = in_array($item["description"], $boxesNames) ? $total + $boxesPrices[$item["description"]] : $total;
        }
        return $total;
    }

    /*
     *  type for @param item can vary
     */
    protected function getQuotesForAllSources(array $sourceCodes, $item, $request = null, array $destinationAddress = null)
    {
        $prodId = $item->getProductType() == 'configurable' ? $item->getOptionByCode('simple_product')->getProduct()->getId() : $item->getProduct()->getId();
        $sku = $this->productRepository->getById($prodId)->getSku();
        foreach ($sourceCodes as $value) {
            $source = $this->sourceRepository->get($value);
            $payload = $this->getPayload($source, [$item],$request, $destinationAddress);
            $quotes[$sku][$value] = $this->getQuotes($payload);
        }
        return $quotes;
    }

    protected function getSourceCodesForCartItems(array $productQtyPerSource, $item, string $sku)
    {
        $sourceCodes = [];
        foreach ($productQtyPerSource as $value) {
            $sourceCodes = $this->getSourceCodesForItem($value, $item, $sourceCodes);
        }
        if (count($sourceCodes) < 1) {
            $sourceCodes = $this->getSourceCodesBySkus->execute([$sku]);
        }
        // If there are no sources, return the default source. This is useful for test stores or products not properly configured.
        if (count($sourceCodes) === 0) {
            $sourceCodes = ['default'];
        }

        return $sourceCodes;
    }

    protected function getSourceCodesForItem(array $value, $item, array $sourceCodes)
    {
        if ($value['status'] == 1 && $value['quantity_per_source'] > $item->getQty()) {
            $sourceCodes[] = $value['source_code'];
        }
        return $sourceCodes;
    }

    protected function findCheapestSource(array $quotes)
    {
        $finalQuotes = [];
        $sourceCode = '';
        foreach ($quotes as $quote) {
            //cheapest source selection
            $cheapest = 99999.99;
            $cheapestQuote = $this->getCheapestQuote($quote, $cheapest);        
            $finalQuotes[] = $cheapestQuote['quote'];
            $sourceCode = $cheapestQuote['sourceCode'];
        }      
        return $sourceCode;
    }

    protected function getCheapestQuote(array $quote, float $cheapest)
    {
        $cheapestArray = [];
        foreach ($quote as $key => $value) { //key is source
            $finalCheapestQuote = $this->getFinalCheapestQuote($key, $value, $cheapest);
            $cheapestArray = count($finalCheapestQuote) > 0 ? $finalCheapestQuote : $cheapestArray;
            $cheapest = array_key_exists('cheapestTotal', $cheapestArray) ? $cheapestArray['cheapestTotal'] : 99999.99;
        }
        return $cheapestArray;
    }

    protected function getFinalCheapestQuote(string $key, \Flagship\Shipping\Collections\RatesCollection $value, float $cheapest)
    {
        $cheapestArray = [];
        if ($value->getCheapest()->getTotal() <= $cheapest) {
            $cheapest = $value->getCheapest()->getTotal();         
            $cheapestArray = [
                'cheapestTotal' => $cheapest,
                'quote' => $value,
                'sourceCode' => $key
            ];
        }     
        return $cheapestArray;
    }

    protected function getRates(\Flagship\Shipping\Collections\RatesCollection $quotes, array $rates) : array
    {
        foreach ($quotes as $quote) {
            $rates[$quote->rate->service->courier_name . ' - ' . $quote->rate->service->courier_desc]['total'][] = $quote->getTotal();
            $rates[$quote->rate->service->courier_name . ' - ' . $quote->rate->service->courier_desc]['subtotal'][] = $quote->getSubtotal();
            $rates[$quote->rate->service->courier_name . ' - ' . $quote->rate->service->courier_desc]['taxesTotal'][] = $quote->gettaxesTotal();
            $courierName = $quote->rate->service->courier_name === 'FedEx' ? $quote->rate->service->courier_name . ' ' . $quote->rate->service->courier_desc : $quote->rate->service->courier_desc;
            $carrier = in_array($courierName, $this->getAllowedMethods()) ? self::SHIPPING_CODE : $quote->rate->service->courier_desc;
            // $methodTitle = substr($courierName, strpos($courierName,' '));
            $rates[$quote->rate->service->courier_name . ' - ' . $quote->rate->service->courier_desc]['details'] = [
                'carrier' => $carrier,
                'carrier_title' => $quote->rate->service->courier_name,
                'method' => $quote->rate->service->courier_code,
                'method_title' => $courierName,
                'estimated_delivery_date' => $quote->rate->service->estimated_delivery_date,
                'subtotal' => $quote->rate->price->subtotal,
                'total' => $quote->rate->price->total
            ];
        }
        return $rates;
    }

    protected function getRatesArray(array $payload, array $rates) : array
    {
        $quotes = $this->getQuotes($payload);
        $rates = $this->getRates($quotes, $rates);
        return $rates;
    }

    protected function getRatesResult(array $rates, array $boxesTotal) : \Magento\Shipping\Model\Rate\Result
    {
        $result = $this->_rateFactory->create();
        try {
            foreach ($rates as $rate) {
                $result->append($this->prepareShippingMethods($rate, $boxesTotal));
            }
            $this->appendFreeShippingMethod($result);
            $this->appendWeeklyShippingMethod($result);

            return $result;
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $error = $this->_rateErrorFactory->create();
            $error->setCarrier($this->getCarrierCode());
            $error->setErrorMessage($e->getMessage());
            $result->append($error);
            return $result;
        }
    }

    protected function getAllowedMethodsArray(\Flagship\Shipping\Collections\AvailableServicesCollection $services) : array
    {
        foreach ($services as $service) {
            $methods[] = [
                'value' => $service->getDescription(), //$service->getFlagshipCode()
                'label' =>  __($service->getDescription())
            ];
        }
        return $methods;
    }

    protected function getOrderId(string $shipmentId) : ?string
    {
        $shipments = $this->shipmentCollection->create()->addFieldToFilter('flagship_shipment_id', $shipmentId);
        foreach ($shipments as $value) {
            $shipment = $value;
        }
        $orderId = $shipment->getOrder()->getId();
        return $orderId;
    }

    protected function prepareShippingMethods(array $rate, array $boxesTotal) : \Magento\Quote\Model\Quote\Address\RateResult\Method
    {
        $method = $this->_rateMethodFactory->create();
        $method->setCarrier($rate['details']['carrier']);
        $method->setCarrierTitle($rate['details']['carrier_title']);
        $methodTitle = $rate['details']['method_title'];
        $method->setMethod($rate['details']['method']);
        if ($this->getConfiguration('delivery_date')) {
            $methodTitle .= ' (Estimated delivery date: ' . $rate['details']['estimated_delivery_date'] . ')';
        }
        $method->setMethodTitle($methodTitle);
        $amount = array_sum($rate['subtotal']);
        $markup = $this->getConfiguration('markup');
        $flatFee = $this->getConfiguration('flat_fee');
        $addTaxes = $this->getConfiguration('taxes');
        if ($markup > 0) {
            $amount += ($markup/100)*$amount;
        }
        if ($flatFee > 0) {
            $amount += $flatFee;
        }
        if ($addTaxes) {
            $shipmentTax = array_sum($rate['taxesTotal']);
            $amount += $shipmentTax;
        }
        
        $amount += array_sum($boxesTotal);

        $method->setPrice($amount);
        $method->setCost($amount);
        // $this->flagship->logInfo('Prepared rate for ' . $methodTitle);
        return $method;
    }

    protected function getToken() : ?string
    {
        return null;
        try {
            $token = isset($this->flagship->getSettings()["token"]) ? $this->flagship->getSettings()["token"] : null;
            return $token;
        } catch (\Exception $e) {
            // $this->flagship->logError($e->getMessage());
        }
    }

    protected function getSenderAddress(\Magento\Inventory\Model\Source $source) : array
    {
        $from_city = $source->getCity();
        $from_country = $source->getCountryId();
        $from_state =  $this->getState($source->getRegionId());
        $from_postcode = $source->getPostcode();
        if ($source->getPostcode() == '00000') {
            $from_city = $this->_scopeConfig->getValue('general/store_information/city', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
            $from_country = $this->_scopeConfig->getValue('general/store_information/country_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
            $from_state = $this->getState($this->_scopeConfig->getValue('general/store_information/region_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE));
            $from_postcode = $this->_scopeConfig->getValue('general/store_information/postcode', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
        }
        $from = [
            "city"  => substr($from_city, 0, 29),
            "country"   => $from_country,
            "state" => $from_state,
            "postal_code"   => $from_postcode,
            "is_commercial" => true
        ];
        return $from;
    }

    protected function getReceiverAddress(?RateRequest $request, array $destinationAddress = null) : array
    {
        if ($request == null && $destinationAddress != null) {
            $to = [
                "city" => substr($destinationAddress['city'], 0, 29),
                "country"=> $destinationAddress['country'],
                "state"=> $destinationAddress['state'],
                "postal_code"=> $destinationAddress['postal_code'],
                "is_commercial"=>false
            ];
            $to = $this->setResidentialFlag($to);
            return $to;
        }

        $toCity = empty($request->getDestCity()) ? 'Toronto' : $request->getDestCity();
        $to = [
            "city" => substr($toCity, 0, 29),
            "country"=> $request->getDestCountryId(),
            "state"=> $request->getDestRegionCode(),
            "postal_code"=> $request->getDestPostcode(),
            "is_commercial"=>false
        ];
        $to = $this->setResidentialFlag($to);
        return $to;
    }

    protected function setResidentialFlag(array $to) : array
    {
        $residentialFlag = $this->getConfiguration('residential');
        if (!$residentialFlag) {
            $to["is_commercial"] = true;
        }
        return $to;
    }

    protected function getPayload(\Magento\Inventory\Model\Source $source, array $items, ?RateRequest $request = null, array $destinationAddress = null) : array
    {
        $insurance  = $this->getConfiguration('insurance');
        $from = $this->getSenderAddress($source);
        $to = $this->getReceiverAddress($request, $destinationAddress);

        $packages = [
            "items" => $this->getItemsArray($items),
            "units" => $this->getUnits(),
            "type" => "package",
            "content" => "goods"
        ];

        $payment = [
            "payer"=> "F"
        ];
        $options = [
            "address_correction" => true
        ];
        $insuranceValue = $this->getInsuranceAmount();
        if ($insurance && $insuranceValue > 0) {
            $options["insurance"] = [
                "value" => $insuranceValue,
                "description"   => "Insurance"
            ];
        }
        $packages = $this->addShipAsIsItemsToPayload($items, $packages);

        $payload = [
            "from"  => $from,
            "to"    => $to,
            "packages"  => $packages,
            "payment"   =>  $payment,
            "options"   => $options
        ];

        return $payload;
    }

    protected function getInsuranceAmount() : float
    {
        $total = $this->cart->getQuote()->getSubtotal() > 100 ? $this->cart->getQuote()->getSubtotal() : 0;
        return $total;
    }

    protected function getState(?string $regionId) : ?string
    {
        if (is_null($regionId)) {
            // $this->flagship->logError("Region not set");
            return null;
        }
        $shipperRegion = $this->_regionFactory->create()->load($regionId);
        $shipperRegionCode =$shipperRegion->getCode();
        return $shipperRegionCode;
    }

    protected function getUnits() : string
    {
        // We only use imperial units because Magento doesn't automatically convert the products' details.
        // $weightUnit = $this->_scopeConfig->getValue('general/locale/weight_unit', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
        // if ($weightUnit === 'kgs') {
        //     return 'metric';
        // }
        return 'imperial';
    }

    protected function getPayloadForPacking(array $items) : ?array
    {
        $cartItems = $items;
        $this->items = [];
        foreach ($cartItems as $item) {
            $prodId = $item->getProductType() == 'configurable' ? $item->getOptionByCode('simple_product')->getProduct()->getId() : $item->getProduct()->getId();
            $product = $this->productRepository->getById($prodId);
            if ($product->getDataByKey('ship_as_is') == 1) {
                continue;
            }

            $weight = empty($product->getWeight()) ? 1 : $product->getWeight();
            $temp = $this->packing->getItemsArray($item);

            $temp["weight"] = $weight;

            $this->getPayloadItems($temp, $item);
        }
        if (is_null($this->packing->getBoxes())) {
            // $this->flagship->logError("Packing Boxes not set");
            return null;
        }
        $payload = [
            "items" => $this->items,
            "boxes" => $this->packing->getBoxes(),
            "units" => $this->getUnits()
        ];

        return $payload;
    }

    protected function getPayloadItems(array $temp, $item) : array
    {
        for ($i=0;$i<$item->getQty() || $i<$item->getQtyOrdered();$i++) {
            $this->items[] = $temp;
        }
        return $this->items;
    }

    protected function getItemsArray(array $items) : array
    {

        $returnItems = [];
        $flag = 1; //no ship_as_is items in cart

        foreach ($items as $item) {
            $prodId = $item->getProductType() == 'configurable' ? $item->getOptionByCode('simple_product')->getProduct()->getId() : $item->getProduct()->getId();
            $product = $this->productRepository->getById($prodId);
            $flag = $product->getDataByKey('ship_as_is') == 0 ? 1 : 0 ;
        }

        if($flag == 0){ //only ship_as_is products in cart
            return [];
        }

        $boxes = $this->packing->getBoxes();

        if ($boxes == null) {
            $this->getPayloadForPacking($items);
            return $this->items;
        }

        $packings = $this->packing->getPackingsFromFlagship($this->getPayloadForPacking($items));

        if ($packings ==  null) {
            return [
                [
                    'width' => 1,
                    'height' => 1,
                    'length' => 1,
                    'weight' => 1.00,
                    'description' => 'Box unknown'
                ]
            ];
        }

        foreach ($packings as $packing) {
            $temp = [
                'width' => intval($packing->getWidth()),
                'height' => intval($packing->getHeight()),
                'length' => intval($packing->getLength()),
                'weight' => $packing->getWeight() < 1 ? 1 : $packing->getWeight(),
                'description' => $packing->getBoxModel()
            ];
            $returnItems[] = $temp;
        }

        return $returnItems;
    }

    protected function getTrackingUrl(string $courierName, string $trackingNumber)
    {
        switch ($courierName) {
            case 'ups':
                $url = 'http://wwwapps.ups.com/WebTracking/track?HTMLVersion=5.0&loc=en_CA&Requester=UPSHome&trackNums=' . $trackingNumber . '&track.x=Track';
                break;
            case 'dhl':
                $url = 'http://www.dhl.com/en/express/tracking.html?AWB=' . $trackingNumber . '&brand=DHL';
                break;
            case 'fedex':
                $url = 'http://www.fedex.com/Tracking?ascend_header=1&clienttype=dotcomreg&track=y&cntry_code=ca_english&language=english&tracknumbers=' . $trackingNumber . '&action=1&language=null&cntry_code=ca_english';
                break;
            case 'purolator':
                $url = 'https://eshiponline.purolator.com/ShipOnline/Public/Track/TrackingDetails.aspx?pup=Y&pin=' . $trackingNumber . '&lang=E';
                break;
            case 'canpar':
                $url = 'https://www.canpar.com/en/track/TrackingAction.do?reference=' . $trackingNumber . '&locale=en';
                break;
            case 'dicom':
                $url = 'https://www.dicom.com/en/express/tracking/load-tracking/' . $trackingNumber . '?division=DicomExpress';
                break;
            default:
                $url = SMARTSHIP_WEB_URL;
                break;
        }
        return $url;
    }

    protected function getQuotes(array $payload) : \Flagship\Shipping\Collections\RatesCollection
    {
        $token = $this->getToken();
        $storeName = $this->_scopeConfig->getValue('general/store_information/name');
        $storeName = $storeName == null ? '' : $storeName;

        $flagship = new Flagship($token, SMARTSHIP_API_URL, FLAGSHIP_MODULE, FLAGSHIP_MODULE_VERSION);
        // $this->flagship->logInfo("Quotes payload sent to FlagShip: ".json_encode($payload));
        $quoteRequest = $flagship->createQuoteRequest($payload)->setStoreName($storeName);
        try {
            $quotes = $quoteRequest->execute();
            // $this->flagship->logInfo("Retrieved quotes from FlagShip. Response Code : " . $quoteRequest->getResponseCode());
            return $quotes;
        } catch (QuoteException $e) {
            // $this->flagship->logError($e->getMessage());
            throw new \Magento\Framework\Exception\LocalizedException(__($e->getMessage()));
        }
    }

    protected function getShipmentFromFlagship(string $trackingNumber) : ?\Flagship\Shipping\Objects\Shipment
    {
        $token = $this->getToken();
        $storeName = $this->_scopeConfig->getValue('general/store_information/name');
        $storeName = $storeName == null ? '' : $storeName;

        $flagship = new Flagship($token, SMARTSHIP_API_URL, FLAGSHIP_MODULE, FLAGSHIP_MODULE_VERSION);
        $shipmentsList = $flagship->getShipmentListRequest()->setStoreName($storeName);
        try {
            $shipments = $shipmentsList->execute();
            $shipment = $shipments->getByTrackingNumber($trackingNumber);
            // $this->flagship->logInfo("Retrieved shipment from FlagShip. Response Code : " . $shipmentsList->getResponseCode());
            return $shipment;
        } catch (GetShipmentListException $e) {
            // $this->flagship->logError($e->getMessage());
            return NULL;
        }
    }

    protected function getConfiguration(string $key) : ?string
    {
        $configData = [
            'isEnabled' => $this->config->isEnabled(),
            'token' => $this->config->getToken(),
            'insurance' => $this->config->getInsurance(),
            'residential' => $this->config->getResidential(),
            'packing' => $this->config->isPackingEnabled(),
            'boxes' => $this->config->getBoxes(),
            'taxes' => $this->config->getTaxes(),
            'markup' => $this->config->getMarkup(),
            'flat_fee' => $this->config->getFee(),
            'delivery_date' => $this->config->getDisplayDelivery(),
            'allowed_methods' => $this->config->getAllowedMethods(),
            'logging' => $this->config->getLogging()
        ];

        return $configData[$key];
        
    }

}
