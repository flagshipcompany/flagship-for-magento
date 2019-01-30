<?php
namespace Flagship\Shipping\Controller\Adminhtml\SetToken;

use \Flagship\Shipping\Flagship;
use \Flagship\Shipping\Exceptions\ValidateTokenException;

class Index extends \Magento\Backend\App\Action{

    protected $resultPageFactory;
    protected $objectManager;
    protected $_logger;
    protected $loggingEnabled;
    protected $flagship;


    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        \Flagship\Shipping\Logger\Logger $logger
    ) {

         parent::__construct($context);
         $this->resultPageFactory = $resultPageFactory;
         $this->objectManager = $context->getObjectManager();
         $this->flagship = $this->objectManager->get("Flagship\Shipping\Block\Flagship");
         $this->_logger = $logger;
         $this->loggingEnabled = $this->flagship->getSettings()["log"];
    }

    public function execute()
    {

       $token = $this->getRequest()->getParam('api_token');

        if(!isset($token)){
            return $this->_redirect($this->_redirect->getRefererUrl());
        }

        if($this->isSetTokenSame($token)){
            $this->flagship->logInfo('Same token is set');
            return $this->_redirect($this->_redirect->getRefererUrl($this->messageManager->addNoticeMessage( __('Same API Token is set'))));
        }
        if($this->setToken($token)){
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

    protected function isSetTokenSame(string $token) : bool {

        if($this->isTokenValid($token) && strcmp($this->getSetToken(),$token) === 0){
            return TRUE;
        }
        return FALSE;
    }

    protected function setToken(string $token) : bool {

        $this->flagship->logInfo('Validating Token');

        if($this->isTokenValid($token)){
            $apiToken = $this->objectManager->create('Flagship\Shipping\Model\Config');
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

    protected function isTokenValid(?string $token) : bool {

        if(is_null($token)){
            return false;
        }

        $flagship = new Flagship($token,SMARTSHIP_API_URL,FLAGSHIP_MODULE,FLAGSHIP_MODULE_VERSION);

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