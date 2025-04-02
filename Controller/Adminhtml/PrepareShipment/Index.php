<?php

namespace Flagship\Shipping\Controller\Adminhtml\PrepareShipment;

use Flagship\Shipping\Model\Configuration;
use Magento\Backend\App\Action\Context;
use Magento\Sales\Model\OrderRepository;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Directory\Model\RegionFactory;
use Magento\Sales\Model\Convert\Order;
use Magento\Sales\Model\Order\Shipment\TrackFactory;
use Magento\InventorySalesAdminUi\Model\GetSalableQuantityDataBySku;
use Magento\Inventory\Model\GetSourceCodesBySkus;
use Magento\InventorySourceDeductionApi\Model\GetSourceItemBySourceCodeAndSku;
use Magento\Inventory\Model\SourceRepository;
use Magento\Sales\Api\Data\ShipmentExtensionFactory;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\InventoryShipping\Model\ResourceModel\ShipmentSource\GetSourceCodeByShipmentId;
use Flagship\Shipping\Model\Carrier\FlagshipQuote;
use Magento\Catalog\Model\ProductRepository;
use Magento\Customer\Api\AddressRepositoryInterface;
use Flagship\Shipping\Service\ApiService;
use Magento\Backend\App\Action;

class Index extends Action
{
    protected $orderId;
    protected $order;

    public function __construct(
        protected Context $context,
        protected OrderRepository $orderRepository,
        protected ScopeConfigInterface $scopeConfig,
        protected RegionFactory $regionFactory,
        protected Order $convertOrder,
        protected TrackFactory $trackFactory,
        protected GetSalableQuantityDataBySku $getSalableQuantityDataBySku,
        protected GetSourceCodesBySkus $getSourceCodesBySkus,
        protected GetSourceItemBySourceCodeAndSku $getSourceItemBySourceCodeAndSku,
        protected SourceRepository $sourceRepository,
        protected ShipmentExtensionFactory $shipmentExtensionFactory,
        protected StateInterface $inlineTranslation,
        protected TransportBuilder $transportBuilder,
        protected GetSourceCodeByShipmentId $getSourceCodeByShipmentId,
        protected FlagshipQuote $flagshipQuote,
        protected ProductRepository $productRepository,
        protected AddressRepositoryInterface $addressRepository,
        protected Configuration $configuration,
        protected ApiService $apiService
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $orderId = $this->getRequest()->getParam('order_id');
        $payload = $this->getPayload($orderId);
        $fsShipment = $this->prepareShipment($payload);
        return $this->_redirect($this->getUrl('sales/order/view', ['order_id' => $orderId]));
    }

    public function getOrder()
    {
        $orderId = $this->getRequest()->getParam('order_id');
        $order = $this->orderRepository->get($orderId);
        $this->order = $order;
        return $order;
    }

    protected function getDestinationAddress(): array
    {
        $shippingAddress = $this->order->getShippingAddress();
        $destinationAddress = [
            'name' => $shippingAddress->getName(),
            'attn' => $shippingAddress->getName(),
            'address' => $shippingAddress->getStreet()[0],
            'city' => $shippingAddress->getCity(),
            'country' => $shippingAddress->getCountryId(),
            'state' => $this->getStateCode($shippingAddress->getRegionId()),
            'postal_code' => $shippingAddress->getPostCode(),
            'phone' => $shippingAddress->getTelephone(),
            'is_commercial' => false
      ];

        return $destinationAddress;
    }

    public function getPayload($orderId): array
    {
        $order = $this->orderRepository->get($orderId);
        $store = $this->getStore();
        $from = $this->getSender($store);
        $to = $this->getReceiver($store);
        $items = $order->getAllVisibleItems();
        $packages = $this->getPackages($items);
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

    public function getPackages(array $items): array
    {

        $itemsArray = $this->flagshipQuote->getItemsArray($items);

        $packages = [
            "items" => $itemsArray,
            "units" => $this->getPackageUnits(),
            "type" => "package",
            "content" => "goods"
        ];

        return $packages;
    }

    public function getStateCode(int $regionId): string
    {
        $state = $this->regionFactory->create()->load($regionId);
        $stateCode = $state->getCode();
        return $stateCode;
    }

    // Creates Magento Shipment
    public function createShipment(int $flagship_shipment_id, int $confirmed = 0): \Magento\Sales\Model\Order\Shipment
    {
        $order = $this->order;
        $items = $order->getAllVisibleItems();
        $shipment = $this->convertOrder->toShipment($order);

        foreach ($items as $item) {
            $qtyShipped = $item->getProductType() == 'configurable' && $item->getQtyToShip() == 0 ? $item->getSimpleQtyToShip() : $item->getQtyToShip();
            $shipmentItem = $this->convertOrder->itemToShipmentItem($item)->setQty($qtyShipped);
            $shipment->addItem($shipmentItem);
        }

        $shipment->register();

        $shipment->getOrder()->setIsInProcess(true);
        $shipmentExtension = $shipment->getExtensionAttributes();
        
        if (empty($shipmentExtension)) {
            $shipmentExtension = $this->shipmentExtensionFactory->create();
        }

        $shipment->setExtensionAttributes($shipmentExtension);
        $shipment->setData('flagship_shipment_id', $flagship_shipment_id);

        // if ($confirmed == 0) {
        //     $shipment = $this->setTrackingDetails($flagship_shipment_id, $shipment);
        // }
        $shipment->save();
        return $shipment;
    }

    protected function getPayment(): array
    {
        $payment = [
          'payer' => 'F'
        ];
        return $payment;
    }

    protected function getOptions(\Magento\Store\Model\Store $store): array
    {
        $options = [
          'signature_required' => false,
          'reference' => 'Magento Order# ' . $this->order->getIncrementId(),
          'address_correction' => true
        ];
        $insuranceValue = $this->getInsuranceValue();

        if ($this->configuration->getInsurance() && $insuranceValue != 0) {
            $options['insurance'] = [
                'value' => $insuranceValue,
                'description' => 'insurance'
            ];
        }
        if ($this->configuration->useCustomerEmailForTracking()) {
            $options["shipment_tracking_emails"] =  $this->order->getCustomerEmail();
        }

        return $options;
    }

    protected function getReceiver(\Magento\Store\Model\Store $store): array
    {
        $shippingAddress = $this->getShippingAddress();
        $suite = isset($shippingAddress->getStreet()[1]) ? $shippingAddress->getStreet()[1] : '';
        $name = is_null($shippingAddress->getCompany()) ? $shippingAddress->getFirstName() : $shippingAddress->getCompany();
        $attn = strlen($shippingAddress->getFirstName() . ' ' . $shippingAddress->getLastName()) > 21 ? $shippingAddress->getFirstName() : $shippingAddress->getFirstName() . ' ' . $shippingAddress->getLastName();
        $city = $shippingAddress->getCity();
        $city = $this->flagshipQuote->removeAccents($city);

        $to = [
          'name' => substr($name, 0, 29),
          'attn' => substr($attn, 0, 20),
          'address' => substr($shippingAddress->getStreet()[0], 0, 29),
          'suite' => substr($suite, 0, 17),
          'city' => substr($city, 0, 29),
          'country' => $shippingAddress->getCountryId(),
          'state' => $this->getStateCode($shippingAddress->getRegionId()),
          'postal_code' => $shippingAddress->getPostCode(),
          'phone' => $shippingAddress->getTelephone(),
          'is_commercial' => true
        ];

        if ($this->configuration->getResidential()) {
            $to['is_commercial'] = false;
        }

        return $to;
    }

    protected function getSender(\Magento\Store\Model\Store $store): array
    {
        $country = $store->getConfig('general/store_information/country_id');

        $stateCode = $store->getConfig('general/store_information/region_id');
        $state = empty($stateCode) ? $stateCode : $this->getStateCode($stateCode);

        $name  =  substr($store->getConfig('general/store_information/name'), 0, 29);
        $attn = substr($name, 0, 20);
        $address = $store->getConfig('general/store_information/street_line1');
        $suite = is_null($store->getConfig('general/store_information/street_line2')) ? '' : $store->getConfig('general/store_information/street_line2');
        $city = $store->getConfig('general/store_information/city');
        $city = $this->flagshipQuote->removeAccents($city);
        $postcode = $store->getConfig('general/store_information/postcode');
        $phone = $store->getConfig('general/store_information/phone');

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

    protected function prepareShipment(array $payload)
    {
        try {
            $token = $this->configuration->getToken();
            $response = $this->apiService->sendRequest('/ship/prepare', $token, 'POST', $payload);
            $id = $response['response']['content']['id'];
            $this->createShipment($id);

            return $this->messageManager->addSuccess(__('FlagShip Shipment Number:' . $id . ' . Please confirm the shipment'));
        } catch (\Exception $e) {
            return $this->messageManager->addErrorMessage(__(ucfirst($e->getMessage())));
        }
    }


    protected function getPackageUnits(): string
    {
        return 'imperial';
    }

    protected function getShippingAddress(): \Magento\Sales\Model\Order\Address
    {
        $order = $this->getOrder();
        $shippingAddressDetails = $order->getShippingAddress();
        $shippingAddressDetails = is_null($shippingAddressDetails) ? $order->getBillingAddress() : $shippingAddressDetails;
        return $shippingAddressDetails;
    }

    protected function getStore(): \Magento\Framework\DataObject
    {
        $store = $this->getOrder()->getStore();
        return $store;
    }

    protected function getInsuranceValue(): float
    {
        return $this->order->getSubtotal() > 100 ? $this->order->getSubtotal() : 0.00;
    }

    protected function setTrackingDetails(int $flagship_shipment_id, \Magento\Sales\Model\Order\Shipment $shipment): \Magento\Sales\Model\Order\Shipment
    {
        try {
            $shipment->addTrack($this->addShipmentTracking($flagship_shipment_id));
            $shipment->addComment('FlagShip Shipment Unconfirmed');
            $shipment->save();
            return $shipment;
        } catch (\Exception $e) {
            throw new \Magento\Framework\Exception\LocalizedException(__($e->getMessage()));
        }
    }

    protected function addShipmentTracking(int $flagship_shipment_id): \Magento\Sales\Model\Order\Shipment\Track
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
