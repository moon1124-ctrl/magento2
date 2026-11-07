define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push({
        type: 'eway',
        component: 'Eway_Rapid/js/view/payment/method-renderer/eway-method'
    });

    return Component.extend({});
});
