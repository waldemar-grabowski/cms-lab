jQuery(function ($) {
    'use strict';
    const { today, getLocalTimeZone, parseDate } = window.BooklyDatatables.calendarDate;

    const table = 'payments';
    const urlParts = document.URL.split('#');
    const filterOpts = BooklyL10n.filterOptions;
    const fl = BooklyL10n.filters;

    const tz = getLocalTimeZone();
    const t = today(tz);
    const startOfMonth = (d) => d.set({ day: 1 });
    const endOfMonth   = (d) => d.set({ day: 1 }).add({ months: 1 }).subtract({ days: 1 });
    // ISO-неделя (Mon..Sun). 0=Sun → 6, 1=Mon → 0, ...
    function startOfWeek(d) {
        const dow = d.toDate(tz).getDay();
        const offset = (dow + 6) % 7;
        return d.subtract({ days: offset });
    }
    const endOfWeek   = (d) => startOfWeek(d).add({ days: 6 });
    const startOfYear = (d) => d.set({ month: 1, day: 1 });
    const endOfYear   = (d) => d.set({ month: 12, day: 31 });
    const ymd = (d) => d.year + '-' + String(d.month).padStart(2, '0') + '-' + String(d.day).padStart(2, '0');

    /**
     * Date presets — соответствуют Appointments createdDatePresets (past-only набор).
     * Используется в фильтре Date (дата создания платежа).
     */
    const createdDatePresets = [
        { label: BooklyL10n.dateRange.today,       range: { start: t,                                        end: t                                           } },
        { label: BooklyL10n.dateRange.yesterday,   range: { start: t.subtract({ days: 1 }),                  end: t.subtract({ days: 1 })                     } },
        { label: BooklyL10n.dateRange.last_7,      range: { start: t.subtract({ days: 7 }),                  end: t                                           } },
        { label: BooklyL10n.dateRange.last_30,     range: { start: t.subtract({ days: 30 }),                 end: t                                           } },
        { label: BooklyL10n.dateRange.last_90,     range: { start: t.subtract({ days: 90 }),                 end: t                                           } },
        { label: BooklyL10n.dateRange.thisWeek,    range: { start: startOfWeek(t),                           end: endOfWeek(t)                                } },
        { label: BooklyL10n.dateRange.thisMonth,   range: { start: startOfMonth(t),                          end: endOfMonth(t)                               } },
        { label: BooklyL10n.dateRange.lastMonth,   range: { start: startOfMonth(t.subtract({ months: 1 })),  end: endOfMonth(t.subtract({ months: 1 }))       } },
        { label: BooklyL10n.dateRange.yearToDate,  range: { start: startOfYear(t),                           end: t                                           } },
    ];

    /**
     * Appointment date presets — соответствуют Appointments datePresets (future-leaning).
     * Используется в фильтре Appointment date.
     */
    const apptDatePresets = [
        { label: BooklyL10n.dateRange.today,      range: { start: t,                                        end: t                                            } },
        { label: BooklyL10n.dateRange.tomorrow,   range: { start: t.add({ days: 1 }),                       end: t.add({ days: 1 })                           } },
        { label: BooklyL10n.dateRange.thisWeek,   range: { start: startOfWeek(t),                           end: endOfWeek(t)                                 } },
        { label: BooklyL10n.dateRange.next_7,     range: { start: t,                                        end: t.add({ days: 7 })                           } },
        { label: BooklyL10n.dateRange.next_30,    range: { start: t,                                        end: t.add({ days: 30 })                          } },
        { label: BooklyL10n.dateRange.thisMonth,  range: { start: startOfMonth(t),                          end: endOfMonth(t)                                } },
        { label: BooklyL10n.dateRange.nextMonth,  range: { start: startOfMonth(t.add({ months: 1 })),       end: endOfMonth(t.add({ months: 1 }))             } },
        { label: BooklyL10n.dateRange.thisYear,   range: { start: startOfYear(t),                           end: endOfYear(t)                                 } },
        // past-хвост для отчётности
        { label: BooklyL10n.dateRange.yesterday,  range: { start: t.subtract({ days: 1 }),                  end: t.subtract({ days: 1 })                      } },
        { label: BooklyL10n.dateRange.last_7,     range: { start: t.subtract({ days: 7 }),                  end: t                                            } },
        { label: BooklyL10n.dateRange.last_30,    range: { start: t.subtract({ days: 30 }),                 end: t                                            } },
        { label: BooklyL10n.dateRange.lastMonth,  range: { start: startOfMonth(t.subtract({ months: 1 })),  end: endOfMonth(t.subtract({ months: 1 }))        } },
    ];

    function serializeDate(value) {
        if (!value) return 'any';
        if (value.tasks) return 'null';
        if (value.start && value.end) return ymd(value.start) + ' - ' + ymd(value.end);
        return 'any';
    }

    function parseSavedDate(saved, presets) {
        if (typeof saved !== 'string' || saved === 'any') return undefined;
        if (saved === 'null') {
            const tasksPreset = presets.find(p => p.range && p.range.tasks);
            return tasksPreset ? tasksPreset.range : undefined;
        }
        const parts = saved.split(' - ');
        if (parts.length === 2) {
            try { return { start: parseDate(parts[0].trim()), end: parseDate(parts[1].trim()) }; }
            catch (e) { return undefined; }
        }
        return undefined;
    }

    // Default state.
    let createdDateValue = createdDatePresets.find(p => p.label === BooklyL10n.dateRange.last_30)?.range;
    let apptDateValue = undefined;
    let typeValue = [];
    let customerValue = '';
    let staffValue = '';
    let serviceValue = '';
    let statusValue = [];

    // Restore from URL anchor or saved settings.
    const savedFilter = BooklyL10n.datatables[table].settings.filter || {};
    if (urlParts.length > 1) {
        urlParts[1].split('&').forEach(function (part) {
            const params = part.split('=');
            switch (params[0]) {
                case 'created-date':    createdDateValue = parseSavedDate(params[1], createdDatePresets); break;
                case 'appointment-date': apptDateValue = parseSavedDate(params[1], apptDatePresets); break;
                case 'type':     typeValue = (params[1] === 'any' || !params[1]) ? [] : params[1].split(','); break;
                case 'customer': customerValue = params[1] || ''; break;
                case 'staff':    staffValue = params[1] || ''; break;
                case 'service':  serviceValue = params[1] || ''; break;
                case 'status':   statusValue = (params[1] === 'any' || !params[1]) ? [] : params[1].split(','); break;
            }
        });
    } else {
        if (savedFilter.created_at !== undefined) {
            createdDateValue = parseSavedDate(savedFilter.created_at, createdDatePresets);
        }
        if (savedFilter.start_date !== undefined) apptDateValue = parseSavedDate(savedFilter.start_date, apptDatePresets);
        if (Array.isArray(savedFilter.type) && savedFilter.type.length > 0) typeValue = savedFilter.type;
        customerValue = savedFilter.customer || '';
        staffValue = savedFilter.staff || '';
        serviceValue = savedFilter.service || '';
        if (Array.isArray(savedFilter.status) && savedFilter.status.length > 0) statusValue = savedFilter.status;
    }

    const defaultBadgeClass = 'bookly:bg-gray-100 bookly:text-gray-700 bookly:border-gray-200';
    const paymentStatusBadgeClass = {
        'completed': 'bookly:bg-green-100 bookly:text-green-800 bookly:border-green-200',
        'pending':   'bookly:bg-amber-100 bookly:text-amber-800 bookly:border-amber-200',
        'rejected':  'bookly:bg-red-100 bookly:text-red-800 bookly:border-red-200',
        'refunded':  'bookly:bg-purple-100 bookly:text-purple-800 bookly:border-purple-200',
    };

    /**
     * Init table columns.
     */
    const columns = [];
    $.each(BooklyL10n.datatables[table].settings.columns, function (column, show) {
        switch (column) {
            case 'created_at':
                columns.push({
                    data: column,
                    searchable: false,
                    render: function (data, type, row) {
                        return type === 'sort' ? data : BooklyDatatables.escapeHtml(row.created_format);
                    }
                });
                break;
            case 'start_date':
                columns.push({
                    data: column,
                    searchable: false,
                    render: function (data, type, row) {
                        return type === 'sort' ? data : BooklyDatatables.escapeHtml(row.start_date_format);
                    }
                });
                break;
            case 'type':
                columns.push({
                    data: column,
                    searchable: false,
                    render: BooklyDatatables.escapeHtml(),
                });
                break;
            case 'subtotal':
                columns.push({
                    data: column,
                    render: BooklyDatatables.escapeHtml(),
                    orderable: false,
                    searchable: false,
                    class: 'bookly:text-right',
                });
                break;
            case 'paid':
                columns.push({
                    data: column,
                    render: BooklyDatatables.escapeHtml(),
                    class: 'bookly:text-right',
                });
                break;
            case 'service':
            case 'provider':
                columns.push({
                    data: column,
                    render: function (data, type, row) {
                        return BooklyDatatables.escapeHtml(data) + (row.multiple ? ' <i class="fas fa-shopping-cart ml-1" title="' + BooklyL10n.multiple + '"></i>' : '');
                    }
                });
                break;
            case 'status':
                columns.push({
                    data: column,
                    searchable: false,
                    render: function (data) { return BooklyDatatables.escapeHtml(data); },
                    badge: function (row) { return paymentStatusBadgeClass[row.status_code] || defaultBadgeClass; },
                });
                break;
            default:
                columns.push({ data: column, render: BooklyDatatables.escapeHtml() });
                break;
        }
        columns[columns.length - 1].title = BooklyL10n.datatables[table].titles[column] || column;
        columns[columns.length - 1].name = column;
        columns[columns.length - 1].show = show;
    });

    /**
     * Build filters array.
     */
    const filters = [
        {
            type: 'dateRange',
            name: 'created_at',
            label: fl.date,
            initialValue: createdDateValue,
            presets: createdDatePresets,
            onChange: (v) => { createdDateValue = v; },
        },
        {
            type: 'dateRange',
            name: 'start_date',
            label: fl.appointment_date,
            initialValue: apptDateValue,
            presets: apptDatePresets,
            onChange: (v) => { apptDateValue = v; },
        },
        {
            type: 'checkboxGroup',
            name: 'type',
            label: fl.type,
            initialValue: typeValue,
            options: filterOpts.types || [],
            onChange: (v) => { typeValue = v; },
        },
    ];

    {
        const customerFilter = {
            type: 'select',
            name: 'customer',
            label: fl.customer,
            initialValue: customerValue,
            searchPlaceholder: fl.searchPlaceholder,
            onChange: (v) => { customerValue = v; },
        };
        if (filterOpts.customersRemote) {
            // Large customer base — load options on demand via AJAX typeahead.
            customerFilter.remote = true;
            customerFilter.loadOptions = function (term) {
                return $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'bookly_get_customers_list',
                        filter: term || '',
                        page: 1,
                        csrf_token: BooklyL10nGlobal.csrf_token,
                    },
                }).then(resp => (resp && resp.results ? resp.results : []).map(c => ({
                    value: String(c.id),
                    label: c.text, // full_name only
                })));
            };
            // Resolve the label for a restored saved value (filter loads the customer itself).
            customerFilter.resolveOption = function (id) {
                return $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'bookly_get_customers_list',
                        ids: [id],
                        csrf_token: BooklyL10nGlobal.csrf_token,
                    },
                }).then(resp => {
                    const c = resp && resp.results && resp.results[0];
                    return c ? { value: String(c.id), label: c.text } : null;
                });
            };
        } else {
            customerFilter.options = (filterOpts.customers || []).map(c => ({ value: String(c.id), label: c.full_name }));
        }
        filters.push(customerFilter);
    }

    filters.push({
        type: 'select',
        name: 'staff',
        label: fl.staff,
        initialValue: staffValue,
        searchPlaceholder: fl.searchPlaceholder,
        options: (filterOpts.staff || []).map(s => ({ value: String(s.id), label: s.full_name })),
        onChange: (v) => { staffValue = v; },
    });

    filters.push({
        type: 'select',
        name: 'service',
        label: fl.service,
        initialValue: serviceValue,
        searchPlaceholder: fl.searchPlaceholder,
        options: (filterOpts.services || []).map(s => ({ value: String(s.id), label: s.full_name })),
        onChange: (v) => { serviceValue = v; },
    });

    filters.push({
        type: 'checkboxGroup',
        name: 'status',
        label: fl.status,
        initialValue: statusValue,
        options: filterOpts.statuses || [],
        onChange: (v) => { statusValue = v; },
    });

    function getFilter() {
        return {
            created_at: serializeDate(createdDateValue),
            start_date: serializeDate(apptDateValue),
            type: typeValue,
            customer: customerValue,
            staff: staffValue,
            service: serviceValue,
            status: statusValue,
        };
    }

    let options = {
        ajax: {
            url: ajaxurl,
            method: 'POST',
            data: function (d) {
                return $.extend({
                    action: 'bookly_get_payments',
                    csrf_token: BooklyL10nGlobal.csrf_token,
                    filter: getFilter(),
                }, d);
            },
        },
        columns: columns,
        // Sum of the paid column for the current filter (formatted on the server).
        footer: function (result) {
            return result && result.data && result.data.length > 0 && BooklyL10n.datatables[table].settings.columns.paid
                ? BooklyDatatables.escapeHtml(BooklyL10n.total) + ': <strong id="bookly-payment-total">' + result.total + '</strong>'
                : null;
        },
        tableSettings: Object.assign({}, BooklyL10n.datatables[table], { l10n: Object.assign({}, BooklyL10n.datatables.l10n, { zeroRecords: BooklyL10n.zeroRecords }) }),
        edit: function (row) {
            BooklyPaymentDetailsDialog.showDialog({
                payment_id: row.id,
                done: function () { bt.reload(); }
            });
        },
        rowActions: function (row) {
            if (!BooklyL10n.invoice.enabled) return [];
            return [{
                label: BooklyL10n.invoice.button,
                icon: 'download',
                variant: 'outline',
                click: function (r) {
                    window.location = BooklyL10n.invoice.action + '&invoices=' + r.id;
                }
            }];
        },
        checked: function (rows) {
            const actions = [];
            if (BooklyL10n.invoice.enabled && rows.length > 1) {
                actions.push({
                    label: BooklyL10n.invoice.download,
                    icon: 'download',
                    variant: 'outline',
                    click: function (selected) {
                        const ids = selected.map(function (row) { return row.id; });
                        if (ids.length) {
                            window.location = BooklyL10n.invoice.action + '&invoices=' + ids.join(',');
                        }
                    }
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
                            action: 'bookly_delete_payments',
                            csrf_token: BooklyL10nGlobal.csrf_token,
                            data: selected.map(function (row) { return row.id; })
                        },
                        dataType: 'json',
                        success: function (response) {
                            if (response.success) {
                                bt.reload();
                            } else {
                                alert(response.data.message);
                            }
                        }
                    });
                }
            });
            return actions;
        },
        saveSettings: function (settings) {
            $.post(ajaxurl, Object.assign({
                action: 'bookly_update_table_settings',
                table: table,
                csrf_token: BooklyL10nGlobal.csrf_token,
            }, settings));
        },
        filters: filters,
        searchFilter: { placeholder: BooklyL10n.search, name: 'filter[search]' },
    };

    if (BooklyL10n.invoice.enabled) {
        options.topToolbar = [{
            label: BooklyL10n.invoice.downloadAll,
            icon: 'download',
            variant: 'outline',
            click: function () {
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'bookly_get_payment_ids',
                        csrf_token: BooklyL10nGlobal.csrf_token,
                        filter: getFilter()
                    },
                    dataType: 'json',
                    success: function (response) {
                        if (response.data.ids.length) {
                            window.location = BooklyL10n.invoice.action + '&invoices=' + response.data.ids.join(',');
                        }
                    }
                });
            }
        }];
    }

    options.datePicker = BooklyL10n.datePicker;
    const bt = BooklyDatatables.showForm('bookly-' + table + '-datatables', options);
});
