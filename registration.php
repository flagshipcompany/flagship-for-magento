<?php

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'Flagship_Shipping',
    __DIR__
);

define('FLAGSHIP_MODULE', 'Magento');
define('FLAGSHIP_MODULE_VERSION', '2.0.3');
