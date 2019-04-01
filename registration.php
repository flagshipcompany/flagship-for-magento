<?php

use \Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'Flagship_Shipping',
    __DIR__
);
require BP.'/vendor/autoload.php';

//NO Tailing slashes please
define('SMARTSHIP_WEB_URL','https://smartship-ng.flagshipcompany.com');
define('SMARTSHIP_API_URL','https://api.smartship.io');
define('FLAGSHIP_MODULE','Magento');
define('FLAGSHIP_MODULE_VERSION','1.0.13');
