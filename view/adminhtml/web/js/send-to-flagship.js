define([
    'jquery', 
    'mage/url', 
    'Magento_Ui/js/modal/alert', 
    'mage/translate'
    ], function ($, urlBuilder, uiAlert, $t) {
    'use strict';

    window.flagshipSendShipment = function (ajaxUrl) {
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                form_key: window.FORM_KEY // Required for admin security
            },
            showLoader: true, // Shows Magento’s loader spinner
            success: function (response) {
                let message = response.message || $t('Shipment sent successfully to FlagShip! Please submit the shipment');
                uiAlert({ title: $t('FlagShip'), content: message });
            },
            error: function (xhr) {
                let message = $t('An error occurred while sending to FlagShip. Please check the logs for more details.');
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    message = xhr.responseJSON.message;
                }
                uiAlert({ title: $t('Error'), content: message });
            }
        });
    };
});
