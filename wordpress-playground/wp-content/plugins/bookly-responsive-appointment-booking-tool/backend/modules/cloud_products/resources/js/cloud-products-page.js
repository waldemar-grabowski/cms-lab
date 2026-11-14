jQuery(function () {
    'use strict';

    if (typeof BooklyCloudProducts === 'undefined' || typeof BooklyL10nCloudProducts === 'undefined') {
        return;
    }

    BooklyCloudProducts.show('bookly-cloud-products', BooklyL10nCloudProducts);
});
