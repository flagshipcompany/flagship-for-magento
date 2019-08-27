<?php

namespace Flagship\Shipping\Block;

class Config extends \Magento\Framework\View\Element\Template{

    protected $flagship;

    public function __construct(\Magento\Framework\View\Element\Template\Context $context, \Flagship\Shipping\Block\Flagship $flagship){
        parent::__construct($context);
        $this->flagship = $flagship;
    }

    public function getTitle() : string {

        $settings = $this->flagship->getSettings();

        $msg = '';
        $packings = isset($settings["packings"]) && $settings["packings"] == 1 ? 'Packings Enabled. ': 'Packings Disabled. ';
        $token = isset($settings["token"]) && !empty($settings["token"]) ? 'Token is set. ' : 'Token is not set. ';
        $log = isset($settings["log"]) && !empty($settings["log"]) ? 'Logging Enabled. ' : 'Logging Disabled. ';

        $env = isset($settings["test_env"]) && !empty($settings["test_env"]) ? 'Test enviroment enabled' : 'Test enviroment disabled. ';

        $msg = count($settings) === 0 ? 'FlagShip is not configured' : $token.$packings.$log.$env;
        return $msg;
    }

    public function isTokenSet() : bool {
        return  array_key_exists("token",$this->flagship->getSettings()) ;
    }

    public function getSettings() : array {
        return $this->flagship->getSettings();
    }

}
