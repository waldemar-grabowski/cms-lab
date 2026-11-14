jQuery(function($) {
    'use strict';

    let
        $customersList = $('#bookly-customers-datatables'),
        $deleteDialog = $('#bookly-delete-dialog'),
        $deleteButton = $('.bookly-js-delete', $deleteDialog),
        $rememberCheckbox = $('#bookly-js-remember-choice-checkbox', $deleteDialog),
        $deleteEventsCheckbox = $('#bookly-js-delete-with-events-checkbox', $deleteDialog),
        $deleteWPUserCheckbox = $('#bookly-js-delete-with-wp-user-checkbox', $deleteDialog),
        bt = BooklyDatatables.getForm('bookly-customers-datatables')
    ;

    /**
     * Delete customers. Triggered either by the legacy `data-action=delete` cell
     * button (kept for back-compat) or by the new datatables `checked()` action,
     * which fires a synthetic `bookly:customers-delete` event on the list element.
     */
    function handleDelete(e) {
        var ladda = e && e.currentTarget && e.currentTarget.tagName === 'BUTTON' ? Ladda.create(e.currentTarget) : null,
            customers = bt.getCheckedRows().map(function(row) { return row.id; });

        if (ladda) ladda.start();

        if (customers.length > 0) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'bookly_check_customers',
                    csrf_token: BooklyL10nGlobal.csrf_token,
                    customers: customers
                },
                dataType: 'json',
                success: function(response) {
                    if (ladda) ladda.stop();
                    $('.bookly-js-delete-with-events', $deleteDialog).toggle(response.data.exists_events);
                    $('.bookly-js-delete-without-events', $deleteDialog).toggle(!response.data.exists_events);

                    $deleteButton.prop('disabled', response.data.exists_events && !response.data.with_events);

                    $deleteEventsCheckbox.prop('checked', response.data.with_events);
                    $deleteWPUserCheckbox.prop('checked', response.data.with_wp_users);
                    $rememberCheckbox.prop('checked', response.data.remember);
                    $deleteDialog.booklyModal('show');
                }
            });
        } else if (ladda) {
            setTimeout(function() {
                ladda.stop();
            }, 200);
        }
    }

    $customersList.on('click', '[data-action=delete]', handleDelete);
    $customersList.on('bookly:customers-delete', handleDelete);

    $deleteEventsCheckbox.on('change', function() {
        $deleteButton.prop('disabled', !$deleteEventsCheckbox.prop('checked'));
    });

    $deleteButton.on('click', function() {
        var ladda = Ladda.create(this),
            customers =  bt.getCheckedRows().map(function(row) { return row.id; })
        ;

        ladda.start();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bookly_delete_customers',
                csrf_token: BooklyL10nGlobal.csrf_token,
                customers: customers,
                with_wp_users: $deleteWPUserCheckbox.prop('checked') ? 1 : 0,
                with_events: $deleteEventsCheckbox.prop('checked') ? 1 : 0,
                remember: $rememberCheckbox.prop('checked') ? 1 : 0
            },
            dataType: 'json',
            success: function(response) {
                ladda.stop();
                $deleteDialog.booklyModal('hide');
                if (response.success) {
                    bt.reload();
                } else {
                    alert(response.data.message);
                }
            }
        });
    });
});