jQuery(function ($) {
    let $alert  = $('#bookly-nps-notice'),
        $quiz   = $('#bookly-nps-quiz'),
        $msg    = $('#bookly-nps-msg'),
        $email  = $('#bookly-nps-email'),
        $form   = $('#bookly-nps-form'),
        $rate   = $('#bookly-js-rate-bookly'),
        rating  = 0;

    // Init stars.
    $quiz.on('mouseenter', '.bookly-js-star', function () {
        rating = $(this).index();
        $('.bookly-js-star', $quiz).each(function () {
            $(this).toggleClass('bookly-js-active', $(this).index() <= rating);
        });
        rating += 1;
    }).on('click', '.bookly-js-star', function () {
        if (rating <= 6) {
            $form.show();
        } else {
            $.post(ajaxurl, {action: 'bookly_nps_send', csrf_token: BooklyL10nGlobal.csrf_token, rate: rating});
            $alert.closest('.wrap').remove();
            let $title = $('.bookly-js-alert-title', $rate),
                text = $title.html();
            $title.html(text.replace('{star}', rating));
            $rate.removeClass('bookly-js-hidden');
        }
    });

    $('#bookly-nps-btn').on('click', function () {
        let $btn = $(this);
        $msg.removeClass('is-invalid');
        if ($msg.val() == '') {
            $msg.addClass('is-invalid');
        } else {
            $btn.addClass('bookly:btn-loading');
            $.post(
                ajaxurl,
                {
                    action: 'bookly_nps_send',
                    csrf_token: BooklyL10nGlobal.csrf_token,
                    rate: rating,
                    msg: $msg.val(),
                    email: $email.val()
                },
                function (response) {
                    $btn.removeClass('bookly:btn-loading');
                    if (response.success) {
                        close();
                        booklyAlert({success: [response.data.message]});
                    }
                }
            );
        }
    });

    function close() {
        $alert.closest('.wrap').slideUp(150);
        $.post(ajaxurl, {action: 'bookly_dismiss_nps_notice', csrf_token: BooklyL10nGlobal.csrf_token}, function () {
            // Indicator for Selenium that request has completed.
            $('.bookly-js-nps-notice').remove();
        });
    }
    $alert.on('click', '[data-dismiss=alert]', close);
});
