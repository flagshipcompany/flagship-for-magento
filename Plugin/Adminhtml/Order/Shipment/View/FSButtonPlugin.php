<?php

namespace Flagship\Shipping\Plugin\Adminhtml\Order\Shipment\View;

use Magento\Framework\UrlInterface;
use Magento\Backend\Block\Widget\Button\ButtonList;
use Magento\Backend\Block\Widget\Button\Toolbar as ToolbarContext;
use Magento\Framework\View\Element\AbstractBlock;

class FSButtonPlugin
{
    public function __construct(
        private UrlInterface $urlBuilder,
        
    ) {
    }

    public function beforePushButtons(
        ToolbarContext $toolbar,
        AbstractBlock $context,
        ButtonList $buttonList
    ): array {
        $buttonList->add(
            'send_to_flagship',
            [
                'label' => __('Send To FlagShip1 &#8618;'),
                'class' => __('action action-secondary scalable'),
                'id' => 'send_to_flagship',
                'onclick' => sprintf("location.href = '%s';", "https://flagshipcompany.com")
            ]
        );
        return [$context, $buttonList];
    }
}