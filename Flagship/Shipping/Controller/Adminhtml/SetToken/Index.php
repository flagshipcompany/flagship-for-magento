<?php
namespace Flagship\Shipping\Controller\Adminhtml\SetToken;

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

      if($this->validateToken($token)){
        $apiToken = $this->objectManager->create('Flagship\Shipping\Model\SetToken');
        $apiToken->setToken($token);
        $apiToken->save();
        return TRUE;
      }
      return FALSE;
    }

    protected function validateToken(?string $apiToken) : bool {
      $curl = curl_init();
      curl_setopt_array($curl, array(
        CURLOPT_URL => SMARTSHIP_API_URL."/ship/available_services",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => "GET",
        CURLOPT_HTTPHEADER => array(
          "X-Smartship-Token: $apiToken",
        ),
      ));
      $response = curl_exec($curl);
      $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
      $errorno = curl_errno($curl);
      curl_close($curl);

      if($httpcode === 200 || $httpcode === 201){
        return TRUE;
      }
    return FALSE;
    }
  }
