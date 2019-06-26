<?php

namespace Flagship\Shipping\Block;

class Flagship extends \Magento\Framework\View\Element\Template{

    protected $_logger;
    protected $loggingEnabled;
    protected $redirect;

    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Flagship\Shipping\Logger\Logger $logger,
        \Flagship\Shipping\Model\Config $config,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ){
        $this->_logger = $logger;
        $this->config = $config;
        $this->scopeConfig = $scopeConfig;
        $this->loggingEnabled = isset($this->getSettings()["log"]) ? $this->getSettings()["log"] : 0 ;
        $this->getEnv();
    }

    public function getSettings() : array {

        $collection = $this->config->getCollection();
        return $this->getSettingsValues($collection->toArray()['items']);
    }

    public function getSettingsValues(array $result) : array {

        $settings = [];
        foreach ($result as $row) {
            $settings[$row["name"]] = $row["value"];
        }
        return $settings;
    }

    public function logInfo(string $msg) : bool {
        if($this->loggingEnabled){
            $this->_logger->info(__($msg));
            return TRUE;
        }
        return FALSE;
    }

    public function logError(string $msg) : bool {
        if($this->loggingEnabled){
            $this->_logger->error(__($msg));
            return TRUE;
        }
        return FALSE;
    }

    private function getEnv(){
        if($this->getSettings()['test_env']){
            define('SMARTSHIP_WEB_URL','https://test-smartshipng.flagshipcompany.com');
            define('SMARTSHIP_API_URL','https://test-api.smartship.io');
            return 0;
        }
        define('SMARTSHIP_WEB_URL','http://127.0.0.1:3006');
        define('SMARTSHIP_API_URL','http://127.0.0.1:3002');
        return 0;
    }

}
