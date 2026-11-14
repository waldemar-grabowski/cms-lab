jQuery(function ($) {
    'use strict';

    window.BooklyNotificationsList = function () {
        const table = BooklyL10n.gateway + '_notifications';
        const $modalTestEmail = $('#bookly-test-email-notifications-modal');
        const $btnTestEmail = $('#bookly-js-test-email-notifications');
        const $testNotificationsList = $('#bookly-js-test-notifications-list', $modalTestEmail);

        const stateBadgeClass = {
            1: 'bookly:bg-green-100 bookly:text-green-800 bookly:border-green-200', // enabled
            0: 'bookly:bg-gray-100 bookly:text-gray-700 bookly:border-gray-200',    // disabled
        };

        /**
         * Init Columns.
         */
        const columns = [];
        $.each(BooklyL10n.datatables[table].settings.columns, function (column, show) {
            switch (column) {
                case 'id':
                    columns.push({
                        data: column,
                        orderMethod: 'numeric',
                        class: 'bookly:w-8',
                        render: function (data) { return BooklyDatatables.escapeHtml(data); }
                    });
                    break;
                case 'type':
                    columns.push({
                        data: 'order',
                        searchable: false,
                        noCopy: true,
                        class: 'bookly:w-8',
                        render: function (data, type, row) {
                            return '<span class="hidden">' + data + '</span><i class="fa-fw ' + row.icon + '" title="' + row.title + '"></i>';
                        }
                    });
                    break;
                case 'active':
                    columns.push({
                        data: column,
                        searchable: false,
                        render: function (data) { return BooklyL10n.state[data]; },
                        badge: function (row) { return stateBadgeClass[row.active == 1 ? 1 : 0]; },
                    });
                    break;
                default:
                    columns.push({
                        data: column,
                        render: function (data) { return BooklyDatatables.escapeHtml(data); }
                    });
                    break;
            }
            columns[columns.length - 1].title = BooklyL10n.datatables[table].titles[column] || column;
            columns[columns.length - 1].name = column;
            columns[columns.length - 1].show = show;
        });

        function setState(state) {
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'bookly_set_notifications_state',
                    csrf_token: BooklyL10nGlobal.csrf_token,
                    ids: bt.getCheckedRows().map(function (row) { return row.id; }),
                    active: state
                },
                dataType: 'json',
                success: function (response) {
                    if (response.success) {
                        bt.reload();
                        booklyAlert({ success: [BooklyL10n.settingsSaved] });
                    }
                }
            });
        }

        const bt = BooklyDatatables.showForm('bookly-' + table + '-datatables', {
            serverSide: false,
            ajax: {
                url: ajaxurl,
                method: 'POST',
                data: function (d) {
                    return $.extend({}, d, {
                        action: 'bookly_get_notifications',
                        csrf_token: BooklyL10nGlobal.csrf_token,
                        gateway: BooklyL10n.gateway
                    });
                }
            },
            columns: columns,
            tableSettings: Object.assign({}, BooklyL10n.datatables[table], { l10n: Object.assign({}, BooklyL10n.datatables.l10n, { zeroRecords: BooklyL10n.zeroRecords }) }),
            notice: BooklyL10n.cron_notice,
            noticePosition: 'bottom',
            edit: function (row) {
                const event = new CustomEvent('bookly:edit-notification', { detail: { id: row.id } });
                window.dispatchEvent(event);
            },
            checked: function (rows) {
                const hasEnabled = rows.some(function (row) { return row.active === '1'; });
                const hasDisabled = rows.some(function (row) { return row.active !== '1'; });
                const actions = [];
                if (hasDisabled) {
                    actions.push({
                        label: BooklyL10n.enable,
                        icon: 'play',
                        variant: 'outline',
                        click: function () { setState(1); }
                    });
                }
                if (hasEnabled) {
                    actions.push({
                        label: BooklyL10n.disable,
                        icon: 'ban',
                        variant: 'outline',
                        click: function () { setState(0); }
                    });
                }
                actions.push({
                    label: BooklyL10n.delete,
                    icon: 'trash',
                    variant: 'destructive',
                    click: function (selected) {
                        if (!confirm(BooklyL10n.areYouSure)) return;
                        $.ajax({
                            url: ajaxurl,
                            method: 'POST',
                            data: {
                                action: 'bookly_delete_notifications',
                                csrf_token: BooklyL10nGlobal.csrf_token,
                                notifications: selected.map(function (row) { return row.id; })
                            },
                            dataType: 'json',
                            success: function (response) { if (response.success) bt.reload(); }
                        });
                    }
                });
                return actions;
            },
            saveSettings: function (settings) {
                $.post(ajaxurl, Object.assign({
                    action: 'bookly_update_table_settings',
                    table: table,
                    csrf_token: BooklyL10nGlobal.csrf_token
                }, settings));
            },
            topToolbar: [{
                id: 'bookly-js-new-notification',
                label: BooklyL10n.new_notification,
                icon: 'plus',
                variant: 'default',
                click: function () {
                    const event = new CustomEvent('bookly:edit-notification');
                    window.dispatchEvent(event);
                }
            }],
            searchFilter: { placeholder: BooklyL10n.quick_search, name: 'filter' }
        });

        $('[href="#bookly-js-auto"]').click(function () {
            if (this.classList.contains('toggle')) {
                $(this).removeClass('border rounded mb-3 toggle');
                $(this).addClass('border-light rounded-top bg-light');
            } else {
                $(this).removeClass('border-light rounded-top bg-light');
                $(this).addClass('border rounded mb-3 toggle');
            }
        });

        $btnTestEmail.on('click', function () { $modalTestEmail.booklyModal(); });

        const $check = $('<div/>', { class: 'bookly-dropdown-item my-0 pl-3' }).append(
            $('<div>', { class: 'custom-control custom-checkbox' }).append(
                $('<input>', { class: 'custom-control-input', type: 'checkbox' }),
                $('<label>', { class: 'custom-control-label text-wrap w-100' })
            )
        );

        $modalTestEmail
            .on('change', '#bookly-check-all-entities', function () {
                $(':checkbox', $testNotificationsList).prop('checked', this.checked);
                $(':checkbox:first-child', $testNotificationsList).trigger('change');
            })
            .on('click', '[for=bookly-check-all-entities]', function (e) { e.stopPropagation(); })
            .on('click', '.btn-success', function () {
                const ladda = Ladda.create(this);
                const data = $(this).closest('form').serializeArray();
                ladda.start();
                $(':checked', $testNotificationsList).each(function () {
                    data.push({ name: 'notification_ids[]', value: $(this).data('notification-id') });
                });
                data.push({ name: 'action', value: 'bookly_test_email_notifications' });
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: data,
                    dataType: 'json',
                    success: function (response) {
                        ladda.stop();
                        if (response.success) {
                            booklyAlert({ success: [BooklyL10n.sentSuccessfully] });
                            $modalTestEmail.booklyModal('hide');
                        }
                    }
                });
            })
            .on('shown.bs.modal', function () {
                const $send = $(this).find('.btn-success');
                let active = 0;
                $send.prop('disabled', true);
                $testNotificationsList.html('');
                bt.getRows().forEach(function (notification) {
                    const $cloneCheck = $check.clone();
                    $('label', $cloneCheck).html(notification.name).attr('for', 'bookly-n-' + notification.id)
                        .on('click', function (e) { e.stopPropagation(); });
                    $(':checkbox', $cloneCheck)
                        .prop('checked', notification.active == '1')
                        .attr('id', 'bookly-n-' + notification.id)
                        .data('notification-id', notification.id);
                    $testNotificationsList.append($cloneCheck);
                    if (notification.active == '1') active++;
                });
                $('.bookly-js-count', $modalTestEmail).html(active);
                $send.prop('disabled', false);
            });

        $testNotificationsList.on('change', ':checkbox', function () {
            $('.bookly-js-count', $modalTestEmail).html($(':checked', $testNotificationsList).length);
        });
    };
});
