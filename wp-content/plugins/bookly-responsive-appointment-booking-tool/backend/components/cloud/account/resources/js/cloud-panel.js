jQuery(function ($) {
    'use strict';

    if (typeof BooklyCloudAccount === 'undefined' || typeof BooklyL10nCloudAccount === 'undefined') {
        return;
    }

    BooklyCloudAccount.show('bookly-cloud-account', BooklyL10nCloudAccount);

    // Links in emails point to the notification settings of the account.
    if (window.location.hash.replace(/^#/, '').split('&').indexOf('notifications-settings') !== -1) {
        $('[href="#bookly-account-notifications-tab"]').trigger('click');
    }
});
