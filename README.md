# flagship-for-magento

Magento2 module for FlagShip.

# Installation

@MagentoRoot

## Composer Install
```
composer require flagshipcompany/flagship-for-magento:1.0.0
composer update 
bin/magento module:enable Flagship_Shipping
bin/magento setup:upgrade
```

## Directory Install

Download flagship-for-magento.zip

```
unzip flagship-for-magento.zip
cd @MagentoRoot/app/code
mkdir Flagship
cd Flagship
mkdir Shipping
cd Shipping
cp ~/flagship-for-magento/* .

bin/magento setup:upgrade
```

# Usage

Login to Magento Admin.

Under FlagShip Configuration, set the API Token.
Make sure that Store address is set under Store > Configuration > General > Store Information

Every order now shows you the option to Ship with FlagShip.
