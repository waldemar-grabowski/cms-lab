jQuery(function ($) {
    let $notice = $('#bookly-sms-promotion-notice'),
        type = $notice.data('type'),
        id = $notice.data('id');
    function close() {
        $.post(ajaxurl, {
            action: 'bookly_dismiss_sms_promotion_notice',
            id: id,
            dismiss: $notice.data('dismiss'),
            csrf_token: BooklyL10nGlobal.csrf_token
        });
        $notice.closest('.wrap').slideUp(150, function () {
            $(this).remove();
        });
    }
    $notice.on('click', '[data-dismiss=alert]', close);
    $notice.find('.bookly-js-remind-me-later').on('click', function () {
        $notice.data('dismiss', 'remind');
        close();
    });
    $notice.find('.bookly-js-apply-action').on('click', function () {
        switch (type) {
            case 'registration':
                BooklyCloudAccount.showAuth('register');
                break;
            default:
                BooklyCloudAccount.showRecharge();
                break;
        }
        close();
    });
});
