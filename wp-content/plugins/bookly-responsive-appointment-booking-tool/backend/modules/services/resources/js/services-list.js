jQuery(function ($) {
    'use strict';

    const table = 'services';
    const $servicesList = $('#bookly-services-datatables');
    const $deleteModal = $('.bookly-js-delete-cascade-confirm');
    const urlParts = document.URL.split('#');

    /**
     * Restore initial filter values from URL anchor or saved user_meta.
     * URL anchor wins (e.g. ?#category=42 deep-link).
     */
    let categoryValue = '';
    const savedFilter = BooklyL10n.datatables[table].settings.filter || {};

    if (urlParts.length > 1) {
        urlParts[1].split('&').forEach(function (part) {
            const params = part.split('=');
            if (params[0] === 'category') categoryValue = params[1] || '';
        });
    } else if (savedFilter.category) {
        categoryValue = String(savedFilter.category);
    }

    const categoryOptions = (BooklyL10n.categories || []).map(function (c) {
        return { value: String(c.id), label: c.name };
    });

    // If saved category id is no longer in the options list (deleted), drop it.
    if (categoryValue && !categoryOptions.some(function (o) { return o.value === categoryValue; })) {
        categoryValue = '';
    }

    /**
     * Init Columns.
     */
    let columns = [];

    // Backend (Ajax::getServices) searches by s.id, s.title, s.tags (Pro), c.name (category) —
    // every other column must opt out of the quick-search highlight.
    const searchableColumns = ['id', 'title', 'tags', 'category_name'];

    if (BooklyL10n.show_type) {
        columns.push({
            data: null,
            name: '__type_icon',
            permanent: true,
            class: 'bookly:w-8',
            show: true,
            orderable: false,
            searchable: false,
            render: function (data, type, row) {
                return '<i class="' + row.type_icon + ' fa-fw" title="' + row.type + '"></i>';
            },
        });
    }
    columns.push({
        data: null,
        name: '__color_dot',
        permanent: true,
        class: 'bookly:w-8',
        show: true,
        orderable: false,
        searchable: false,
        render: function (data, type, row) {
            return '<i class="fas fa-fw fa-circle" style="color:' + row.color + ';">';
        }
    });

    $.each(BooklyL10n.datatables[table].settings.columns, function (column, show) {
        switch (column) {
            case 'category_name':
                columns.push({
                    data: 'category_name',
                    render: function (data) {
                        // Server provides the joined category name (c.name) or null.
                        return data != null ? BooklyDatatables.escapeHtml(data) : BooklyL10n.uncategorized;
                    }
                });
                break;
            case 'image':
                // Image is rendered inline inside the Title cell; skip its own column.
                return;
            case 'title':
                columns.push({
                    data: column,
                    render: function (data, type, row) {
                        const title = BooklyDatatables.escapeHtml(data);
                        if (row.image) {
                            return '<span class="bookly:inline-flex bookly:items-center bookly:gap-2 bookly:align-middle"><img class="bookly:datatable-thumb" src="' + row.image + '"/>' + title + '</span>';
                        }
                        return title;
                    }
                });
                break;
            case 'online_meetings':
                columns.push({
                    data: column,
                    render: function (data) {
                        switch (data) {
                            case 'zoom':        return '<span class="badge badge-secondary"><i class="fas fa-video fa-fw"></i> Zoom</span>';
                            case 'google_meet': return '<span class="badge badge-secondary"><i class="fas fa-video fa-fw"></i> Meet</span>';
                            case 'jitsi':       return '<span class="badge badge-secondary"><i class="fas fa-video fa-fw"></i> Jitsi Meet</span>';
                            case 'bbb':         return '<span class="badge badge-secondary"><i class="fas fa-video fa-fw"></i> BigBlueButton</span>';
                            case 'teams':       return '<span class="badge badge-secondary"><i class="fas fa-video fa-fw"></i> Microsoft Teams</span>';
                            default:            return '';
                        }
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
                            if (BooklyProL10nServiceEditDialog && BooklyProL10nServiceEditDialog.tags) {
                                const _tag = BooklyProL10nServiceEditDialog.tags.tagsList.find(function (t) {
                                    return t.tag.toLowerCase() === tag.toLowerCase();
                                });
                                if (_tag) {
                                    color = BooklyProL10nServiceEditDialog.tags.colors[_tag.color_id];
                                }
                            }
                            text += '<span class="badge p-2 text-white" style="background-color: ' + color + '">' + BooklyDatatables.escapeHtml(tag) + '</span>';
                        });
                        return text + '</div>';
                    }
                });
                break;
            case 'duration':
            case 'price':
                columns.push({
                    data: column,
                    render: function (data) { return BooklyDatatables.escapeHtml(data); }
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
        columns[columns.length - 1].searchable = searchableColumns.indexOf(column) !== -1;
    });

    /**
     * Init DataTables.
     */
    let bt = BooklyDatatables.showForm('bookly-' + table + '-datatables', {
        ajax: {
            url: ajaxurl,
            method: 'POST',
            data: function (d) {
                return $.extend({
                    action: 'bookly_get_services',
                    csrf_token: BooklyL10nGlobal.csrf_token,
                    filter: { category: categoryValue }
                }, d);
            },
        },
        columns: columns,
        tableSettings: Object.assign({}, BooklyL10n.datatables[table], { l10n: Object.assign({}, BooklyL10n.datatables.l10n, { zeroRecords: BooklyL10n.zeroRecords }) }),
        emptyState: {
            icon: 'layers',
            title: BooklyL10n.empty_title,
            text: BooklyL10n.empty_text,
            actions: ['bookly-new-service', 'bookly-services-categories-btn']
        },
        rowClass: function (row) {
            return row.disabled ? 'bookly:text-gray-400' : '';
        },
        edit: function (row) {
            $(document.body).trigger('service.edit', [row.id]);
        },
        rowActions: function (row) {
            return [{
                label: BooklyL10n.duplicate,
                icon: 'copy-plus',
                variant: 'outline',
                click: function (r) {
                    if (!confirm(BooklyL10n.are_you_sure + '\n\n' + BooklyL10n.private_warning)) return;
                    bt.setLoading(true);
                    $.post(
                        ajaxurl,
                        {
                            action: 'bookly_duplicate_service',
                            service_id: r.id,
                            csrf_token: BooklyL10nGlobal.csrf_token,
                        },
                        function (response) {
                            if (response.success) {
                                bt.reload();
                                BooklyL10n.service_order.push({ id: response.data.id, title: response.data.title });
                            } else {
                                requiredBooklyPro();
                            }
                            bt.setLoading(false);
                        }
                    );
                }
            }];
        },
        checked: function () {
            return [{
                label: BooklyL10n.delete,
                icon: 'trash',
                variant: 'destructive',
                click: function () { $deleteModal.booklyModal('show'); }
            }];
        },
        filters: [
            {
                type: 'select',
                name: 'category',
                label: BooklyL10n.filters.category,
                initialValue: categoryValue,
                searchPlaceholder: BooklyL10n.filters.searchPlaceholder,
                options: categoryOptions,
                onChange: function (v) { categoryValue = v; },
            },
        ],
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
            buttons.push({
                label: BooklyL10n.order,
                icon: 'list-ordered',
                variant: 'outline',
                click: openReorder
            });
            if (BooklyL10n.proEnabled) {
                buttons.push({
                    label: BooklyL10n.tags,
                    icon: 'tag',
                    variant: 'outline',
                    click: function () { $('#bookly-service-tags-modal').booklyModal('show'); }
                });
            }
            buttons.push({
                id: 'bookly-services-categories-btn',
                label: BooklyL10n.manage_categories,
                icon: 'folder',
                variant: 'outline',
                click: function () { $('#bookly-service-categories-modal').booklyModal('show'); }
            });
            buttons.push({
                id: 'bookly-new-service',
                label: BooklyL10n.new_service,
                icon: 'plus',
                variant: 'default',
                click: function () { $('#bookly-create-service-modal').booklyModal('show'); }
            });
            return buttons;
        })(),
        searchFilter: {
            placeholder: BooklyL10n.search,
            name: 'filter[search]',
        }
    });

    /**
     * Delete confirm dialog.
     */
    $('.bookly-js-delete', $deleteModal).on('click', function () {
        const data = {
            action: 'bookly_remove_services',
            csrf_token: BooklyL10nGlobal.csrf_token,
        };
        const ladda = rangeTools.ladda(this);
        const service_ids = bt.getCheckedRows().map(function (row) { return row.id; });

        data['service_ids[]'] = service_ids;

        $.post(ajaxurl, data, function () {
            $(document.body).trigger('service.deleted', [service_ids]);
            bt.reload();
            ladda.stop();
            $deleteModal.booklyModal('hide');
        });
    });

    $('.bookly-js-edit', $deleteModal).on('click', function () {
        rangeTools.ladda(this);
        window.location.href = BooklyL10n.appointmentsUrl + '#service=' + bt.getCheckedRows()[0].id;
    });

    /**
     * Reorder dialog. The full simple-service collection (position order) lives
     * in BooklyL10n.service_order and is kept in sync below — the server-side
     * table can't supply the whole list.
     */
    function openReorder() {
        const items = BooklyL10n.service_order.map(function (s) {
            return { id: s.id, label: s.title || '' };
        });

        BooklyDatatables.showReorder({
            title: BooklyL10n.service_order_title,
            hint: BooklyL10n.service_order_hint,
            items: items,
            saveLabel: BooklyL10n.datatables.l10n.save,
            cancelLabel: BooklyL10n.datatables.l10n.cancel,
            onSave: function (orderedIds) {
                return $.post(ajaxurl, booklySerialize.buildRequestData('bookly_update_service_positions', { services: orderedIds }))
                    .then(function () {
                        BooklyL10n.service_order.sort(function (a, b) {
                            return orderedIds.indexOf(a.id) - orderedIds.indexOf(b.id);
                        });
                        bt.reload();
                    });
            }
        });
    }

    /**
     * Keep BooklyL10n.service_order in sync with service edits/deletes so the
     * reorder dialog reflects changes without a page reload.
     */
    $(document.body)
        .on('service.submitForm', function (event, $panel, data) {
            const svc = BooklyL10n.service_order.find(function (s) { return s.id == data.id; });
            if (svc) {
                svc.title = data.title;
            }
        })
        .on('service.deleted', function (event, services) {
            const ids = services.map(Number);
            BooklyL10n.service_order = BooklyL10n.service_order.filter(function (s) {
                return ids.indexOf(Number(s.id)) === -1;
            });
        });
});
