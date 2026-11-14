jQuery(function ($) {
    let $notice = $('#bookly-wpml-resave-notice');
    $notice.on('click', '[data-dismiss=alert]', function () {
        $.post(ajaxurl, {action: $notice.data('action'), csrf_token: BooklyL10nGlobal.csrf_token});
        $notice.closest('.wrap').slideUp(150, function () {
            $(this).remove();
        });
    });
});
