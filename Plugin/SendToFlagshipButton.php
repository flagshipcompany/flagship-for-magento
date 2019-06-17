<?php
namespace Flagship\Shipping\Plugin;

class SendToFlagshipButton{

    public function beforeSetLayout(\Magento\Shipping\Block\Adminhtml\Create $subject){

        $this->addSendToFlagshipButton($subject);
        return;
    }

    public function addSendToFlagshipButton($subject,$showButton = 1){
        $order = NULL;
        if(!is_null($subject->getShipment())){
            $order = $subject->getShipment()->getOrder();
        }
        $order = $subject->getOrder();

        if(!$order->hasShipments() && $showButton){
            $orderId = $order->getId();
            return $subject->addButton(
                'send_to_flagship',
                [
                    'label' => __('Send To FlagShip &#8618;'),
                    'class' => __('action action-secondary scalable'),
                    'id' => 'send_to_flagship',
                    'onclick' => sprintf("location.href = '%s';", $subject->getUrl('shipping/prepareShipment',['order_id' => $orderId]))
                ]
            );
        }
        return NULL;
    }

}
