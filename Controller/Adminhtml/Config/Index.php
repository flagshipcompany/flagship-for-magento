<?php
namespace Flagship\Shipping\Controller\Adminhtml\Config;

class Index extends \Magento\Backend\App\Action{

    protected $_logger;
    protected $loggingEnabled;

    public function __construct(\Magento\Backend\App\Action\Context $context,\Magento\Framework\View\Result\PageFactory $resultPageFactory, \Flagship\Shipping\Logger\Logger $logger) {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
        $this->objectManager = $context->getObjectManager();
        $this->flagship = $this->objectManager->get("Flagship\Shipping\Block\Flagship");      
        $this->_logger = $logger;
    }

    public function execute(){
        
        $token = $this->getRequest()->getParam('api_token');
        $packings = $this->getRequest()->getParam('packings');
        $logging = $this->getRequest()->getParam('logging');

        if($this->isLoggingEnabled()){
            $this->loggingEnabled = true;
        }

        if(isset($token) || isset($packings) || isset($logging)){

            $this->_redirect($this->getUrl('shipping/settoken',['api_token' => $token]));
            
            $config = $this->objectManager->create('Flagship\Shipping\Model\Config');
        
            $this->setPackings($config,$packings);
        
            $this->setLogging($config,$logging);
        }
        
        return  $resultPage = $this->resultPageFactory->create();
    }

    protected function isLoggingEnabled() : string {
        $logging = isset($this->flagship->getSettings()["log"]) ? $this->flagship->getSettings()["log"] : 0 ;
        return $logging;
    }

    protected function setPackings(\Flagship\Shipping\Model\Config $config,string $packings) : bool {

        if(!$this->ifSettingExists('packings')){
            $this->saveConfig($config,'packings',$packings);
            return TRUE;
        }

        $resource = $this->objectManager->get('Magento\Framework\App\ResourceConnection');
        $connection = $resource->getConnection();
        try{
            $connection->update("flagship_settings",
                ["value"=>$packings],
                ["name = ?" => 'packings']
            );
            $this->flagship->logInfo('Updated packings setting');
            return TRUE;
        } catch (\Exception $e){
            $this->flagship->logError($e->getMessage());
            $this->messageManager->addErrorMessage(__($e->getMessage()));
        }

    }

     protected function setLogging(\Flagship\Shipping\Model\Config $config,string $log) : bool {

        if(!$this->ifSettingExists('log')){
            $this->saveConfig($config,'log',$log);
            return TRUE;
        }

        $resource = $this->objectManager->get('Magento\Framework\App\ResourceConnection');
        $connection = $resource->getConnection();
        try{
            $connection->update("flagship_settings",
                    ["value"=>$log],
                    ["name = ?" => 'log']
            );
            
            $this->flagship->logInfo('Updated logging setting');
            return TRUE;
        } catch(\Exception $e){
            $this->flagship->logError($e->getMessage());
            $this->messageManager->addErrorMessage(__($e->getMessage()));
        }
    }

    protected function ifSettingExists(string $property) : bool {
        
        $settings = $this->flagship->getSettings(); 
        return array_key_exists($property, $settings);
    }

    protected function saveConfig(\Flagship\Shipping\Model\Config $config,string $name,string $value) : bool {
        
        try{
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
