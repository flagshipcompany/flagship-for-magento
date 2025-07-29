<?php

namespace Flagship\Shipping\Model\Carrier;

use Magento\Shipping\Model\Carrier\AbstractCarrierOnline;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Quote\Api\Data\ShippingMethodInterface;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Rate\Result\Method;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Xml\Security;
use Flagship\Shipping\Model\Configuration;
use Flagship\Shipping\Service\ApiService;
use Magento\Catalog\Model\ProductRepository;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\AttributeRepository;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Shipping\Model\Rate\ResultFactory as RateResultFactory;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory as RateMethodFactory;
use Magento\Framework\UrlInterface;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory as RateErrorFactory;
use Magento\Shipping\Model\Simplexml\ElementFactory;
use Magento\Shipping\Model\Rate\ResultFactory as RateFactory;
use Magento\Shipping\Model\Tracking\ResultFactory as TrackFactory;
use Magento\Shipping\Model\Tracking\Result\ErrorFactory as TrackErrorFactory;
use Magento\Shipping\Model\Tracking\Result\StatusFactory as TrackStatusFactory;
use Magento\Directory\Model\RegionFactory;
use Magento\Directory\Model\CountryFactory;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Directory\Helper\Data as DirectoryData;
use Magento\CatalogInventory\Api\StockRegistryInterface;

class FlagshipQuote extends AbstractCarrierOnline implements CarrierInterface
{
    public const SHIPPING_CODE = 'flagship';
    protected $_code = self::SHIPPING_CODE;
    protected $_isFixed = true;

    public function __construct(
        protected Configuration $configuration,
        protected ApiService $apiService,
        protected AttributeRepository $attributeRepository,
        protected ProductRepository $productRepository,
        protected SearchCriteriaBuilder $searchCriteriaBuilder,
        protected FilterBuilder $filterBuilder,
        ScopeConfigInterface $scopeConfig,
        protected RateResultFactory $rateResultFactory,
        protected RateMethodFactory $rateMethodFactory,
        protected UrlInterface $urlBuilder,
        protected RateErrorFactory $rateErrorFactory,
        \Psr\Log\LoggerInterface $logger,
        Security $xmlSecurity,
        protected ElementFactory $xmlElFactory,
        protected RateFactory $rateFactory,
        TrackFactory $trackFactory,
        protected TrackErrorFactory $trackErrorFactory,
        protected TrackStatusFactory $trackStatusFactory,
        protected RegionFactory $regionFactory,
        CountryFactory $countryFactory,
        CurrencyFactory $currencyFactory,
        DirectoryData $directoryData,
        StockRegistryInterface $stockRegistry,
        array $data = []
    ) {
        parent::__construct(
            $scopeConfig,
            $rateErrorFactory,
            $logger,
            $xmlSecurity,
            $xmlElFactory,
            $rateFactory,
            $rateMethodFactory,
            $trackFactory,
            $trackErrorFactory,
            $trackStatusFactory,
            $regionFactory,
            $countryFactory,
            $currencyFactory,
            $directoryData,
            $stockRegistry,
            $data
        );
    }

    public function isShippingLabelsAvailable(): bool
    {
        return true;
    }

    public function isTrackingAvailable(): bool
    {
        return true;
    }

    public function getTracking(string $tracking)
    {
        $result = $this->_trackFactory->create();
        $status = $this->_trackStatusFactory->create();
        $status->setCarrier($this->getCarrierCode());

        if (stristr($tracking, 'Unconfirmed') !== false) {
            $shipmentId = substr($tracking, strpos($tracking, '-') + 1);
            $url = $this->configuration->getUrl()."/shipping/$shipmentId/convert";
            $status->setCarrierTitle('Your FlagShip shipment is still Unconfirmed');
        }
        if (stristr($tracking, 'Unconfirmed') === false
            && $this->getShipmentFromFlagship($tracking) != null
        ) { //shipment confirmed
            $shipment = $this->getShipmentFromFlagship($tracking);
            $status->setCarrierTitle($shipment['service']['courier_desc']);
            $courierName = $shipment['service']['courier_name'];
            $trackingNumber = $shipment['tracking_number'];
            $url = $this->getTrackingUrl($courierName, $trackingNumber);
        }

        if ($this->getShipmentFromFlagship($tracking) == null || (stristr($tracking, 'Unconfirmed') === false)
        ) {
            $url = 'https://www.flagshipcompany.com';
            $tracking = "Tracking is not available for this shipment. Please check with FlagShip";
        }
        $status->setTracking($tracking);
        $status->setUrl($url);
        $result->append($status);
        return $result;
    }

    protected function _doShipmentRequest($request)
    {
        return $request;
    }

    public function proccessAdditionalValidation($request): bool
    {
        return $this->processAdditionalValidation($request);
    }

    public function processAdditionalValidation($request): bool
    {
        return true;
    }

    public function allowedMethods(): array
    {
        if (empty($this->getToken())) {
            return [];
        }
        $token = $this->getToken();
        try {
            $response = $this->apiService->sendRequest('/ship/available_services', $token, 'GET', []);

            $allowedMethods = $response['status'] >= 200 ? $this->getAllowedMethodsArray($response['response']['content']) : [];
            return $allowedMethods;
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            return [];
        }
    }

    public function getAllowedMethods(): array
    {
        $allowed = $this->configuration->getAllowedMethods();
        $methods = [];
        foreach ($allowed as $value) {
            $methods[$value] = $value;
        }
        return $methods;
    }

    public function collectRates($request)
    {
        if (!$this->canCollectRates() || null === $request->getDestPostcode()) {
            return $this->getErrorMessage();
        }

        $cartItems = $request->getAllItems();
        $itemsValid = $this->validateItems($cartItems);
        if (!$itemsValid) {
            return $this->getErrorMessage();
        }

        $payload = $this->getPayload($cartItems, $request);
        $rates = $this->getRatesArray($payload);
        if (empty($rates)) {
            return $this->getErrorMessage();
        }
        return $this->getRatesResult($rates);
    }

    public function removeAccents(string $accented): string
    {
        $lc = strtolower($accented);
        $lc = str_replace(['à', 'À', 'á', 'Á', 'â', 'Â', 'ä', 'Ä'], 'a', $lc);
        $lc = str_replace(['è', 'È', 'é', 'É', 'ê', 'Ê', 'ë', 'Ë'], 'e', $lc);
        $lc = str_replace(['î', 'Î', 'ï', 'Ï', 'í', 'Í'], 'i', $lc);
        $lc = str_replace(['ô', 'Ô', 'ö', 'Ö', 'ò', 'Ò', 'ó', 'Ó'], 'o', $lc);
        $lc = str_replace(['û', 'Û', 'ü', 'Ü', 'ù', 'Ù', 'ú', 'Ú'], 'u', $lc);
        $lc = str_replace(['æ', 'Æ'], 'ae', $lc);
        $lc = str_replace(['œ', 'Œ'], 'oe', $lc);

        return strtoupper($lc);
    }

    public function getItemsArray(array $items): array
    {
        $packingAndBoxes = $this->configuration->isPackingEnabled() && !empty($this->configuration->getBoxes());

        if (!$packingAndBoxes) {
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

        $packingsPayload = $this->getPayloadForPacking($items);
        try {
            $packingResponse = $this->apiService->sendRequest(
                '/ship/packing',
                $this->configuration->getToken(),
                'POST',
                $packingsPayload
            );
            $packages = $packingResponse['response']['content']['packages'];
            $formattedPackages = $this->getFormattedPackages($packages);
            return $formattedPackages;
        } catch (\Exception $e) {
            error_log($e->getMessage());
            return [];
        }
    }

    protected function skipIfItemIsDownloadable(array $orderItems, string $sourceCode, $item)
    {
        $orderItems[$sourceCode]['source'] = $this->sourceRepository->get($sourceCode);
        if ($item->getProductType() != 'downloadable') {
            $orderItems[$sourceCode]['items'][] = $item;
        }
        return $orderItems[$sourceCode];
    }

    protected function getRates($quotes): array
    {
        $rates = [];
        foreach ($quotes as $quote) {
            $courierName = $quote['service']['courier_name'] === 'FedEx'
            ? $quote['service']['courier_name'] . ' ' . $quote['service']['courier_desc']
            : $quote['service']['courier_desc'];

            $key = $quote['service']['courier_name'] . ' - ' . $quote['service']['courier_desc'];
            $rates[$key]['total'][] = $quote['price']['total'];
            $rates[$key]['subtotal'][] = $quote['price']['subtotal'];
            $rates[$key]['taxesTotal'][] = array_sum($quote['price']['taxes']);
            $carrier = in_array($courierName, $this->getAllowedMethods())
            ? self::SHIPPING_CODE
            : $quote['service']['courier_desc'];
            $rates[$key]['details'] = [
            'carrier' => $carrier,
            'carrier_title' => ucfirst($quote['service']['courier_name']),
            'method' => $quote['service']['courier_code'],
            'method_title' => $courierName,
            'estimated_delivery_date' => $quote['service']['estimated_delivery_date'],
            'subtotal' => $quote['price']['subtotal'],
            'total' => $quote['price']['total']
            ];
        }

        return $rates;
    }

    protected function getRatesArray(array $payload): array
    {
        $quotes = $this->getQuotes($payload);
        if(!empty($quotes)) {
            $rates = $this->getRates($quotes);
            return $rates;
        }

        return [];
    }

    protected function getRatesResult(array $rates)
    {
        $result = $this->rateResultFactory->create();
        try {
            foreach ($rates as $rate) {
                $result->append($this->prepareShippingMethods($rate));
            }

            return $result;
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            return $this->getErrorMessage();
        }
    }

    protected function getAllowedMethodsArray(array $services): array
    {
        $methods = [];
        $services = array_values($services);
        $services = array_merge(...$services);
        $fedexServices = [
                'PRIORITY_OVERNIGHT',
                'FEDEX_2_DAY',
                'STANDARD_OVERNIGHT',
                'FIRST_OVERNIGHT',
                'FEDEX_EXPRESS_SAVER',
                'FEDEX_FIRST_FREIGHT',
                'FEDEX_1_DAY_FREIGHT',
                'FEDEX_GROUND',
                'INTERNATIONAL_ECONOMY',
                'INTERNATIONAL_ECONOMY_DISTRIBUTION',
                'INTERNATIONAL_ECONOMY_FREIGHT',
                'FEDEX_INTERNATIONAL_PRIORITY',
                'FEDEX_INTERNATIONAL_PRIORITY_EXPRESS',
                'INTERNATIONAL_PRIORITY_DISTRIBUTION',
                'INTERNATIONAL_PRIORITY_FREIGHT',
                'FEDEX_INTERNATIONAL_CONNECT_PLUS',
                'FEDEX_GROUND'
            ];

        foreach ($services as $service) {
            $label = in_array($service['courier_code'], $fedexServices) ? 'FedEx ' . $service['courier_description'] : $service['courier_description'];
            $methods[] = [
                'value' => $service['courier_description'],
                'label' =>  __($label)
            ];
        }
        return $methods;
    }

    protected function getOrderId(string $shipmentId): ?string
    {
        $shipments = $this->shipmentCollection->create()->addFieldToFilter('flagship_shipment_id', $shipmentId);
        foreach ($shipments as $value) {
            $shipment = $value;
        }
        $orderId = $shipment->getOrder()->getId();
        return $orderId;
    }

    protected function prepareShippingMethods(array $rate): \Magento\Quote\Model\Quote\Address\RateResult\Method
    {
        $method = $this->rateMethodFactory->create();
        $method->setCarrier($rate['details']['carrier']);
        $method->setCarrierTitle($rate['details']['carrier_title']);
        $methodTitle = $rate['details']['method_title'];
        $method->setMethod($rate['details']['method']);
        if ($this->configuration->getDisplayDelivery()) {
            $methodTitle .= ' (Estimated delivery date: ' . $rate['details']['estimated_delivery_date'] . ')';
        }
        $method->setMethodTitle($methodTitle);
        $amount = array_sum($rate['subtotal']);
        $markup = $this->configuration->getMarkup();
        $flatFee = $this->configuration->getFee();
        $addTaxes = $this->configuration->getTaxes();

        if ($markup > 0) {
            $amount += ($markup / 100) * $amount;
        }
        if ($flatFee > 0) {
            $amount += $flatFee;
        }
        if ($addTaxes) {
            $shipmentTax = array_sum($rate['taxesTotal']);
            $amount += $shipmentTax;
        }

        $method->setPrice($amount);
        $method->setCost($amount);
        return $method;
    }

    protected function getToken(): ?string
    {
        try {
            $token = $this->configuration->getToken() ? $this->configuration->getToken() : null;
            return $token;
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function getSenderAddress($isShipment): array
    {
        $from_city = $this->getScopeConfigValue('general/store_information/city');
        $from_country = $this->getScopeConfigValue('general/store_information/country_id');
        $from_state = $this->getState($this->getScopeConfigValue('general/store_information/region_id'));
        $from_postcode = $this->getScopeConfigValue('general/store_information/postcode');
        $from_city = $this->removeAccents($from_city);

        $from = [
            "city"  => substr($from_city, 0, 29),
            "country"   => $from_country,
            "state" => $from_state,
            "postal_code"   => $from_postcode,
            "is_commercial" => true
        ];

        return $from;
    }

    protected function getScopeConfigValue(string $path): ?string
    {
        return $this->_scopeConfig->getValue($path, \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }

    protected function getReceiverAddress($request, $isShipment): array
    {
        $toCity = empty($request->getDestCity()) ? 'Toronto' : $request->getDestCity();
        $toCity = $this->removeAccents($toCity);
        $to = [
            "city" => substr($toCity, 0, 29),
            "country" => $request->getDestCountryId(),
            "state" => $request->getDestRegionCode(),
            "postal_code" => $request->getDestPostcode(),
            "is_commercial" => false
        ];
        $to = $this->setResidentialFlag($to);
        return $to;
    }

    protected function setResidentialFlag(array $to): array
    {
        $residentialFlag = $this->configuration->getResidential();
        if (!$residentialFlag) {
            $to["is_commercial"] = true;
        }
        return $to;
    }

    protected function getPayload(array $items, $request, $isShipment = false): array
    {
        $insurance  = $this->configuration->getInsurance();
        $from = $this->getSenderAddress($isShipment);
        $to = $this->getReceiverAddress($request, $isShipment);

        $packages = [
            "items" => $this->getItemsArray($items),
            "units" => $this->getUnits(),
            "type" => "package",
            "content" => "goods"
        ];

        $payment = [
            "payer" => "F"
        ];
        $options = [
            "address_correction" => true
        ];
        $insuranceValue = $this->getInsuranceAmount($request);
        if ($insurance && $insuranceValue > 0) {
            $options["insurance"] = [
                "value" => $insuranceValue,
                "description"   => "Insurance"
            ];
        }

        $payload = [
            "from"  => $from,
            "to"    => $to,
            "packages"  => $packages,
            "payment"   =>  $payment,
            "options"   => $options
        ];

        return $payload;
    }

    protected function getInsuranceAmount($request): float
    {
        $total = $request->getSubtotal() > 100 ? $request->getSubtotal() : 0;
        return $total;
    }

    //TBD
    protected function getState(?string $regionId): ?string
    {
        if (is_null($regionId)) {
            return 'QC';
        }
        $shipperRegion = $this->regionFactory->create()->load($regionId);
        $shipperRegionCode = $shipperRegion->getCode();
        return $shipperRegionCode;
    }

    protected function getUnits(): string
    {
        // We only use imperial units because Magento doesn't automatically convert the products' details.
        return 'imperial';
    }

    protected function getPayloadForPacking(array $items): ?array
    {
        $packingItems = [];
        foreach ($items as $item) {
            $packingItems = $this->getPackingItems($item);
        }
        $boxes = $this->configuration->getBoxes();
        $boxes = !empty($boxes) ? $boxes : [];

        $payload = [
            "items" => $packingItems,
            "boxes" => $boxes,
            "units" => $this->getUnits()
        ];

        return $payload;
    }

    protected function getPackingItems($item): array
    {
        $payloadItems = [];
        $prodId = $item->getProductType() == 'configurable'
            ? $item->getOptionByCode('simple_product')->getProduct()->getId()
            : $item->getProduct()->getId();

        $product = $this->productRepository->getById($prodId);
        $dimensions = $this->getProductDimensions($product);

        for ($i = 0; $i < $item->getQty() || $i < $item->getQtyOrdered(); $i++) {
            $payloadItems[] = [
                "length" => $dimensions['length'],
                "width" => $dimensions['width'],
                "height" => $dimensions['height'],
                "weight" => $dimensions['weight'],
                "description" => $product->getName()
            ];
        }

        return array_values($payloadItems);
    }

    protected function getProductDimensions($product)
    {
        $length = null === $product->getCustomAttribute('fs_length')
            ? 1.0 : floatval($product->getCustomAttribute('fs_length')->getValue());
        $width = null === $product->getCustomAttribute('fs_width')
            ? 1.0 : floatval($product->getCustomAttribute('fs_width')->getValue());
        $height = null === $product->getCustomAttribute('fs_height')
            ? 1.0 : floatval($product->getCustomAttribute('fs_height')->getValue());
        $weight = is_null($product->getWeight()) ? 1 : $product->getWeight();

        return [
            'length' => $length <= 0 ? 1 : $length,
            'width' => $width <= 0 ? 1 : $width,
            'height' => $height <= 0 ? 1 : $height,
            'weight' => $weight <= 0 ? 1 : $weight,
        ];
    }

    protected function validateItems(array $items) {
        $valid = 1;
        foreach($items as $item) {
            $product = $this->productRepository->get($item->getSku());
            $dimensions = $this->getProductDimensions($product);
            $valid = (($dimensions['width'] * 2) + ($dimensions['height'] * 2) + $dimensions['length']) > 165
                ? 0 : $valid;
        }
        return $valid;
    }

    protected function getFormattedPackages(array $packages): array
    {
        $formattedPackages = [];
        foreach ($packages as $package) {
            $formattedPackages[] = [
                'width' => intval($package['width']),
                'height' => intval($package['height']),
                'length' => intval($package['length']),
                'weight' => $package['weight'] < 1 ? 1 : $package['weight'],
                'description' => $package['box_model']
            ];
        }
        return $formattedPackages;
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
                $url = $this->configuration->getUrl();
                break;
        }
        return $url;
    }

    protected function getQuotes(array $payload)
    {
        $token = $this->configuration->getToken();

        try {
            $quotes = $this->apiService->sendRequest('/ship/rates', $token, 'POST', $payload);
            return $quotes['response']['content'];
        } catch (\Exception $e) {
            return [];
        }

    }

    protected function getShipmentFromFlagship(string $trackingNumber)
    {
        $token = $this->configuration->getToken();

        try {
            $shipments = $this->apiService->sendRequest('/ship/shipments', $token, 'GET', []);
            $shipment = array_filter($shipments, function ($shipment) use ($trackingNumber) {
                return $shipment['tracking_number'] == $trackingNumber;
            });
            return $shipment;
        } catch (\Exception $e) {
            return [];
        }
    }
}
