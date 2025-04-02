# flagship-for-magento

Flagship shipping module for Magento 2.4.7.

Overview
==================

Flagship for Magento provides you with real time shipping rates on your store during checkout.

**Compatibility**

| Flagship for Magento | **1.0** | **2.0** |
|----------------------|---------|---------|
| **Magento 2.3.0 - 2.3.7**      | ✓       |    |
| **Magento 2.4.7**              |      | ✓       | 


🛠️ Installation
-------------
**Using Composer**

*Run the commands from the root of the Magento installation*
```
composer require flagshipcompany/flagship-for-magento
```

**Using Zip**
* Download the Zip File
* Extract & upload the files to `/path/to/magento2/app/code/Flagship/Shipping/`

After installation by either means, enable the extension by running following commands (again from root of Magento2 installation):
```
php bin/magento module:enable Flagship_Shipping --clear-static-content
php bin/magento setup:upgrade
```

# Setting Up FlagShip

Login to Magento Admin.

Under FlagShip Configuration, set the API Token, Packing and Logging preferences.

Make sure that Store Address is set under Store > Configuration > General > Store Information

Enable the shipping methods, Store > Configuration > Sales > Shipping Methods > FlagShip Shipping


# Usage

## Shipping

When a customer places an order, the selected shipping methods are shown and the customer can choose a shipping method.

To make a FlagShip shipment, Sales > Orders > Order# 123 > Send To FlagShip

Order# 123 > Confirm FlagShip Shipment

You can modify the shipment and choose a different shipping method from the customer's selection while confirming the shipment.

Once the shipment is confirmed, you can print the shipping label, track the shipment.

## Exclude Taxes From Magento on Shipping

Magento Backend > Stores > Configuration > Sales > Tax > Calculation Settings > Shipping Prices : Excluding Tax
