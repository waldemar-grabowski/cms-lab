jQuery(function ($) {
    let $notice = $('#bookly-powered-by');
    function close() {
        $.post(ajaxurl, {action: $notice.data('action'), csrf_token: BooklyL10nGlobal.csrf_token});
        $notice.closest('.wrap').slideUp(150, function () {
            $(this).remove();
        });
    }
    $notice.on('click', '[data-dismiss=alert]', close);
    $notice.on('click', '#bookly-show-powered-by', function () {
        $(this).addClass('bookly:btn-loading');
        $.post(ajaxurl, {action: 'bookly_enable_show_powered_by', csrf_token: BooklyL10nGlobal.csrf_token}, close);
    });
});
