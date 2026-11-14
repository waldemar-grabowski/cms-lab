jQuery(function ($) {
    'use strict';

    let $rate = $('#bookly-js-rate-bookly'),
        data = {
            action: 'bookly_hide_until_rate_notice',
            csrf_token: BooklyL10nGlobal.csrf_token,
            hide_until: 'long-time'
        };

    function close() {
        $.post(ajaxurl, data);
        $rate.closest('.wrap').slideUp(150, function () {
            $(this).remove();
        });
    }

    $rate
        .on('click', '.bookly-js-ok', function () {
            window.open(BooklyRateL10n.reviewsUrl, '_blank');
            data.hide_until = 'forever';
            close();
        })
        .on('click', '.bookly-js-maybe-later', function () {
            data.hide_until = 'short-time';
            close();
        })
        .on('click', '.bookly-js-dismiss', close);
});
