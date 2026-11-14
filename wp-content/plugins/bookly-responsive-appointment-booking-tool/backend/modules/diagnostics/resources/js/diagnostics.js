jQuery(function ($) {
    'use strict';

    let reloadTestButtons = [];
    // Common
    let url_params = {};
    try {
        let queryString = window.location.search[0] === '?'
            ? window.location.search.slice(1)
            : window.location.search;
        let queries = queryString.split('&');

        queries.forEach(function (query) {
            let pair = query.split('='),
                key = decodeURIComponent(pair[0]);

            url_params[key] = decodeURIComponent(pair[1] || '');
        });
    } catch (e) { }

    function setCookie(name, value) {
        let expires = new Date();
        expires.setFullYear(expires.getFullYear() + 3);
        document.cookie = name + '=' + (value || '') + ';expires=' + expires.toUTCString() + ';path=/';
    }

    function getCookie(name) {
        let keyValue = document.cookie.match('(^|;) ?' + name + '=([^;]*)(;|$)');
        return keyValue ? keyValue[2] : null;
    }

    // All tests
    function runAllTests() {
        reloadTestButtons = [];
        $('.bookly-js-tests .bookly-js-reload-test').each(function () {
            reloadTestButtons.push($(this));
        });
        runNextTest();
    }

    function runNextTest() {
        if (reloadTestButtons.length > 0) {
            reloadTestButtons[0].trigger('click');
            reloadTestButtons.shift();
        }
    }

    $('.bookly-js-reload-test').on('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        let $test = $(this).closest('.bookly-js-test'),
            $loading = $test.find('.bookly-js-loading-test'),
            $reload = $test.find('.bookly-js-reload-test'),
            $success = $test.find('.bookly-js-success-test'),
            $failed = $test.data('error-type') === 'warning' ? $test.find('.bookly-js-warning-test') : $test.find('.bookly-js-failed-test'),
            $errors = $test.find('.bookly-js-test-errors')
        ;
        $loading.show();
        $reload.hide();
        $success.hide();
        $failed.hide();
        $errors.hide();
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bookly_run_diagnostics_test',
                test: $test.data('class'),
                csrf_token: BooklyL10nGlobal.csrf_token
            },
            dataType: 'json',
            error: function () {
                $loading.hide();
                $reload.show();
                $failed.show();
            }
        }).then(function (response) {
            if ($test.data('test') !== 'check-sessions') {
                $loading.hide();
                $reload.show();
                if (response.success) {
                    $success.show();
                } else {
                    $failed.show();
                    if (response.data.errors.length > 0) {
                        $errors.text(response.data.errors.join(' / ')).show();
                    }
                }
                runNextTest();
            } else {
                // Sessions test ajax calls
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'bookly_diagnostics_ajax',
                        test: $test.data('class'),
                        ajax: 'ajax1',
                        csrf_token: BooklyL10nGlobal.csrf_token
                    }
                }).then(function (response) {
                    if (response.success) {
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'bookly_diagnostics_ajax',
                                test: $test.data('class'),
                                ajax: 'ajax2',
                                csrf_token: BooklyL10nGlobal.csrf_token
                            }
                        }).then(function (response) {
                            $loading.hide();
                            $reload.show();
                            if (response.success) {
                                $success.show();
                            } else {
                                $failed.show();
                                $errors.text(response.data.errors.join(' / ')).show();
                            }
                            runNextTest();
                        });
                    } else {
                        $loading.hide();
                        $reload.show();
                        $failed.show();
                        $errors.text(response.data.errors.join(' / ')).show();
                        runNextTest();
                    }
                })
            }
        })
    });

    // Autorun is ON by default: run all tests on load unless the user stopped it.
    let autorun = getCookie('bookly_diagnostic_autorun_tests');
    if (autorun === '0') {
        $('.bookly-js-tests').each(function () {
            $('.bookly-js-loading-test', $(this)).hide();
            $('.bookly-js-reload-test', $(this)).show();
        });
    } else {
        runAllTests();
    }

    // The autorun control lives in the async-mounted Svelte page header as two
    // buttons (Stop / Run) that swap visibility — bind via delegation. Initial
    // visibility is server-set from the cookie.
    $(document).on('click', '.bookly-js-stop-autorun', function () {
        setCookie('bookly_diagnostic_autorun_tests', '0');
        $('.bookly-js-stop-autorun').prop('hidden', true);
        $('.bookly-js-run-autorun').prop('hidden', false);
    });
    $(document).on('click', '.bookly-js-run-autorun', function () {
        setCookie('bookly_diagnostic_autorun_tests', '1');
        $('.bookly-js-run-autorun').prop('hidden', true);
        $('.bookly-js-stop-autorun').prop('hidden', false);
        runAllTests();
    });

    // Tools
    // Data Management

    $('.bookly-js-tables-dropdown').on('click', function (e) {
        e.stopPropagation();
    });
    $('#bookly_import_file').change(function (e) {
        if ($(this).val()) {
            let $spinner = $(this).siblings('.bookly-js-spinner');
            $spinner.show();
            const formData = new FormData();
            formData.append('action', 'bookly_import_data');
            formData.append('csrf_token', BooklyL10nGlobal.csrf_token);
            formData.append('safe', $('[name=safe]', $(this).closest('.input-group')).prop('checked') ? '1' : '0');
            formData.append('import', e.target.files[0]);
            fetch(ajaxurl, {method: 'POST', body: formData})
                .then(function (response) {
                    if (response.status == 200) {
                        return response.json();
                    }
                    $spinner.hide();
                    throw new Error(response.statusText + ' (' + response.status + ')');
                })
                .then(function (result) {
                    if (result.success) {
                        booklyAlert({success: [result.data.message]});
                    } else {
                        booklyAlert({error: result.data.message});
                    }
                    $spinner.hide();
                    let $modal = booklyModal('Are you sure you want to reload this page?', null, 'No', 'Reload')
                        .on('bs.click.main.button', function (event, modal, mainButton) {
                            location.reload();
                        });
                    setTimeout(function () {
                        $('.modal-footer .btn-success', $modal).focus();
                    }, 500);
                }).catch(function (error) {
                booklyAlert({error: ['Request failed: ' + error]});
            });
        }
    });

    // Forms Data
    $('#forms-data button[data-action="copy"]').on('click', function () {
        let $button = $(this),
            form_id = $button.closest('.list-group-item-action').data('form_id'),
            data = $button.closest('.list-group-item-action').data('form_data'),
            $copied = $('<small>', {
                class: 'ml-2',
                text: 'copied'
            });
        $button.before($copied);
        $button.hide();
        const $temp = $('<input/>');
        $('body').append($temp);
        $temp.val(JSON.stringify(data)).select();
        document.execCommand('copy');
        $temp.remove();
        console.group(form_id);
        console.dir(data);
        console.groupEnd();
        setTimeout(function () {
            $copied.remove();
            $button.show();
        }, 1000);
    });
    $('#forms-data button[data-action="destroy"]').on('click', function () {
        let ladda = Ladda.create(this);
        ladda.start();
        let $li = $(this).closest('.list-group-item-action');
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bookly_diagnostics_ajax',
                tool: 'FormsData',
                ajax: 'destroy',
                form_id: $li.data('form_id'),
                csrf_token: BooklyL10nGlobal.csrf_token
            },
            dataType: 'json',
            success: function (response) {
                ladda.stop();
                $li.remove();
            }
        });
    });

    // External plugins
    $('#external-plugins button').on('click', function () {
        let $plugin = $(this).closest('.list-group-item-action'),
            action = $(this).data('action'),
            ladda = Ladda.create(this);
        ladda.start();
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bookly_diagnostics_ajax',
                tool: 'ExternalPlugins',
                ajax: action,
                plugin: $plugin.data('plugin'),
                csrf_token: BooklyL10nGlobal.csrf_token
            },
            dataType: 'json',
            success: function (response) {
                ladda.stop();
                if (response.success) {
                    switch (action) {
                        case 'install':
                        case 'activate':
                            $plugin.find('button').hide();
                            $plugin.find('button[data-action="delete"]').show();
                            break;
                        case 'delete':
                            $plugin.find('button').hide();
                            $plugin.find('button[data-action="install"]').show();
                            break;
                    }
                } else {
                    booklyAlert('Failed');
                }
            }
        });
    });

    // Shortcodes
    $('#bookly-find-shortcode-and-open').on('click', function () {
        let ladda = Ladda.create(this);
        ladda.start();
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bookly_diagnostics_ajax',
                tool: 'ShortCodes',
                ajax: 'find',
                shortcode: $('#bookly_shortcode').val(),
                csrf_token: BooklyL10nGlobal.csrf_token
            },
            dataType: 'json',
            success: function (response) {
                ladda.stop();
                if (response.success) {
                    window.open(response.data.url, '_blank').focus();
                }
            }
        });
    });

    // Advanced options
    $('#advanced-options').on('click', 'button[data-action="set-default-option"]', function () {
        let ladda = Ladda.create(this);
        let $that = $(this);
        ladda.start();
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bookly_diagnostics_ajax',
                tool: 'AdvancedOptions',
                ajax: 'setDefault',
                option: $that.data('option'),
                csrf_token: BooklyL10nGlobal.csrf_token
            },
            dataType: 'json',
            success: function () {
                ladda.stop();
                $that.closest('.bookly-js-advanced-option-card').hide();
            }
        });
    }).on('click', '.bookly-js-advanced-options-option-name button', function () {
        let ladda = Ladda.create(this);
        ladda.start();
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bookly_diagnostics_ajax',
                tool: 'AdvancedOptions',
                ajax: 'getOption',
                option: $('#bookly-advanced-options-option-name').val(),
                csrf_token: BooklyL10nGlobal.csrf_token
            },
            dataType: 'json',
            success: function (response) {
                ladda.stop();
                if (response.success) {
                    $('#bookly-advanced-options-option-current-value').val(response.data.current);
                    $('#bookly-advanced-options-option-default-value').val(response.data.default);
                    $('#bookly-advanced-options-option-value').val(response.data.current);
                    $('.bookly-js-advanced-options-view-option').show();
                    // The option holds credentials and is not shown to users without 'manage_options'.
                    $('.bookly-js-advanced-options-set-option').toggle(!response.data.protected);
                } else {
                    $('#bookly-advanced-options-option-current-value').val('');
                    $('#bookly-advanced-options-option-default-value').val('');
                    $('#bookly-advanced-options-option-value').val('');
                    $('.bookly-js-advanced-options-view-option').hide();
                    booklyAlert({error: [response.data && response.data.message || 'Unknown option']});
                }
            }
        });
    }).on('click', '.bookly-js-advanced-options-set-option button', function () {
        let ladda = Ladda.create(this);
        ladda.start();
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bookly_diagnostics_ajax',
                tool: 'AdvancedOptions',
                ajax: 'setOption',
                option: $('#bookly-advanced-options-option-name').val(),
                value: $('#bookly-advanced-options-option-value').val(),
                csrf_token: BooklyL10nGlobal.csrf_token
            },
            dataType: 'json',
            success: function (response) {
                ladda.stop();
                if (response.success) {
                    $('#bookly-advanced-options-option-current-value').val($('#bookly-advanced-options-option-value').val());
                } else {
                    booklyAlert({error: [response.data && response.data.message || 'Failed']});
                }
            }
        });
    });

    // Optional logs
    $('.bookly-js-enable-optional-logs').on('click', function () {
        let $that = $(this),
            ladda = Ladda.create(this);
        ladda.start();
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bookly_diagnostics_ajax',
                tool: 'Logs',
                ajax: 'enableLogs',
                option: $that.closest('.bookly-js-optional-logs-entry').data('option'),
                period: $that.data('period'),
                csrf_token: BooklyL10nGlobal.csrf_token
            },
            dataType: 'json',
            success: function (response) {
                ladda.stop();
                if (response.data.until) {
                    $that.closest('.bookly-js-optional-logs-entry').find('.bookly-js-optional-logs-until').text(response.data.until);
                    $that.closest('.bookly-js-optional-logs-entry').find('.bookly-js-optional-logs-active').show();
                } else {
                    $that.closest('.bookly-js-optional-logs-entry').find('.bookly-js-optional-logs-active').hide();
                }
            }
        });
    });

    // Logs
    $('#bookly_logs_expire').change(function (e) {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bookly_set_logs_expire',
                expire: $(this).val(),
                csrf_token: BooklyL10nGlobal.csrf_token,
            },
            dataType: 'json'
        });
    });
    // ── Logs (Svelte 5 datatable) ─────────────────────────────────────────
    let logsBt = null;
    const logsTable = 'logs';
    const ll = window.BooklyLogsL10n;
    const lcal = window.BooklyDatatables.calendarDate;
    const ltz = lcal.getLocalTimeZone();
    const lt = lcal.today(ltz);
    const lymd = (d) => d.year + '-' + String(d.month).padStart(2, '0') + '-' + String(d.day).padStart(2, '0');
    const lStartOfMonth = (d) => d.set({ day: 1 });
    const lEndOfMonth = (d) => d.set({ day: 1 }).add({ months: 1 }).subtract({ days: 1 });

    function logsSerializeDate(v) {
        if (!v || !v.start || !v.end) return 'any';
        return lymd(v.start) + ' ' + (v.startTime || '00:00') + ':00 - ' + lymd(v.end) + ' ' + (v.endTime || '23:59') + ':00';
    }

    // Default: last 30 days, full days.
    let logsDate = { start: lt.subtract({ days: 30 }), end: lt, startTime: '00:00', endTime: '23:59' };
    let logsAction = ll.debug ? ['error', 'debug'] : [];
    let logsTarget = '';

    const logsDatePresets = [
        { label: ll.dateRange.yesterday, range: { start: lt.subtract({ days: 1 }),  end: lt.subtract({ days: 1 }) } },
        { label: ll.dateRange.today,     range: { start: lt,                        end: lt                       } },
        { label: ll.dateRange.last_7,    range: { start: lt.subtract({ days: 7 }),  end: lt                       } },
        { label: ll.dateRange.last_30,   range: { start: lt.subtract({ days: 30 }), end: lt                       } },
        { label: ll.dateRange.thisMonth, range: { start: lStartOfMonth(lt),         end: lEndOfMonth(lt)          } },
    ];

    function clearAllLogs() {
        if (!confirm(BooklyL10nGlobal.l10n.areYouSure)) return;
        if (logsBt) logsBt.setLoading(true);
        $.ajax({
            url: ajaxurl, type: 'POST', dataType: 'json',
            data: { action: 'bookly_delete_logs', csrf_token: BooklyL10nGlobal.csrf_token },
            success: function () { if (logsBt) logsBt.reload(); }
        });
    }

    function restoreLogs(ids) {
        if (!ids.length || !confirm(BooklyL10nGlobal.l10n.areYouSure)) return;
        if (logsBt) logsBt.setLoading(true);
        $.ajax({
            url: ajaxurl, method: 'POST', dataType: 'json',
            data: { action: 'bookly_diagnostics_ajax', tool: 'Logs', ajax: 'restore', ids: ids, csrf_token: BooklyL10nGlobal.csrf_token },
            success: function (response) { booklyAlert(response.success ? { success: ['Success'] } : { error: ['Failed'] }); },
            error: function () { booklyAlert({ error: ['Failed'] }); }
        }).always(function () { if (logsBt) logsBt.reload(); });
    }

    $('[href=#logs]').one('click', function () {
        let columns = [];
        // Backend (Ajax::getLogs) searches by target, details, target_id, ref,
        // comment, author, id — created_at and action aren't searchable.
        const searchableColumns = ['id', 'target', 'target_id', 'author', 'details', 'comment', 'ref'];
        $.each(ll.datatables[logsTable].settings.columns, function (column, show) {
            switch (column) {
                case 'action':
                    columns.push({
                        data: 'action',
                        render: function (data, type, row) {
                            return data === 'error' && row.target && row.target.indexOf('bookly-') !== -1
                                ? '<span class="bookly:text-red-600 bookly:font-medium">ERROR</span>'
                                : BooklyDatatables.escapeHtml(data);
                        }
                    });
                    break;
                case 'target':
                    columns.push({
                        data: 'target',
                        render: function (data) {
                            if (!data) return '';
                            const text = data.indexOf('bookly-') !== -1 ? data.slice(data.indexOf('bookly-')) : data;
                            return '<span dir="rtl" class="bookly:inline-block bookly:max-w-full bookly:overflow-hidden bookly:text-ellipsis bookly:whitespace-nowrap bookly:align-bottom">' + BooklyDatatables.escapeHtml(text) + '</span>';
                        }
                    });
                    break;
                case 'details':
                    columns.push({
                        data: 'details',
                        orderable: false,
                        render: function (data) {
                            try {
                                return '<pre class="bookly:m-0 bookly:whitespace-pre-wrap bookly:text-xs">' + BooklyDatatables.escapeHtml(JSON.stringify(JSON.parse(data), null, 2)) + '</pre>';
                            } catch (e) {
                                return BooklyDatatables.escapeHtml(data);
                            }
                        }
                    });
                    break;
                case 'ref':
                    columns.push({
                        data: 'ref',
                        orderable: false,
                        render: function (data) { return data ? BooklyDatatables.escapeHtml(data).replace(/\n/g, '<br>') : ''; }
                    });
                    break;
                default:
                    columns.push({ data: column, render: BooklyDatatables.escapeHtml() });
                    break;
            }
            columns[columns.length - 1].title = ll.datatables[logsTable].titles[column] || column;
            columns[columns.length - 1].name = column;
            columns[columns.length - 1].show = show;
            columns[columns.length - 1].searchable = searchableColumns.indexOf(column) !== -1;
        });

        logsBt = BooklyDatatables.showForm('bookly-logs-datatables', {
            datePicker: ll.datePicker,
            ajax: {
                url: ajaxurl, method: 'POST',
                data: function (d) {
                    return $.extend({
                        action: 'bookly_get_logs', csrf_token: BooklyL10nGlobal.csrf_token,
                        filter: { created_at: logsSerializeDate(logsDate), action: logsAction, target: logsTarget }
                    }, d);
                }
            },
            columns: columns,
            tableSettings: Object.assign({}, ll.datatables[logsTable], {
                l10n: Object.assign({}, ll.datatables.l10n, { zeroRecords: ll.zeroRecords })
            }),
            searchFilter: { placeholder: ll.search, name: 'filter[search]' },
            saveSettings: function (settings) {
                $.post(ajaxurl, Object.assign({ action: 'bookly_update_table_settings', table: logsTable, csrf_token: BooklyL10nGlobal.csrf_token }, settings));
            },
            checked: function (rows) {
                const actions = [];
                if (rows.some(function (r) { return r.action === 'delete'; })) {
                    actions.push({
                        label: ll.restore, icon: 'arrow-left', variant: 'outline',
                        click: function (sel) { restoreLogs(sel.filter(function (r) { return r.action === 'delete'; }).map(function (r) { return r.id; })); }
                    });
                }
                return actions;
            },
            filters: [
                { type: 'dateRange', name: 'created_at', label: ll.filters.date, initialValue: logsDate, withTime: true, presets: logsDatePresets, onChange: function (v) { logsDate = v; } },
                { type: 'checkboxGroup', name: 'action', label: ll.filters.action, initialValue: logsAction, options: ll.actionOptions, onChange: function (v) { logsAction = v; } },
                { type: 'text', name: 'target', label: ll.filters.target, initialValue: logsTarget, placeholder: ll.filters.target, onChange: function (v) { logsTarget = v; } },
            ],
            topToolbar: [
                { id: 'bookly-delete-logs', label: ll.clearAll, icon: 'trash', variant: 'outline', click: clearAllLogs },
            ],
        });
    });

    $('[data-tool]').on('click', function (e) {
        e.preventDefault();
        let ladda = Ladda.create(this),
            $button = $(this);
        ladda.start();
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'bookly_diagnostics_ajax',
                tool: $button.data('tool'),
                ajax: $button.data('ajax'),
                params: $button.data('params'),
                csrf_token: BooklyL10nGlobal.csrf_token
            },
            dataType: 'json',
            success: function (response) {
                if (response.success) {
                    if ($button.data('hide-on-success')) {
                        $button.closest($button.data('hide-on-success')).remove();
                    }
                    if ($button.data('hide-errors-on-success')) {
                        $button.closest('.card').find('.bookly-js-has-error').hide();
                    }
                }
                let message = response.hasOwnProperty('data') && response.data.hasOwnProperty('message') ? response.data.message : null;
                if (message) {
                    if (response.success) {
                        booklyAlert({success: [message]});
                    } else {
                        booklyAlert({error: [message]});
                    }
                }

                ladda.stop();
            },
            error: function () {
                booklyAlert({error: ['Error: in query execution.']});
                ladda.stop();
            },
        }).always(function () {
            ladda.stop();
        });
    })
})