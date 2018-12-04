<?php
namespace Flagship\Shipping\Controller\Adminhtml\SetToken;

use \Flagship\Shipping\Flagship;
use \Flagship\Shipping\Exceptions\ValidateTokenException;

class Index extends \Magento\Backend\App\Action{

    protected $resultPageFactory;
    protected $objectManager;

    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory
    ) {

         parent::__construct($context);
         $this->resultPageFactory = $resultPageFactory;
         $this->objectManager = $context->getObjectManager();

    }

    public function execute()
    {
        $token = $this->getRequest()->getParam('api_token');
        
        if(empty($token)){
            return  $resultPage = $this->resultPageFactory->create();
        }

        if($this->isSetTokenSame($token)){
          $this->messageManager->addNoticeMessage( __('Same API Token is set'));
          return $resultPage = $this->resultPageFactory->create();
        }
        if($this->setToken($token)){
          $this->messageManager->addSuccessMessage( __('Success! API Token saved'));
          return $resultPage = $this->resultPageFactory->create();
        }
        $this->messageManager->addErrorMessage( __('Invalid API Token'));
        return $resultPage = $this->resultPageFactory->create();

    }

    protected function getSetToken() : ?string {

      $resource = $this->objectManager->get('Magento\Framework\App\ResourceConnection');
      $connection = $resource->getConnection();
      $tableName = $resource->getTableName('flagship_settings');
      $sql = $connection->select()->from(
                ["table" => $tableName]
      );
      $result = $connection->fetchAll($sql);
      if(count($result) > 0){
        return $result[0]['token'];
      }
      return NULL;
    }

    protected function isSetTokenSame(string $token) : bool {

      if(strcmp($this->getSetToken(),$token) === 0){
          return TRUE;
      }
      return FALSE;

    }

    protected function setToken($token) : bool {

      if($this->isTokenValid($token)){
        $apiToken = $this->objectManager->create('Flagship\Shipping\Model\SetToken');
        $apiToken->setToken($token);
        $apiToken->save();
        return TRUE;
      }
      return FALSE;
    }

    protected function isTokenValid(?string $apiToken) : bool {

        if(is_null($apiToken)){
            return false;
        }

        $flagship = new Flagship($apiToken,SMARTSHIP_API_URL);

        $validateTokenRequest = $flagship->validateTokenRequest($apiToken);

        try{
            $validToken = $validateTokenRequest->execute() === 200 ? true : false;
            return $validToken;
        }
        catch(ValidateTokenException $e){
            return false;
        }

    }
  }
