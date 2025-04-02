<?php

declare(strict_types=1);

namespace Flagship\Shipping\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Exception\InputException;
use Flagship\Shipping\Service\ApiService;

class ConfigChange implements ObserverInterface
{
    public function __construct(
        private RequestInterface $request, 
        private WriterInterface $configWriter,
        private ApiService $apiService
    )
    {}
    public function execute(EventObserver $observer)
    {
        $fsParams = $this->request->getParam('groups');
        $testEnv = $fsParams['flagship']['fields']['env']['value'];
        $token = $fsParams['flagship']['fields']['token']['value'];

        $url = $testEnv == 1 ? 'https://test-api.smartship.io' : 'https://api.smartship.io';
        $response = $this->apiService->sendRequest('/check-token', $token, 'GET', [], $testEnv, $url);

        if($response['status'] != 200){
            throw new InputException(__('Invalid Token'));
        }
        
        // call apiservice for validate token request
        return $this;
    }
}
?>