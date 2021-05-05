<?php
namespace Flagship\Shipping\Plugin;

class SendToFlagshipButton
{
    public function beforeSetLayout(\Magento\Shipping\Block\Adminhtml\Create $subject)
    {
        $this->addSendToFlagshipButton($subject);
        return;
    }

    public function addSendToFlagshipButton($subject) : ?int
    {
        $order = null;

        $order = !is_null($subject->getShipment()) ? $subject->getShipment()->getOrder() : $subject->getOrder();

        if (!$order->hasShipments()) {
            $orderId = $order->getId();
            $subject->addButton(
                'send_to_flagship',
                [
                    'label' => __('Send To FlagShip &#8618;'),
                    'class' => __('action action-secondary scalable'),
                    'id' => 'send_to_flagship',
                    'onclick' => sprintf("location.href = '%s';", $subject->getUrl('shipping/prepareShipment', ['order_id' => $orderId]))
                ]
            );
            return 0;
        }
        return null;
    }
}
