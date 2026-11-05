define([
    'Magento_Checkout/js/view/payment/default',
    'mage/url'
], function (Component, url) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'SecurePay_SecureFrame/payment/secureframe',
            redirectAfterPlaceOrder: false
        },

        afterPlaceOrder: function () {
            window.location.replace(url.build('secureframe/payment/redirect'));
        }
    });
});
