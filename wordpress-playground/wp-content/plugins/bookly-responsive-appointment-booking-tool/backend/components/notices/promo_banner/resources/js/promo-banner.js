jQuery(function ($) {
    'use strict';

    const $banner = $('#bookly-promo-banner');
    const page = new URLSearchParams(window.location.search).get('page');
    $banner.on('click', '[data-button]', function () {
        booklyTrackUsage('promo_banner.click', {
            banner: $banner.data('id'),
            page: page ? page.replace(/^bookly-/, '') : '',
            preset: $(this).data('preset'),
            button: $(this).data('button'),
        });
    });
    $banner.on('click', '.bookly-js-dismiss', function () {
        $.post(ajaxurl, { action: 'bookly_dismiss_promo_banner', id: $banner.data('id'), page: page, csrf_token: BooklyL10nGlobal.csrf_token });
        $banner.slideUp(150, function () { $(this).remove(); });
    });
});
