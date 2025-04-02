<?php
declare(strict_types=1);

namespace Flagship\Shipping\Block\Adminhtml\Form\Field;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Data\Form\Element\Factory as ElementFactory;

class Boxes extends AbstractFieldArray
{
    protected $_elementFactory;

    public function __construct(
        Context $context,
        ElementFactory $elementFactory,
        array $data = []
    ) {
        $this->_elementFactory = $elementFactory;
        parent::__construct($context, $data);
    }

    protected function _prepareToRender()
    {
        $this->addColumn('box_model', ['label' => __('Name')]);
        $this->addColumn('length', ['label' => __('Length')]);
        $this->addColumn('width', ['label' => __('Width')]);
        $this->addColumn('height', ['label' => __('Height')]);
        $this->addColumn('weight', ['label' => __('Weight')]);
        $this->addColumn('max_weight', ['label' => __('Max Weight')]);
        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add Box');
    }

    protected function _prepareArrayRow(\Magento\Framework\DataObject $row)
    {
        $options = [];
        $boxName = $row->getBoxName();
        if ($boxName !== null) {
            $options['option_' . $boxName] = 'selected="selected"';
        }
        $row->setData('option_extra_attrs', $options);
    }

}