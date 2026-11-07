define([
    'Magento_Checkout/js/view/payment/default',
    'mage/url'
], function (Component, url) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Eway_Rapid/payment/eway',
            redirectAfterPlaceOrder: false
        },

        afterPlaceOrder: function () {
            window.location.replace(url.build('eway/payment/start'));
        }
    });
});
