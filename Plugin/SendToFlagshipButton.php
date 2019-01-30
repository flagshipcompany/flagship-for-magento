<?php
namespace Flagship\Shipping\Plugin;

class SendToFlagshipButton{

    public function beforeSetLayout(\Magento\Shipping\Block\Adminhtml\Create $subject){
        
        $this->order = $subject->getShipment()->getOrder();
        if(!$this->order->hasShipments()){
            $orderId = $this->order->getId();
            $subject->addButton(
                'send_to_flagship',
                [
                    'label' => __('Send To FlagShip &#8618;'),
                    'class' => __('action action-secondary scalable'),
                    'id' => 'send_to_flagship',
                    'onclick' => sprintf("location.href = '%s';", $subject->getUrl('shipping/prepareShipment',['order_id' => $orderId]))
                ]
            );
        }
        return;
    }

}
