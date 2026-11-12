/**
 * FroggaBio checkout: show order totals in the sidebar from the first step, as in the mockup.
 */
var config = {
    config: {
        mixins: {
            'Magento_Checkout/js/view/summary/abstract-total': {
                'js/froggabio-full-totals': true
            }
        }
    }
};
