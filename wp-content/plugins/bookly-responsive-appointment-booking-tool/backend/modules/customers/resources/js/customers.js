jQuery(function ($) {
    'use strict';

    const table = 'customers';
    const $customersList = $('#bookly-customers-datatables');
    const $mergeDialog = $('#bookly-merge-dialog');
    const $importDialog = $('#bookly-import-customers-dialog');
    const $mergeButton = $('#bookly-merge', $mergeDialog);
    const $exportDialog = $('#bookly-export-customers-dialog');
    const $exportSelectAll = $('#bookly-js-export-select-all', $exportDialog);

    const info_renders = {};
    for (const a in BooklyL10n.infoFields) {
        if (BooklyL10n.infoFields[a].type === 'file') {
            info_renders[BooklyL10n.infoFields[a].id] = function (data) {
                return data !== '' ? '<button type="button" class="btn btn-link p-0" data-download-file="' + data + '" title="' + BooklyL10n.download + '"><i class="fas fa-fw fa-paperclip"></i></button>' : '';
            };
        } else {
            info_renders[BooklyL10n.infoFields[a].id] = function (data) {
                return BooklyDatatables.escapeHtml(data);
            };
        }
    }

    /**
     * Init Columns. The `image` column is rendered inline inside `full_name` so it
     * shares vertical space with the customer name.
     */
    let columns = [];

    $.each(BooklyL10n.datatables[table].settings.columns, function (column, show) {
        switch (column) {
            case 'id':
                columns.push({
                    data: column,
                    render: function (data) { return BooklyDatatables.escapeHtml(data); }
                });
                break;
            case 'last_appointment':
            case 'total_appointments':
            case 'payments':
            case 'wp_user':
                columns.push({
                    data: column,
                    searchable: false,
                    render: function (data) { return BooklyDatatables.escapeHtml(data); }
                });
                break;
            case 'image':
                // Rendered inline in full_name cell; skip own column.
                return;
            case 'full_name':
                columns.push({
                    data: column,
                    render: function (data, type, row) {
                        const name = BooklyDatatables.escapeHtml(data);
                        if (row.image) {
                            return '<span class="bookly:inline-flex bookly:items-center bookly:gap-2 bookly:align-middle"><img class="bookly:datatable-thumb" src="' + row.image + '"/>' + name + '</span>';
                        }
                        return name;
                    }
                });
                break;
            case 'address':
                columns.push({
                    data: column,
                    orderable: false,
                    searchable: false,
                    render: function (data) { return BooklyDatatables.escapeHtml(data); },
                });
                break;
            case 'facebook':
                columns.push({
                    data: 'facebook_id',
                    searchable: false,
                    render: function (data) {
                        return data ? '<a href="https://www.facebook.com/app_scoped_user_id/' + data + '/" target="_blank"><span class="dashicons dashicons-facebook"></span></a>' : '';
                    }
                });
                break;
            case 'phone':
                columns.push({
                    data: column,
                    render: function (data) {
                        return data
                            ? '<span style="white-space: nowrap;">'
                                + window.booklyIntlTelInput.utils.formatNumber(BooklyDatatables.escapeHtml(data), null, window.booklyIntlTelInput.utils.numberFormat.INTERNATIONAL)
                                + '</span>'
                            : '';
                    }
                });
                break;
            case 'tags':
                columns.push({
                    data: 'tags',
                    render: function (data) {
                        if (!data) return '';
                        let text = '<div class="bookly:flex bookly:flex-wrap bookly:gap-1">';
                        JSON.parse(data).forEach(function (tag) {
                            let color = '#000';
                            if (BooklyL10n.tagsData !== undefined) {
                                const _tag = BooklyL10n.tagsData.list.find(function (t) {
                                    return t.tag.toLowerCase() === tag.toLowerCase();
                                });
                                if (_tag) {
                                    color = BooklyL10n.tagsData.colors[_tag.color_id];
                                }
                            }
                            text += '<span class="badge p-2 text-white" style="background-color: ' + color + '">' + BooklyDatatables.escapeHtml(tag) + '</span>';
                        });
                        return text + '</div>';
                    }
                });
                break;
            case 'birthday':
                columns.push({
                    data: 'birthday',
                    searchable: false,
                    render: function (data, type, row) { return row.birthday_formatted; }
                });
                break;
            case 'notes':
                columns.push({
                    data: column,
                    searchable: false,
                    render: function (data) { return BooklyDatatables.escapeHtml(data); }
                });
                break;
            default:
                if (column.startsWith('info_fields_')) {
                    columns.push({
                        data: column.replace(/_([^_]*)$/, '.$1'),
                        render: info_renders[parseInt(column.split('_').pop())],
                        orderable: false
                    });
                } else {
                    columns.push({
                        data: column,
                        render: function (data) { return BooklyDatatables.escapeHtml(data); }
                    });
                }
                break;
        }
        columns[columns.length - 1].title = BooklyL10n.datatables[table].titles[column] || column;
        columns[columns.length - 1].name = column;
        columns[columns.length - 1].show = show;
    });

    let bt = BooklyDatatables.showForm('bookly-' + table + '-datatables', {
        ajax: {
            url: ajaxurl,
            method: 'POST',
            data: function (d) {
                return $.extend({}, d, {
                    action: 'bookly_get_customers',
                    csrf_token: BooklyL10nGlobal.csrf_token,
                });
            }
        },
        columns: columns,
        tableSettings: Object.assign({}, BooklyL10n.datatables[table], { l10n: Object.assign({}, BooklyL10n.datatables.l10n, { zeroRecords: BooklyL10n.zeroRecords }) }),
        emptyState: {
            icon: 'users',
            title: BooklyL10n.empty_title,
            text: BooklyL10n.empty_text,
            actions: ['bookly-new-customer', 'bookly-import-customers']
        },
        edit: function (row) {
            BooklyCustomerDialog.showDialog({
                action: 'load',
                customerId: row.id,
                onDone: function (customer, result) {
                    bt.reload();
                    if (result && result.new_tags.length) {
                        BooklyL10n.tagsData.list = BooklyL10n.tagsData.list.concat(result.new_tags);
                    }
                }
            });
        },
        checked: function (rows) {
            const actions = [];
            if (rows.length > 1) {
                actions.push({
                    label: BooklyL10n.merge,
                    icon: 'merge',
                    variant: 'outline',
                    click: function () { $customersList.trigger('bookly:customers-merge'); }
                });
            }
            actions.push({
                label: BooklyL10n.delete,
                icon: 'trash',
                variant: 'destructive',
                click: function () { $customersList.trigger('bookly:customers-delete'); }
            });
            return actions;
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
                            columnsHtml += '<div class="custom-control custom-checkbox"><input class="custom-control-input" id="bookly-eс-' + index + '" name="exp[' + column.name + ']" type="checkbox"' + (column.show ? 'checked' : '') + '><label class="custom-control-label" for="bookly-eс-' + index + '">' + column.title + '</label></div>';
                        });
                        $('.bookly-js-columns', $exportDialog).html(columnsHtml);
                        $exportDialog.booklyModal('show');
                    }
                });
                buttons.push({
                    id: 'bookly-import-customers',
                    label: BooklyL10n.import,
                    icon: 'upload',
                    variant: 'outline',
                    click: function () { $importDialog.booklyModal('show'); }
                });
            }
            buttons.push({
                id: 'bookly-new-customer',
                label: BooklyL10n.new_customer,
                icon: 'plus',
                variant: 'default',
                click: function () {
                    BooklyCustomerDialog.showDialog({
                        action: 'create',
                        onDone: function (customer, result) {
                            if (result && result.new_tags.length) {
                                BooklyL10n.tagsData.list = BooklyL10n.tagsData.list.concat(result.new_tags);
                            }
                            bt.reload();
                        }
                    });
                }
            });
            return buttons;
        })(),
        searchFilter: {
            placeholder: BooklyL10n.search,
            name: 'filter',
        }
    });

    /**
     * Download attached files in cells.
     */
    $customersList.on('click', '[data-download-file]', function (e) {
        e.preventDefault();
        window.open(ajaxurl + (ajaxurl.indexOf('?') > 0 ? '&' : '?') + 'action=bookly_files_download&slug=' + $(this).data('download-file') + '&csrf_token=' + BooklyL10nGlobal.csrf_token, '_blank');
    });

    /**
     * Merge customers — triggered by the new datatables action.
     */
    $customersList.on('bookly:customers-merge', function () {
        const $target = $('#bookly-merge-customers-target', $mergeDialog);
        let columnsHtml = '<select class="form-control" id="bookly-merge-customers-target-customer" name="target_customer">';
        bt.getCheckedRows().forEach(function (customer) {
            let name = customer.full_name;
            if (customer.email !== '' || customer.phone !== '') {
                name += ' (';
                if (customer.email !== '') {
                    name += customer.email;
                    if (customer.phone !== '') name += ', ';
                }
                if (customer.phone !== '') name += customer.phone;
                name += ')';
            }
            columnsHtml += '<option value="' + customer.id + '">' + name + '</option>';
        });
        columnsHtml += '</select>';
        $target.html(columnsHtml);
        $mergeDialog.booklyModal('show');
    });

    $mergeButton.on('click', function (e) {
        e.preventDefault();
        const ladda = Ladda.create(this);
        const ids = bt.getCheckedRows().map(function (customer) { return customer.id; });
        ladda.start();
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'bookly_merge_customers',
                csrf_token: BooklyL10nGlobal.csrf_token,
                target_id: $('#bookly-merge-customers-target-customer', $mergeDialog).val(),
                ids: ids
            },
            dataType: 'json',
            success: function (response) {
                ladda.stop();
                $mergeDialog.booklyModal('hide');
                if (response.success) {
                    bt.reload();
                } else {
                    alert(response.data.message);
                }
            }
        });
    });

    /**
     * Export dialog — column checkbox sync.
     */
    $exportSelectAll.on('click', function () {
        const checked = this.checked;
        $('.bookly-js-columns input', $exportDialog).each(function () { $(this).prop('checked', checked); });
    });
    $exportDialog.on('change', '.bookly-js-columns input', function () {
        $exportSelectAll.prop('checked', $('.bookly-js-columns input:checked', $exportDialog).length === $('.bookly-js-columns input', $exportDialog).length);
    });

    Ladda.bind('#bookly-import-customers-dialog button[type=submit]');
    Ladda.bind('#bookly-export-customers-dialog button[type=submit]', { timeout: 2000 });
});
