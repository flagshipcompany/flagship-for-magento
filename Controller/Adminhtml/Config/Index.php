<?php
namespace Flagship\Shipping\Controller\Adminhtml\Config;

class Index extends \Magento\Backend\App\Action{

    protected $_logger;
    protected $loggingEnabled;

    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        \Flagship\Shipping\Logger\Logger $logger,
        \Flagship\Shipping\Block\Flagship $flagship,
        \Flagship\Shipping\Model\ConfigFactory $configFactory,
        \Flagship\Shipping\Model\Config $config
    ) {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
        $this->flagship = $flagship;
        $this->configFactory = $configFactory;
        $this->_logger = $logger;
        $this->config = $config;
    }

    public function execute(){

        $token = $this->getRequest()->getParam('api_token');
        $packings = $this->getRequest()->getParam('packings');
        $logging = $this->getRequest()->getParam('logging');
        $testEnv = $this->getRequest()->getParam('test_env');

        if($this->isLoggingEnabled()){
            $this->loggingEnabled = true;
        }

        if(isset($token)){
            $this->_redirect($this->getUrl('shipping/settoken',['api_token' => $token,'test_env' => $testEnv]));
        }

        if(isset($packings)){
            $this->setPackings($packings);
        }

        if(isset($logging)){
            $this->setLogging($logging);
        }

        if(isset($testEnv)){
            $this->setEnv($testEnv);
        }

        return  $resultPage = $this->resultPageFactory->create();
    }

    protected function isLoggingEnabled() : string {
        $logging = isset($this->flagship->getSettings()["log"]) ? $this->flagship->getSettings()["log"] : 0 ;
        return $logging;
    }

    protected function setEnv($testEnv){
        if($this->setConfig('test_env',$testEnv)){
            return TRUE;
        }
        return $this->updateConfig('test_env',$testEnv);
    }

    protected function setConfig(string $name, string $value){
        if(!$this->ifSettingExists($name)){
            $this->saveConfig($name,$value);
            return TRUE;
        }
        return FALSE;
    }

    protected function updateConfig(string $name, string $value){
        var_dump("thing_to_debug"); var_dump($value);
        $collection = $this->config->getCollection()->addFieldToFilter('name',['eq' => $name]);
        $recordId = $collection->getFirstItem()->getId();
        $record = $this->config->load($recordId);
        $record->setValue($value);
        $record->save();
        return TRUE;
    }

    protected function setPackings(string $packings) : bool {
        if($this->setConfig('packings',$packings)){
            return TRUE;
        }
        return $this->updateConfig('packings',$packings);
    }

    protected function setLogging(string $log) : bool {

         if($this->setConfig('log',$log)){
             return TRUE;
         }
          return $this->updateConfig('log',$log);
    }

    protected function ifSettingExists(string $property) : bool {

        $settings = $this->flagship->getSettings();
        return array_key_exists($property, $settings);
    }

    protected function saveConfig(string $name,string $value) : bool {

        try{
                $config = $this->configFactory->create();
                $config->setName($name);
                $config->setValue($value);
                $config->save();
                $this->flagship->logInfo("Updated value for ".$name);
                return TRUE;
            }
            catch(\Exception $e){
                $this->flagship->logError($e->getMessage());
                $this->messageManager->addErrorMessage(__($e->getMessage()));
            }
    }
}
