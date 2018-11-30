<?php
namespace Flagship\Shipping\Plugin;

class SendToFlagshipButton{

public function beforeSetLayout(\Magento\Sales\Block\Adminhtml\Order\View $subject){

    $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
    $orderId = $subject->getOrderId();
    $resource = $objectManager->get('Magento\Framework\App\ResourceConnection');
    $connection = $resource->getConnection();
    $tableName = $resource->getTableName('sales_order');
    $sql = $connection->select()->from(
              ["table" => $tableName],
              ["flagship_shipment_id"]
    )
    ->where('entity_id = ?',$orderId);
    $result = ($connection->fetchAll($sql))[0];
    $shipmentId = $result["flagship_shipment_id"];

    if(isset($shipmentId)){
      $subject->addButton(
          'flagship_shipment',
          [
              'label' => __('FlagShip Shipment Id : '.$shipmentId),
              'class' => __('action-default scalable'),
              'id' => 'flagship_shipment',
              'onclick' =>'window.open("'.SMARTSHIP_WEB_URL.'/shipping/'.$shipmentId.'/convert")'
          ]
        );
      $subject->addButton(
          'flagship_shipment_update',
          [
              'label' => __('Update FlagShip Shipment'),
              'class' => __('action-default scalable'),
              'id' => 'flagship_shipment_update',
              'onclick' => sprintf("location.href = '%s';", $subject->getUrl('shipping/prepareShipment', ['update' => 1, 'shipmentId' => $shipmentId]))
              //'setLocation(\'' . $subject->getUrl('shipping/prepareShipment') . '\')'
          ]
        );
      return;
    }

    $subject->addButton(
        'send_to_flagship',
        [
            'label' => __('Send To FlagShip'),
            'class' => __('action-default scalable'),
            'id' => 'send_to_flagship',
            'onclick' =>sprintf("location.href = '%s';", $subject->getUrl('shipping/prepareShipment'))
        ]
      );

    }
}
