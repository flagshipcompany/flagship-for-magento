<?php
namespace Flagship\Shipping\Controller\Adminhtml\SetToken;

use \Flagship\Shipping\Flagship;
use \Flagship\Shipping\Exceptions\ValidateTokenException;

class Index extends \Magento\Backend\App\Action{

    protected $resultPageFactory;
    protected $_logger;
    protected $loggingEnabled;
    protected $flagship;


    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        \Flagship\Shipping\Logger\Logger $logger,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Flagship\Shipping\Model\ConfigFactory $configFactory,
        \Flagship\Shipping\Model\Config $config,
        \Flagship\Shipping\Helper\Flagship $flagship
    ) {
         parent::__construct($context);
         $this->resultPageFactory = $resultPageFactory;
         $this->flagship = $flagship;
         $this->_logger = $logger;
         $this->loggingEnabled = array_key_exists('log',$this->flagship->getSettings()) ? $this->flagship->getSettings()["log"] : 1 ;
         $this->configFactory = $configFactory;
         $this->config = $config;
    }

    public function execute()
    {
       $token = $this->getRequest()->getParam('api_token');
       $testEnv = $this->getRequest()->getParam('test_env');

        if(!isset($token)){
            return $this->_redirect($this->_redirect->getRefererUrl());
        }

        $url = SMARTSHIP_API_URL;

        if($this->isSetTokenSame($token,$url)){
            $this->flagship->logInfo('Same token is set');
            return $this->_redirect($this->_redirect->getRefererUrl($this->messageManager->addNoticeMessage( __('Same API Token is set'))));
        }
        if($this->setToken($token,$url)){
            $this->flagship->logInfo('Token saved');
            return $this->_redirect($this->_redirect->getRefererUrl($this->messageManager->addSuccessMessage( __('Success! API Token saved'))));
        }
        $this->flagship->logInfo($token.' is an invalid token');
        return $this->_redirect($this->_redirect->getRefererUrl($this->messageManager->addErrorMessage( __('Invalid API Token'))));
    }

    protected function getSetToken() : ?string {
        if($this->isTokenSet()){
            return $this->flagship->getSettings()["token"];
        }
        return NULL;
    }

    protected function isTokenSet() : bool {
        return array_key_exists("token", $this->flagship->getSettings());
    }

    protected function isSetTokenSame(string $token,?string $url) : bool {

        if($this->isTokenValid($token,$url) && strcmp($this->getSetToken(),$token) === 0){
            return TRUE;
        }
        return FALSE;
    }

    protected function setToken(string $token, string $url) : bool {

        $this->flagship->logInfo('Validating Token');

        if($this->isTokenValid($token,$url) && $this->isTokenSet()){
            $collection = $this->config->getCollection()->addFieldToFilter('name',['eq' => 'token']);
            $recordId = $collection->getFirstItem()->getId();
            $record = $this->config->load($recordId);
            $record->setValue($token);
            $record->save();
            return true;
        }

        if($this->isTokenValid($token,$url)){
            $apiToken = $this->configFactory->create();
            $apiToken->setName('token');
            $apiToken->setValue($token);
            $this->flagship->logInfo('Saving token to database');
            return $this->saveToken($apiToken,$token);
        }
        return FALSE;
    }

    protected function saveToken($apiToken,string $token) : bool {
        try{
            $apiToken->save();
            return TRUE;
        } catch (\Exception $e){
            $this->flagship->logError($e->getMessage());
            $this->messageManager->addErrorMessage(__($e->getMessage()));
        }
    }

    protected function isTokenValid(?string $token, ?string $url) : bool {

        if(is_null($token)){
            return false;
        }

        $flagship = new Flagship($token,$url,FLAGSHIP_MODULE,FLAGSHIP_MODULE_VERSION);

        $validateTokenRequest = $flagship->validateTokenRequest($token);

        try{
            $validToken = $validateTokenRequest->execute() === 200 ? true : false;
            return $validToken;
        }
        catch(ValidateTokenException $e){
            $this->flagship->logError($e->getMessage());
            return false;
        }
    }
}
