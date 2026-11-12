/**
 * Luma only shows sidebar totals after the shipping step; show them whenever totals exist.
 * The shipping line waits until a shipping method is chosen.
 */
define(['Magento_Checkout/js/model/quote'], function (quote) {
    'use strict';

    return function (Component) {
        return Component.extend({
            isFullMode: function () {
                if (!this.getTotals()) {
                    return false;
                }

                return this.index !== 'shipping' || !!quote.shippingMethod();
            }
        });
    };
});
