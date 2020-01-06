# flagship-for-magento

Magento2 module for FlagShip.

# Installation

Navigate to your Magento directory and execute the following commands

## Composer Install (Preferred)

```
composer require flagshipcompany/flagship-for-magento:^1.0
composer update
bin/magento module:enable Flagship_Shipping
bin/magento setup:upgrade
```

## Directory Install

Download flagship-for-magento.zip

```
@MagentoRoot > composer require flagshipcompany/flagship-api-sdk:^1.1
unzip flagship-for-magento.zip
cd @MagentoRoot/app/code
mkdir Flagship
cd Flagship
mkdir Shipping
cd Shipping
cp ~/flagship-for-magento/* .

bin/magento setup:upgrade
```

# Setting Up FlagShip

Login to Magento Admin.

Under FlagShip Configuration, set the API Token, Packing and Logging preferences.

Make sure that Store Address is set under Store > Configuration > General > Store Information

Every source should be assigned an address. You can set it: Store > Sources > (Edit the source) > Address Data 

Enable the shipping methods, Store > Configuration > Sales > Shipping Methods > FlagShip Shipping


# Usage

## Shipping

When a customer places an order, the selected shipping methods are shown and the customer can choose a shipping method.

To make a FlagShip shipment, Sales > Orders > Order# 123 > Ship > Send To FlagShip

Order# 123 > Shipments > Shipment# 0001 > Confirm FlagShip Shipment

You can choose a different shipping method from the customer's selection while confirming the shipment.

Once the shipment is confirmed, you can print the shipping label, track the shipment.

## Packing

FlagShip > Packing Boxes.

You can view a list of all the boxes that you have already set and add more boxes.

FlagShip tells you which box should be used for packing which items.

Sales > Orders > Order# 123 > Scroll down to FlagShip Shipping Details

## Logs

You can view logs for all FlagShip activity - FlagShip > Display logs

## FlagShip

You can Manage your FlagShip shipments, pickups and create pickups for your shipments from your Magento store.
