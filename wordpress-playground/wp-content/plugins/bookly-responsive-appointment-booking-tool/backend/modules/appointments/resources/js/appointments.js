jQuery(function ($) {
    'use strict';
    const D = window.BooklyDatatables;
    const { today, getLocalTimeZone } = D.calendarDate;

    let
        $appointmentsList = $('#bookly-appointments-datatables'),
        $printDialog = $('#bookly-print-dialog'),
        $printSelectAll = $('#bookly-js-print-select-all', $printDialog),
        $printButton = $(':submit', $printDialog),
        $exportDialog = $('#bookly-export-dialog'),
        $exportSelectAll = $('#bookly-js-export-select-all', $exportDialog),
        $exportForm = $('form', $exportDialog),
        isMobile = false,
        urlParts = document.URL.split('#'),
        columns = []
    ;

    try {
        document.createEvent('TouchEvent');
        isMobile = true;
    } catch (e) {}

    // The booking wizard is the creation flow only when the setting is on and the
    // component is present on the page.
    function wizardEnabled() {
        return BooklyL10n.createWithWizard && typeof BooklyBookingWizard !== 'undefined';
    }

    // Tailwind classes per appointment status — used by the status column badge.
    const statusBadgeClass = {
        pending:    'bookly:bg-amber-100 bookly:text-amber-800 bookly:border-amber-200',
        approved:   'bookly:bg-green-100 bookly:text-green-800 bookly:border-green-200',
        cancelled:  'bookly:bg-gray-100 bookly:text-gray-700 bookly:border-gray-200',
        rejected:   'bookly:bg-red-100 bookly:text-red-800 bookly:border-red-200',
        waitlisted: 'bookly:bg-purple-100 bookly:text-purple-800 bookly:border-purple-200',
        done:       'bookly:bg-blue-100 bookly:text-blue-800 bookly:border-blue-200',
    };
    const defaultBadgeClass = 'bookly:bg-gray-100 bookly:text-gray-700 bookly:border-gray-200';
    // Online meeting providers by the value stored on the appointment. Brand names — not translated.
    const meetingProviders = {
        zoom: 'Zoom',
        google_meet: 'Google Meet',
        jitsi: 'Jitsi Meet',
        bbb: 'BigBlueButton',
        teams: 'Microsoft Teams',
    };

    /**
     * Filter state — each FilterRenderer bind updates its variable through onChange;
     * ajax.data() serializes them into the backend POST.
     *
     * Date values: undefined (any), 'tasks' (special), or { start: CalendarDate, end: CalendarDate }.
     * Select values: '' or a string id.
     * Status: array of values (empty = "any", full = "any" — the backend treats both the same).
     */
    let dateValue = undefined;
    let createdDateValue = undefined;
    let staffValue = '';
    let customerValue = '';
    let serviceValue = '';
    let locationValue = '';
    let statusValue = [];

    const tz = getLocalTimeZone();
    const t = today(tz);

    /**
     * Appointment date — future-leaning set (the shared catalogue default).
     * Tasks mode (when enabled) is added from OUTSIDE as an extra preset carrying
     * the marker range = { tasks: true }; intercepted in serializeDate.
     */
    const datePresets = D.datePresets({
        labels: BooklyL10n.dateRange,
        extra: BooklyL10n.tasks.enabled
            ? [{ label: BooklyL10n.tasks.title, range: { tasks: true, start: t, end: t.add({ days: 1 }) }, hideCalendar: true }]
            : [],
    });

    // Creation date — past-only set (creation dates only make sense in the past).
    const createdDatePresets = D.datePresets({
        keys: ['today', 'yesterday', 'thisWeek', 'last_7', 'last_30', 'last_90', 'thisMonth', 'lastMonth', 'yearToDate'],
        labels: BooklyL10n.dateRange,
    });

    // Tasks mode serializes to the special 'null' marker; everything else is a plain range.
    function serializeDate(value) {
        if (value && value.tasks) return 'null';
        return D.serializeRange(value);
    }

    /**
     * Restore initial values from saved settings or anchor.
     */
    let savedFilter = BooklyL10n.datatables.appointments.settings.filter || {};

    function tryParseSavedDate(saved, label) {
        if (label) {
            const preset = datePresets.find(p => p.label === label);
            if (preset) return preset.range;
        }
        if (saved === 'null' && BooklyL10n.tasks.enabled) {
            const tasksPreset = datePresets.find(p => p.range && p.range.tasks);
            if (tasksPreset) return tasksPreset.range;
        }
        // Plain range string ('a - b' or the URL-hash form) — delegated to the shared parser.
        return D.parseRange(saved);
    }

    if (urlParts.length > 1) {
        urlParts[1].split('&').forEach(function (part) {
            const params = part.split('=');
            switch (params[0]) {
                case 'appointment-date':
                    dateValue = tryParseSavedDate(params[1], null);
                    break;
                case 'tasks':
                    if (BooklyL10n.tasks.enabled) {
                        const p = datePresets.find(p => p.range && p.range.tasks);
                        if (p) dateValue = p.range;
                    }
                    break;
                case 'created-date':
                    createdDateValue = tryParseSavedDate(params[1], null);
                    break;
                case 'staff':    staffValue    = params[1]; break;
                case 'customer': customerValue = params[1]; break;
                case 'service':  serviceValue  = params[1]; break;
                case 'location': locationValue = params[1]; break;
                case 'status':
                    statusValue = (params[1] === 'any' || !params[1]) ? [] : params[1].split(',');
                    break;
            }
        });
    } else {
        dateValue        = tryParseSavedDate(savedFilter.date, savedFilter.date_label);
        createdDateValue = tryParseSavedDate(savedFilter.created_date, savedFilter.created_date_label);
        staffValue       = savedFilter.staff || '';
        customerValue    = savedFilter.customer || '';
        serviceValue     = savedFilter.service || '';
        locationValue    = savedFilter.location || '';
        if (Array.isArray(savedFilter.status) && savedFilter.status.length > 0) {
            statusValue = savedFilter.status;
        }
        // Default appointment date — this month, when nothing is set.
        if (dateValue === undefined && !savedFilter.date) {
            dateValue = datePresets.find(p => p.label === BooklyL10n.dateRange.thisMonth)?.range;
        }
    }

    Ladda.bind($('button[type=submit]', $exportForm).get(0), {timeout: 2000});

    /**
     * Init table columns.
     */
    const table = 'appointments';

    // Backend (Ajax::getAppointments) searches only by a.id (ID) / ca.id (No.), customer
    // full_name/phone/email, staff full_name and service title — so the quick-search
    // highlight must be limited to those columns. All other columns are non-searchable.
    const searchableColumns = ['id', 'no', 'customer_full_name', 'customer_phone', 'customer_email', 'staff_name', 'service_title'];

    $.each(BooklyL10n.datatables.appointments.settings.columns, function (column, show) {
        switch (column) {
            case 'customer_full_name':
                columns.push({data: 'customer.full_name', render: BooklyDatatables.escapeHtml()});
                break;
            case 'customer_phone':
                columns.push({
                    data: 'customer.phone',
                    parent: 'customer_full_name',
                    render: function (data, type, row, meta) {
                        if (isMobile) {
                            return '<a href="tel:' + window.booklyIntlTelInput.utils.formatNumber(BooklyDatatables.escapeHtml(data), null, window.booklyIntlTelInput.utils.numberFormat.INTERNATIONAL) + '">' + BooklyDatatables.escapeHtml(data) + '</a>';
                        } else {
                            return data ? '<span style="white-space: nowrap;">' + window.booklyIntlTelInput.utils.formatNumber(BooklyDatatables.escapeHtml(data), null, window.booklyIntlTelInput.utils.numberFormat.INTERNATIONAL) + '</span>' : '';
                        }
                    }
                });
                break;
            case 'customer_email':
                columns.push({data: 'customer.email', parent: 'customer_full_name', render: BooklyDatatables.escapeHtml()});
                break;
            case 'customer_address':
                columns.push({data: 'customer.address', render: BooklyDatatables.escapeHtml(), orderable: false});
                break;
            case 'customer_birthday':
                columns.push({data: 'customer.birthday', render: BooklyDatatables.escapeHtml()});
                break;
            case 'staff_name':
                columns.push({data: 'staff.name', render: BooklyDatatables.escapeHtml()});
                break;
            case 'start_date':
                columns.push({
                    data: 'start_date',
                    // Time of day rendered as a muted secondary line below the date.
                    // Both halves come pre-formatted from Ajax.php (WP date_format / time_format).
                    secondary: row => row.start_time || '',
                });
                break;
            case 'created_date':
                columns.push({
                    data: 'created_date',
                    secondary: row => row.created_time || '',
                });
                break;
            case 'service_title':
                columns.push({
                    data: 'service.title',
                    render: data => BooklyDatatables.escapeHtml(data),
                    // Each extra renders as a muted secondary line under the title.
                    secondary: row => (row.service.extras || []).map(e => BooklyDatatables.escapeHtml(e.title)),
                });
                break;
            case 'service_duration':
                // Rendered as a secondary line under Service in the cell, and as an
                // indented checkbox under Service in the View dialog. Stays in columns
                // array (export/print see it as a separate field) — Form.svelte just
                // skips top-level rendering for columns with `parent`.
                columns.push({
                    data: 'service.duration',
                    parent: 'service_title',
                });
                break;
            case 'payment':
                columns.push({
                    data: 'payment_amount',
                    // Two lines: amount (primary) + "gateway · status" (muted secondary).
                    secondary: row => row.payment_gateway || row.payment_status
                        ? [row.payment_gateway, row.payment_status].filter(Boolean).join(' · ')
                        : null,
                    render: function (data, type, row, meta) {
                        if (row.payment_id) {
                            return '<a type="button" data-action="show-payment" class="text-primary" data-payment_id="' + row.payment_id + '">' + data + '</a>';
                        }
                        return data || '';
                    }
                });
                break;
            case 'service_price':
                columns.push({data: 'service.price', class: 'bookly:text-right'});
                break;
            case 'attachments':
                columns.push({
                    data: 'attachment',
                    render: function (data, type, row, meta) {
                        if (data == '1') {
                            return '<button type="button" class="btn btn-link p-0" data-action="show-attachments" data-ca-id="' + row.ca_id + '" title="' + BooklyL10n.attachments + '"><i class="fas fa-fw fa-paperclip"></i></button>';
                        }
                        return '';
                    }
                });
                break;
            case 'rating':
                columns.push({
                    data: 'rating',
                    render: (data, type, row) => row.rating != null ? BooklyDatatables.escapeHtml(String(row.rating)) : '',
                    popover: row => row.rating_comment || null,
                    popoverLink: true,
                });
                break;
            case 'internal_note':
            case 'locations':
            case 'notes':
            case 'number_of_persons':
                columns.push({data: column, render: BooklyDatatables.escapeHtml()});
                break;
            case 'online_meeting':
                // The same neutral badge the status column uses, named by the provider; the
                // badge opens the meeting. The appointment card shows it the same way.
                columns.push({
                    data: 'online_meeting_provider',
                    badge: () => defaultBadgeClass,
                    render: function (data, type, row) {
                        const title = meetingProviders[data];
                        if (!title) {
                            return '';
                        }
                        const url = data === 'zoom'
                            ? 'https://zoom.us/j/' + row.online_meeting_start_url
                            : row.online_meeting_start_url;
                        // wp-admin paints every link in its own colour; the badge keeps its own.
                        return '<a href="' + BooklyDatatables.escapeHtml(url) + '" target="_blank" rel="noopener" style="color:inherit;text-decoration:none">' + title + '</a>';
                    },
                });
                break;
            case 'id':
                columns.push({data: column, render: BooklyDatatables.escapeHtml()});
                break;
            case 'status':
                columns.push({
                    data: 'status',
                    badge: row => statusBadgeClass[row.status_code] || defaultBadgeClass,
                });
                break;
            default:
                if (column.startsWith('custom_fields_')) {
                    columns.push({
                        data: column.replace(/_([^_]*)$/, '.$1'),
                        render: BooklyDatatables.escapeHtml(),
                        orderable: false
                    });
                } else {
                    columns.push({data: column, render: BooklyDatatables.escapeHtml()});
                }
                break;
        }
        columns[columns.length - 1].title = BooklyL10n.datatables[table].titles[column] || column;
        columns[columns.length - 1].name = column;
        columns[columns.length - 1].show = show;
        columns[columns.length - 1].searchable = searchableColumns.indexOf(column) !== -1;
    });

    const filterOpts = BooklyL10n.filterOptions;
    const fl = BooklyL10n.filters;
    const searchPlaceholder = fl.searchPlaceholder;

    const filters = [
        {
            type: 'dateRange',
            name: 'date',
            label: fl.date,
            initialValue: dateValue,
            presets: datePresets,
            onChange: (v) => { dateValue = v; },
        },
        {
            type: 'dateRange',
            name: 'created_date',
            label: fl.created,
            initialValue: createdDateValue,
            presets: createdDatePresets,
            onChange: (v) => { createdDateValue = v; },
        },
        {
            type: 'select',
            name: 'staff',
            label: fl.staff,
            initialValue: staffValue,
            searchPlaceholder: searchPlaceholder,
            options: (filterOpts.staff || []).map(s => ({ value: String(s.id), label: s.full_name })),
            onChange: (v) => { staffValue = v; },
        },
    ];

    {
        const customerFilter = {
            type: 'select',
            name: 'customer',
            label: fl.customer,
            initialValue: customerValue,
            searchPlaceholder: searchPlaceholder,
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
                    label: c.text, // full_name only — matches static mode and the coupons filter
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
        name: 'service',
        label: fl.service,
        initialValue: serviceValue,
        searchPlaceholder: searchPlaceholder,
        options: [{ value: '0', label: 'Custom' }].concat((filterOpts.services || []).map(s => ({ value: String(s.id), label: s.title }))),
        onChange: (v) => { serviceValue = v; },
    });

    if (filterOpts.locations && filterOpts.locations.length > 0) {
        const locOptions = [{ value: 'w/o', label: fl.noLocation }].concat(filterOpts.locations.map(l => ({ value: String(l.id), label: l.name })));
        filters.push({
            type: 'select',
            name: 'location',
            label: fl.location,
            initialValue: locationValue,
            searchPlaceholder: searchPlaceholder,
            options: locOptions,
            onChange: (v) => { locationValue = v; },
        });
    }

    filters.push({
        type: 'checkboxGroup',
        name: 'status',
        label: fl.status,
        initialValue: statusValue,
        options: filterOpts.statuses || [],
        onChange: (v) => { statusValue = v; },
    });

    function activeDateLabel(value, presets) {
        if (!value) return null;
        for (const p of presets) {
            if (p.range && p.range.tasks && value.tasks) return p.label;
            if (p.range && p.range.start && value.start && p.range.start.compare(value.start) === 0 && p.range.end.compare(value.end) === 0) {
                return p.label;
            }
        }
        return null;
    }

    let options = {
        summary: true,
        ajax: {
            url: ajaxurl,
            method: 'POST',
            data: function () {
                return {
                    action: 'bookly_get_appointments',
                    csrf_token: BooklyL10nGlobal.csrf_token,
                    filter: {
                        date: serializeDate(dateValue),
                        date_label: activeDateLabel(dateValue, datePresets),
                        created_date: serializeDate(createdDateValue),
                        created_date_label: activeDateLabel(createdDateValue, createdDatePresets),
                        staff: staffValue,
                        customer: customerValue,
                        service: serviceValue,
                        status: statusValue,
                        location: locationValue,
                    },
                };
            },
        },
        columns: columns,
        tableSettings: Object.assign({}, BooklyL10n.datatables[table], {l10n: Object.assign({}, BooklyL10n.datatables.l10n, {zeroRecords: BooklyL10n.zeroRecords})}),
        edit: function (row) {
            const reload = function () {
                bt.reload();
            };
            // The card and the wizard are one interface and are switched on together: the
            // card's own actions — rescheduling, adding a customer, giving a task a time —
            // are the wizard's, and a card without them is the classic form one click
            // further away. With the wizard off a click opens the classic form, as it
            // always did.
            if (!wizardEnabled()) {
                BooklyAppointmentDialog.showDialog(row.id, null, null, reload);

                return;
            }
            // A click on a row opens the appointment card — a view of the whole
            // appointment, from which the classic form and the wizard are reached. A row
            // here is a single booking, the card shows all of them; deleting is the card's
            // own operation and takes the appointment with every booking in it.
            BooklyAppointmentCard.showCard(
                getBooklyModalContainer('bookly-appointment-card'),
                {
                    appointmentId: row.id,
                    onEdit: function () {
                        BooklyAppointmentDialog.showDialog(row.id, null, null, reload);
                    },
                    onChanged: function () {
                        bt.reload();
                    },
                    onDeleted: function () {
                        bt.reload();
                    },
                }
            );
        },
        rowActions: function (row) {
            if (!wizardEnabled()) return [];
            return [{
                // A row without a time is a task: the wizard gives it one instead of
                // moving it, and the button promises that. Same wording in the card.
                label: row.timeless ? BooklyL10n.setTime : BooklyL10n.reschedule,
                icon: 'calendar',
                variant: 'outline',
                click: function (r) {
                    BooklyBookingWizard.showDialog({
                        reschedule_ca: r.ca_id,
                        onSaved: function () {
                            bt.reload();
                        }
                    });
                }
            }];
        },
        checked: function (rows) {
            return [
                {
                    label: BooklyL10n.delete,
                    icon: 'trash',
                    variant: 'destructive',
                    click: function (selectedRows) {
                        // With the booking wizard on, deleting asks and reports the way the
                        // appointment card does; a single booking is also asked how far into
                        // its series or compound service the deletion reaches.
                        if (wizardEnabled()) {
                            BooklyAppointmentCard.showRemoveBookings(
                                getBooklyModalContainer('bookly-remove-bookings'),
                                {
                                    rows: selectedRows.map(row => ({
                                        caId: row.ca_id ? row.ca_id : null,
                                        appointmentId: row.id,
                                    })),
                                    onDeleted: function () {
                                        bt.reload();
                                    },
                                }
                            );

                            return;
                        }
                        const data = selectedRows.map(row => ({
                            ca_id: row.ca_id ? row.ca_id : 'null',
                            id: row.id,
                        }));
                        new BooklyConfirmDeletingAppointment({
                                action: 'bookly_delete_customer_appointments',
                                data: data,
                                csrf_token: BooklyL10nGlobal.csrf_token,
                            },
                            function (response) { bt.reload(); }
                        );
                    }
                }
            ];
        },
        getId(row) {
            return row.id + '-' + parseInt(row.ca_id);
        },
        saveSettings: function (settings) {
            $.post(
                ajaxurl,
                Object.assign(
                    {
                        action: 'bookly_update_table_settings',
                        table: table,
                        csrf_token: BooklyL10nGlobal.csrf_token
                    },
                    settings
                )
            );
        },
        filters: filters,
        emptyState: {
            icon: 'calendar',
            title: BooklyL10n.empty_title,
            text: BooklyL10n.empty_text,
            actions: ['bookly-new-appointment']
        },
        topToolbar: (function () {
            const buttons = [];
            if (BooklyL10n.proEnabled) {
                buttons.push({
                    label: BooklyL10n.export,
                    icon: 'download',
                    variant: 'outline',
                    click: function () {
                        let columnsHtml = '';
                        bt.getColumns().forEach(function (column, index) {
                            columnsHtml += '<div class="custom-control custom-checkbox"><input class="custom-control-input" id="bookly-ea-' + index + '" name="exp[' + column.name + ']" type="checkbox"' + (column.show ? 'checked' : '') + '><label class="custom-control-label" for="bookly-ea-' + index + '">' + column.title + '</label></div>';
                        });
                        $('.bookly-js-columns', $exportDialog).html(columnsHtml);
                        $exportDialog.booklyModal('show');
                    }
                });
                buttons.push({
                    label: BooklyL10n.print,
                    icon: 'printer',
                    variant: 'outline',
                    click: function () {
                        let columnsHtml = '';
                        bt.getColumns().forEach(function (column, index) {
                            columnsHtml += '<div class="custom-control custom-checkbox"><input class="custom-control-input" id="bookly-pa-' + index + '" value="' + index + '" type="checkbox"' + (column.show ? 'checked' : '') + '><label class="custom-control-label" for="bookly-pa-' + index + '">' + column.title + '</label></div>';
                        });
                        $('.bookly-js-columns', $printDialog).html(columnsHtml);
                        $printDialog.booklyModal('show');
                    }
                });
            }
            buttons.push({
                id: 'bookly-new-appointment',
                label: BooklyL10n.new_appointment,
                icon: 'plus',
                variant: 'default',
                click: function () {
                    // When the wizard is the chosen creation flow, "New appointment"
                    // opens it instead of the classic form (setting replaces the flow,
                    // does not add a second button).
                    // Мосты визард↔классика: каждая форма получает колбэк открытия другой
                    // (кнопки рендерятся только при переданном колбэке — на страницах без
                    // бандла визарда классика остаётся прежней).
                    let openWizard = typeof BooklyBookingWizard !== 'undefined' ? function (params) {
                        BooklyBookingWizard.showDialog({
                            start_date: params && params.start_date,
                            start_time: params && params.start_time,
                            staff_id: params && params.staff_id,
                            service_id: params && params.service_id,
                            location_id: params && params.location_id,
                            onSaved: function () {
                                bt.reload();
                            },
                            onOpenClassic: openClassic
                        });
                    } : null;
                    function openClassic(params) {
                        BooklyAppointmentDialog.showDialog(
                            null,
                            params && params.staff_id ? params.staff_id : null,
                            params && params.date ? moment(params.date) : moment(),
                            function (event) {
                                bt.reload();
                            },
                            params ? { service_id: params.service_id, location_id: params.location_id } : null,
                            openWizard
                        );
                    }
                    if (wizardEnabled()) {
                        BooklyBookingWizard.showDialog({
                            onSaved: function () {
                                bt.reload();
                            },
                            // Header link in the wizard: escape hatch to the classic form
                            // (recurring, tasks and other features the wizard lacks).
                            onOpenClassic: openClassic
                        });
                    } else {
                        openClassic();
                    }
                }
            });
            return buttons;
        })(),
        searchFilter: {
            placeholder: BooklyL10n.search,
            name: 'filter[search]',
        },
    }
    options.datePicker = BooklyL10n.datePicker;
    let bt = BooklyDatatables.showForm('bookly-' + table + '-datatables', options);

    /**
     * Export form submit. Dialog opening is handled via topToolbar callback (see options.topToolbar).
     */
    $exportForm.on('submit', function () {
        $('[name="filter"]', $exportDialog).val(JSON.stringify({
            date: serializeDate(dateValue),
            created_date: serializeDate(createdDateValue),
            staff: staffValue,
            customer: customerValue,
            service: serviceValue,
            status: statusValue,
            location: locationValue,
        }));
        $exportDialog.booklyModal('hide');

        return true;
    });

    $exportSelectAll
        .on('click', function () {
            let checked = this.checked;
            $('.bookly-js-columns input', $exportDialog).each(function () {
                $(this).prop('checked', checked);
            });
        });

    $exportDialog
        .on('change', '.bookly-js-columns input', function () {
            $exportSelectAll.prop('checked', $('.bookly-js-columns input:checked', $exportDialog).length == $('.bookly-js-columns input', $exportDialog).length);
        });

    /**
     * Print confirm. Dialog opening is handled via topToolbar callback (see options.topToolbar).
     */
    $printButton.on('click', function () {
        let columns = [];
        $('.bookly-js-columns input:checked', $printDialog).each(function () {
            columns.push(parseInt(this.value));
        });
        bt.print(columns);
    });

    $printSelectAll
        .on('click', function () {
            let checked = this.checked;
            $('.bookly-js-columns input', $printDialog).each(function () {
                $(this).prop('checked', checked);
            });
        });

    $printDialog
        .on('change', '.bookly-js-columns input', function () {
            $printSelectAll.prop('checked', $('.bookly-js-columns input:checked', $printDialog).length == $('.bookly-js-columns input', $printDialog).length);
        });

    $appointmentsList
        .on('click', '[data-action=show-payment]', function () {
            BooklyPaymentDetailsDialog.showDialog({
                payment_id: $(this).data('payment_id'),
                done: function (event) {
                    bt.reload();
                }
            });
        });
});
