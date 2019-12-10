<?php

use \Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'Flagship_Shipping',
    __DIR__
);
require BP.'/vendor/autoload.php';

define('FLAGSHIP_MODULE','Magento');
define('FLAGSHIP_MODULE_VERSION','1.0.31');
