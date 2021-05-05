<?php
namespace Flagship\Shipping\Block;

class AddBoxes extends \Magento\Framework\View\Element\Template
{
    protected $flagship;

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Framework\App\ResourceConnection $resource,
        \Flagship\Shipping\Helper\Flagship $flagship
    ) {
        parent::__construct($context);
        $this->resource = $resource;
        $this->flagship = $flagship;
    }

    public function getUnits() : string
    {
        // We only use imperial units because Magento doesn't automatically convert the products' details.
        // $units = $this->_scopeConfig->getValue(
        //     'general/locale/weight_unit',
        //     \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        // );
        // if($units === 'kgs'){
        //     return 'metric';
        // }
        return 'imperial';
    }

    public function getPackingsSetting() : ?string
    {
        return $this->flagship->getSettings()["packings"];
    }

    public function showBoxPrice() : bool
    {
        return $this->_scopeConfig->getValue(
            'carriers/flagship/pick_and_pack_price',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }
}
