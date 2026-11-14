jQuery(function ($) {
    let $alert = $('#bookly-subscribe-notice');
    function close() {
        $alert.closest('.wrap').slideUp(150);
        $.post(ajaxurl, {action: 'bookly_dismiss_subscribe_notice', csrf_token: BooklyL10nGlobal.csrf_token}, function () {
            // Indicator for Selenium that request has completed.
            $alert.closest('.wrap').remove();
        });
    }
    $('#bookly-subscribe-btn').on('click', function () {
        let $email = $('#bookly-subscribe-email', $alert),
            $btn = $(this);
        $email.removeClass('is-invalid');
        $btn.addClass('bookly:btn-loading');
        $.post(ajaxurl, {action: 'bookly_subscribe', csrf_token: BooklyL10nGlobal.csrf_token, email: $email.val()}, function (response) {
            $btn.removeClass('bookly:btn-loading');
            if (response.success) {
                close();
                booklyAlert({success: [response.data.message]});
            } else {
                $email.addClass('is-invalid');
                booklyAlert({error: [response.data.message]});
            }
        });
    });
    $alert.on('click', '[data-dismiss=alert]', close);
});
