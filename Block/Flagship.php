<?php

namespace Flagship\Shipping\Block;

class Flagship extends \Magento\Framework\View\Element\Template{

    protected $_logger;
    protected $loggingEnabled;
    protected $redirect;

    public function __construct(\Magento\Backend\App\Action\Context $context, \Flagship\Shipping\Logger\Logger $logger){
        
        $this->objectManager = $context->getObjectManager();
        $this->_logger = $logger; 
        $this->loggingEnabled = isset($this->getSettings()["log"]) ? $this->getSettings()["log"] : 0 ; 
    }
    
    public function getSettings() : array {
        $resource = $this->objectManager->get('Magento\Framework\App\ResourceConnection');
        $connection = $resource->getConnection();
        $tableName = $resource->getTableName('flagship_settings');
         try{
            $sql = $connection->select()->from(
                    ["table" => $tableName]
                );
            $result = $connection->fetchAll($sql);
            $this->logInfo('Retrieved settings from database');
            return $this->getSettingsValues($result);
        } catch(\Exception $e){
            $this->logError($e->getMessage());
        }
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

}