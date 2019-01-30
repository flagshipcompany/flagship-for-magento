<?php
 
namespace Flagship\Shipping\Controller\Adminhtml\ShowLog;
 
use Magento\Framework\App\Action\Context;
 
class Download extends \Magento\Framework\App\Action\Action
{
    
    public function __construct(
        Context $context,
        \Magento\Framework\App\Response\Http\FileFactory $fileFactory,
        \Magento\Framework\Filesystem\DirectoryList $directory
    ) {
        $this->_downloader =  $fileFactory;
        $this->directory = $directory;
        parent::__construct($context);
    }
 
    public function execute()
    {
        $fileName = 'flagship_logs.txt';
        $file = BP.'/var/log/flagship.log';
        $logs = '';
        $file_handle = fopen($file,"r");
        while (!feof($file_handle)) {
            $logs .= fgets($file_handle);
        }
        fclose($file_handle);

        return $this->_downloader->create(
            $fileName,
            $logs
        );
    }
}