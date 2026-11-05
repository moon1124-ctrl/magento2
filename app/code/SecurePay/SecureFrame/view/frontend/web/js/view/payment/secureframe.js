define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push({
        type: 'secureframe',
        component: 'SecurePay_SecureFrame/js/view/payment/method-renderer/secureframe-method'
    });

    return Component.extend({});
});
