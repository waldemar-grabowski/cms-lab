jQuery(function ($) {
    let $notice = $('#bookly-collect-stats-notice');
    function close() {
        $.post(ajaxurl, {action: $notice.data('action'), csrf_token: BooklyL10nGlobal.csrf_token});
        $notice.closest('.wrap').slideUp(150, function () {
            $(this).remove();
        });
    }
    $notice.on('click', '[data-dismiss=alert]', close);
    $notice.find('#bookly-enable-collecting-stats-btn').on('click', function () {
        $(this).addClass('bookly:btn-loading');
        $.post(ajaxurl, {action: 'bookly_enable_collecting_stats', csrf_token: BooklyL10nGlobal.csrf_token}, close);
    });
});
