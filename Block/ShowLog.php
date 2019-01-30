<?php

namespace Flagship\Shipping\Block;

class ShowLog extends \Magento\Framework\View\Element\Template{

    
    public function __construct(\Magento\Framework\View\Element\Template\Context $context){
        parent::__construct($context);
    }

    public function getTitle(){
        $count = count($this->getLogs()) - 1;
        $msg = "Displaying last ".$count." lines from flagship.log";
        if($count > 100){
            $msg = "Displaying last 100 lines from flagship.log";
        }
        return $count != 0 ? $msg : "Logs are clear";
    }

    public function getLogs(){
        $logs =[];
        $file_handle = fopen(BP.'/var/log/flagship.log',"r");
        
        while (!feof($file_handle)) {
           $logs[]= fgets($file_handle) ;
        }
        fclose($file_handle);
        return $logs;
    }

    public function getDownloadLink(){
        return $this->getUrl('shipping/showlog/download');
    }

    public function getClearLogsLink(){
        return $this->getUrl('shipping/showlog/clear');
    }

    public function getLogsAsString(){

        $logs = array_reverse($this->getLogs());
        
        $logs_str = '';
        $i = 0;

        while($i < 101 && $i < count($logs)){
            $logs_str .= $logs[$i] ? $logs[$i]."<br>" : "";
            $i++;
        }
        return $logs_str;
    }
}
