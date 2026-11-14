jQuery(function () {
    'use strict';

    if (typeof BooklyCloudSettings === 'undefined' || typeof BooklyL10nCloudSettings === 'undefined') {
        return;
    }

    BooklyCloudSettings.show('bookly-cloud-settings', BooklyL10nCloudSettings);
});
