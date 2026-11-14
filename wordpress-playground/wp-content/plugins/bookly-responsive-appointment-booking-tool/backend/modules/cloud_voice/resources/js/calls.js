jQuery(function ($) {
    'use strict';

    const { today, getLocalTimeZone, parseDate } = window.BooklyDatatables.calendarDate;

    /**
     * Notifications Tab
     */
    BooklyNotificationsList();
    BooklyNotificationDialog();

    var $phone_input = $('#admin_phone');
    if (BooklyL10n.intlTelInput.enabled) {
        window.booklyIntlTelInput($phone_input.get(0), {
            preferredCountries: [BooklyL10n.intlTelInput.country],
            initialCountry: BooklyL10n.intlTelInput.country,
            geoIpLookup: function (callback) {
                $.get('https://ipinfo.io', function () {}, 'jsonp').always(function (resp) {
                    var countryCode = (resp && resp.country) ? resp.country : '';
                    callback(countryCode);
                });
            }
        });
    }

    $('#test_call').on('click', function (e) {
        e.preventDefault();
        $.ajax({
            url: ajaxurl,
            data: {
                action: 'bookly_make_test_call',
                csrf_token: BooklyL10nGlobal.csrf_token,
                phone_number: BooklyL10n.intlTelInput.enabled ? booklyGetPhoneNumber($phone_input.get(0)) : $phone_input.val()
            },
            dataType: 'json',
            success: function (response) {
                if (response.success) {
                    booklyAlert({success: [response.data.message]});
                } else {
                    booklyAlert({error: [response.data.message]});
                }
            }
        });
    });

    $('[data-action=save-administrator-phone]')
        .on('click', function (e) {
            e.preventDefault();
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'bookly_save_administrator_phone',
                    bookly_sms_administrator_phone: BooklyL10n.intlTelInput.enabled ? booklyGetPhoneNumber($phone_input.get(0)) : $phone_input.val(),
                    csrf_token: BooklyL10nGlobal.csrf_token
                },
                success: function (response) {
                    if (response.success) {
                        booklyAlert({success: [BooklyL10n.settingsSaved]});
                    }
                }
            });
        });

    /**
     * Voice Details Tab.
     */
    $('[href="#details"]').one('click', function () {
        const detailsTable = 'voice_details';

        const tz = getLocalTimeZone();
        const t = today(tz);
        const startOfMonth = d => d.set({ day: 1 });
        const endOfMonth = d => d.set({ day: 1 }).add({ months: 1 }).subtract({ days: 1 });
        const ymd = d => d.year + '-' + String(d.month).padStart(2, '0') + '-' + String(d.day).padStart(2, '0');

        const datePresets = [
            { label: BooklyL10n.dateRange.yesterday, range: { start: t.subtract({ days: 1 }),  end: t.subtract({ days: 1 }) } },
            { label: BooklyL10n.dateRange.today,     range: { start: t,                        end: t                       } },
            { label: BooklyL10n.dateRange.last_7,    range: { start: t.subtract({ days: 7 }),  end: t                       } },
            { label: BooklyL10n.dateRange.last_30,   range: { start: t.subtract({ days: 30 }), end: t                       } },
            { label: BooklyL10n.dateRange.thisMonth, range: { start: startOfMonth(t),                         end: endOfMonth(t)                         } },
            { label: BooklyL10n.dateRange.lastMonth, range: { start: startOfMonth(t.subtract({ months: 1 })), end: endOfMonth(t.subtract({ months: 1 })) } },
        ];

        let dateValue;
        const savedFilter = BooklyL10n.datatables[detailsTable].settings.filter || {};
        if (savedFilter.range && savedFilter.range !== 'any') {
            const parts = String(savedFilter.range).split(' - ');
            if (parts.length === 2) {
                try { dateValue = { start: parseDate(parts[0].trim()), end: parseDate(parts[1].trim()) }; } catch (e) { /* ignore */ }
            }
        }
        function serializeDate(value) {
            if (!value || !value.start || !value.end) return 'any';
            return ymd(value.start) + ' - ' + ymd(value.end);
        }

        const voiceErrorBadgeClass = 'bookly:bg-red-100 bookly:text-red-800 bookly:border-red-200';
        const voiceStatusBadgeClass = {
            'completed':     'bookly:bg-green-100 bookly:text-green-800 bookly:border-green-200',
            'pending':       'bookly:bg-amber-100 bookly:text-amber-800 bookly:border-amber-200',
            'busy':          voiceErrorBadgeClass,
            'failed':        voiceErrorBadgeClass,
            'no-answer':     voiceErrorBadgeClass,
            'out-of-credit': voiceErrorBadgeClass,
        };

        let columns = [];
        $.each(BooklyL10n.datatables[detailsTable].settings.columns, function (column, show) {
            switch (column) {
                case 'status':
                    columns.push({
                        data: column,
                        render: function (data) {
                            return BooklyL10n.status.hasOwnProperty(data)
                                ? BooklyL10n.status[data]
                                : (data.charAt(0).toUpperCase() + data.slice(1)).replaceAll('-', ' ');
                        },
                        badge: function (row) { return voiceStatusBadgeClass[row.status] || voiceErrorBadgeClass; },
                    });
                    break;
                default:
                    columns.push({data: column, render: BooklyDatatables.escapeHtml()});
                    break;
            }
            columns[columns.length - 1].title    = BooklyL10n.datatables[detailsTable].titles[column] || column;
            columns[columns.length - 1].name     = column;
            columns[columns.length - 1].show     = show;
            columns[columns.length - 1].orderable = false;
        });

        if (columns.length) {
            BooklyDatatables.showForm('bookly-' + detailsTable + '-datatables', {
                noCheckboxes: true,
                datePicker: BooklyL10n.datePicker,
                ajax: {
                    url: ajaxurl,
                    method: 'POST',
                    data: function (d) {
                        return $.extend({}, d, {
                            action: 'bookly_get_calls_list',
                            csrf_token: BooklyL10nGlobal.csrf_token,
                            filter: { range: serializeDate(dateValue) }
                        });
                    }
                },
                columns: columns,
                tableSettings: Object.assign({}, BooklyL10n.datatables[detailsTable], {
                    l10n: Object.assign({}, BooklyL10n.datatables.l10n, {zeroRecords: BooklyL10n.zeroRecords})
                }),
                saveSettings: function (settings) {
                    $.post(ajaxurl, Object.assign({
                        action: 'bookly_update_table_settings',
                        table: detailsTable,
                        csrf_token: BooklyL10nGlobal.csrf_token
                    }, settings));
                },
                filters: [{
                    type: 'dateRange',
                    name: 'date',
                    label: BooklyL10n.filters.date,
                    initialValue: dateValue,
                    presets: datePresets,
                    onChange: function (v) { dateValue = v; },
                }],
            });
        }
    });

    /**
     * Settings Tab.
     */
    $('.bookly-js-voice-settings-save')
        .on('click', function () {
            let ladda = Ladda.create(this);
            ladda.start();
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'bookly_cloud_voice_save_settings',
                    csrf_token: BooklyL10nGlobal.csrf_token,
                    language: $('#bookly_cloud_voice_language', $('#settings')).val(),
                },
                dataType: 'json',
                success: function (response) {
                    if (response.success) {
                        booklyAlert({success: [BooklyL10n.settingsSaved]});
                    } else {
                        booklyAlert({error: [response.data.message]});
                    }
                    ladda.stop();
                }
            });
        });

    /**
     * Prices Tab.
     */
    const pricesTable = 'voice_prices';
    let columns = [];

    function formatPrice(number) {
        number = number.replace(/0+$/, '');
        if ((number + '').split('.')[1].length === 1) {
            return '$' + number + '0';
        }
        return '$' + number;
    }

    $.each(BooklyL10n.datatables[pricesTable].settings.columns, function (column, show) {
        switch (column) {
            case 'country_iso_code':
                columns.push({
                    data: column,
                    class: 'bookly:w-8',
                    render: function (data, type, row, meta) {
                        return '<div class="iti__flag iti__' + data + '"></div>';
                    }
                });
                break;
            case 'call_price':
                columns.push({
                    data: column,
                    class: 'bookly:text-right',
                    render: function (data, type, row, meta) {
                        return formatPrice(data);
                    }
                });
                break;
            default:
                columns.push({data: column, render: BooklyDatatables.escapeHtml()});
                break;
        }
        columns[columns.length - 1].title = BooklyL10n.datatables[pricesTable].titles[column] || column;
        columns[columns.length - 1].name  = column;
        columns[columns.length - 1].show  = show;
    });

    if (columns.length) {
        BooklyDatatables.showForm('bookly-' + pricesTable + '-datatables', {
            serverSide: false,
            reloadButton: false,
            noCheckboxes: true,
            ajax: {
                url: ajaxurl,
                method: 'POST',
                data: function (d) {
                    return $.extend({}, d, {
                        action: 'bookly_get_voice_price_list',
                        csrf_token: BooklyL10nGlobal.csrf_token
                    });
                }
            },
            columns: columns,
            tableSettings: Object.assign({}, BooklyL10n.datatables[pricesTable], {
                l10n: Object.assign({}, BooklyL10n.datatables.l10n, {zeroRecords: BooklyL10n.zeroRecords})
            }),
            saveSettings: function (settings) {
                $.post(ajaxurl, Object.assign({
                    action: 'bookly_update_table_settings',
                    table: pricesTable,
                    csrf_token: BooklyL10nGlobal.csrf_token
                }, settings));
            }
        });
    }

    $('#bookly-js-test-voice-notifications').on('click', function () {
        BooklyTestingVoiceDialog.showDialog();
    });

    /**
     * Tab switching.
     */
    const
        $voice_tabs    = $('#voice_tabs'),
        $voice_content = $('#voice_tabs_content'),
        $voice_footer  = $('.bookly-js-voice-settings-footer');
    $voice_tabs.on('click', 'li', function (e) {
        e.preventDefault();
        $('li a', $voice_tabs).removeClass('bookly:active');
        $(this).find('a').addClass('bookly:active');
        $('>', $voice_content).removeClass('bookly:active');
        const href = $(this).find('a').attr('href');
        $voice_content.find(href).addClass('bookly:active');
        $voice_footer.prop('hidden', href !== '#settings');
    });
});
