<?php

namespace Flagship\Shipping\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use Magento\Framework\UrlInterface;

class BoxesActions extends Column{
    protected $urlBuilder;

    public function __construct(ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        UrlInterface $urlBuilder,
        array $components = [],
        array $data = []){
        
        $this->urlBuilder = $urlBuilder;
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource){
        
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as &$item) {

                $item[$this->getData('name')]['delete'] = [
                    'href' => $this->urlBuilder->getUrl(
                        'shipping/listboxes/delete',
                        ['id' => $item['id']]
                    ),
                    'class' => 'action-delete',
                    'label' => __('Delete'),
                    'hidden' => false,
                ];
            }
        }

        return $dataSource;
    }
}