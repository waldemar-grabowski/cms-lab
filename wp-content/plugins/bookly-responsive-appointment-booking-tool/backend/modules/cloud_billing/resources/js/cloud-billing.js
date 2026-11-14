jQuery(function ($) {
    'use strict';

    const purchasesTable = 'cloud_purchases';
    const { today, getLocalTimeZone, parseDate } = window.BooklyDatatables.calendarDate;
    const tz = getLocalTimeZone();
    const t = today(tz);
    const startOfMonth = d => d.set({ day: 1 });
    const endOfMonth = d => d.set({ day: 1 }).add({ months: 1 }).subtract({ days: 1 });
    const ymd = d => d.year + '-' + String(d.month).padStart(2, '0') + '-' + String(d.day).padStart(2, '0');

    /**
     * Serialize selected range to the backend wire format ('YYYY-MM-DD - YYYY-MM-DD' or 'any').
     */
    function serializeDate(value) {
        if (!value || !value.start || !value.end) return 'any';
        return ymd(value.start) + ' - ' + ymd(value.end);
    }

    /**
     * Restore previously saved range from user_meta.
     */
    let dateValue;
    const savedFilter = BooklyL10n.datatables[purchasesTable].settings.filter || {};
    if (savedFilter.range && savedFilter.range !== 'any') {
        const parts = String(savedFilter.range).split(' - ');
        if (parts.length === 2) {
            try {
                dateValue = { start: parseDate(parts[0].trim()), end: parseDate(parts[1].trim()) };
            } catch (e) { /* invalid format — leave undefined */ }
        }
    }

    const datePresets = [
        { label: BooklyL10n.dateRange.yesterday, range: { start: t.subtract({ days: 1 }),  end: t.subtract({ days: 1 }) } },
        { label: BooklyL10n.dateRange.today,     range: { start: t,                        end: t                       } },
        { label: BooklyL10n.dateRange.last_7,    range: { start: t.subtract({ days: 7 }),  end: t                       } },
        { label: BooklyL10n.dateRange.last_30,   range: { start: t.subtract({ days: 30 }), end: t                       } },
        { label: BooklyL10n.dateRange.thisMonth, range: { start: startOfMonth(t),                                  end: endOfMonth(t)                                 } },
        { label: BooklyL10n.dateRange.lastMonth, range: { start: startOfMonth(t.subtract({ months: 1 })),          end: endOfMonth(t.subtract({ months: 1 }))         } },
    ];

    const defaultBadgeClass = 'bookly:bg-gray-100 bookly:text-gray-700 bookly:border-gray-200';
    const purchaseStatusBadgeClass = {
        'Paid':               'bookly:bg-green-100 bookly:text-green-800 bookly:border-green-200',
        'Charged':            'bookly:bg-blue-100 bookly:text-blue-800 bookly:border-blue-200',
        'Pending':            'bookly:bg-amber-100 bookly:text-amber-800 bookly:border-amber-200',
        'Rejected':           'bookly:bg-red-100 bookly:text-red-800 bookly:border-red-200',
        'Refunded':           'bookly:bg-purple-100 bookly:text-purple-800 bookly:border-purple-200',
        'Reversed':           defaultBadgeClass,
        'Cancelled reversal': defaultBadgeClass,
    };

    let columns = [];

    $.each(BooklyL10n.datatables[purchasesTable].settings.columns, function (column, show) {
        switch (column) {
            case 'amount':
                columns.push({
                    data: column,
                    render: function (data, type, row) {
                        const disabled = ['Pending', 'Rejected', 'Cancelled reversal'].includes(row.status);
                        if (Number(data) === 0) {
                            return '<span' + (disabled ? ' class="bookly:text-slate-400"' : '') + '>$' + data.replace('-', '') + '</span>';
                        }
                        if (data > 0) {
                            return '<span class="' + (disabled ? 'bookly:text-slate-400' : 'bookly:text-green-600') + '">+ $' + data + '</span>';
                        }
                        return '<span class="' + (disabled ? 'bookly:text-slate-400' : 'bookly:text-red-600') + '">- $' + data.substring(1) + '</span>';
                    }
                });
                break;
            case 'status':
                columns.push({
                    data: column,
                    render: function (data) { return BooklyDatatables.escapeHtml(data); },
                    badge: function (row) { return purchaseStatusBadgeClass[row.status] || defaultBadgeClass; },
                });
                break;
            default:
                columns.push({ data: column, render: BooklyDatatables.escapeHtml() });
                break;
        }
        columns[columns.length - 1].title = BooklyL10n.datatables[purchasesTable].titles[column] || column;
        columns[columns.length - 1].name = column;
        columns[columns.length - 1].show = show;
        columns[columns.length - 1].orderable = false;
    });

    function rowHasInvoice(row) {
        return (row.type === 'PayPal' || row.type === 'Card' || row.type === 'Paddle') && row.status === 'Paid';
    }

    /**
     * Who the invoices are made out to. Opened from the table toolbar, and from an
     * invoice download that cannot go ahead without the details.
     */
    function showInvoiceDetails() {
        BooklyCloudSettings.showInvoiceDialog({
            invoice: BooklyL10n.invoice.details,
            email: BooklyL10n.invoice.email,
            l10n: BooklyL10n.invoice.l10n,
            onsaved: function (details) {
                BooklyL10n.invoice.details = details;
                BooklyL10n.invoice.valid = details.company_name.trim() !== '' && details.company_address.trim() !== '';
            }
        });
    }

    if (columns.length) {
        let purchasesBt = BooklyDatatables.showForm('bookly-' + purchasesTable + '-datatables', {
            datePicker: BooklyL10n.datePicker,
            ajax: {
                url: ajaxurl,
                method: 'POST',
                data: function (d) {
                    return $.extend({}, d, {
                        action: 'bookly_get_purchases_list',
                        csrf_token: BooklyL10nGlobal.csrf_token,
                        filter: { range: serializeDate(dateValue) },
                    });
                }
            },
            columns: columns,
            tableSettings: Object.assign({}, BooklyL10n.datatables[purchasesTable], {
                l10n: Object.assign({}, BooklyL10n.datatables.l10n, { zeroRecords: BooklyL10n.zeroRecords })
            }),
            saveSettings: function (settings) {
                $.post(ajaxurl, Object.assign({
                    action: 'bookly_update_table_settings',
                    table: purchasesTable,
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
            searchFilter: { placeholder: BooklyL10n.search || 'Quick search', name: 'filter[search]' },
            topToolbar: [{
                label: BooklyL10n.invoice.details_button,
                icon: 'pencil',
                variant: 'outline',
                click: showInvoiceDetails,
            }],
            rowActions: function (row) {
                if (!rowHasInvoice(row)) return [];
                return [{
                    label: BooklyL10n.invoice.button,
                    icon: 'download',
                    variant: 'outline',
                    click: function (r) {
                        if (BooklyL10n.invoice.valid) {
                            window.location = BooklyL10n.invoice.link + '/' + r.id;
                        } else {
                            booklyAlert({ error: [BooklyL10n.invoice.alert] });
                            showInvoiceDetails();
                        }
                    }
                }];
            },
        });
    }
});
