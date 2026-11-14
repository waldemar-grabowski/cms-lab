jQuery(function($) {
    'use strict';

    const cabinetTable = 'cloud_mobile_staff_cabinet';
    const app_auth_url = 'https://app.bookly.pro/?token=';
    let columns        = [];

    $.each(BooklyL10n.datatables[cabinetTable].settings.columns, function(column, show) {
        switch (column) {
            case 'token':
                columns.push({data: column, render: BooklyDatatables.escapeHtml(), class: 'bookly:font-mono'});
                break;
            case 'full_name':
                columns.push({
                    data: column,
                    render: function(data, type, row) {
                        let name = data ? String(data).replace(/[&<>"']/g, function(c) {
                            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[c];
                        }) : '';
                        if (row.wp_user_id) {
                            return name + ' <span class="bookly:text-slate-400">(' + BooklyL10n.wp_user + ')</span>';
                        }
                        if (row.staff_id) {
                            return name + ' <span class="bookly:text-slate-400">(' + BooklyL10n.staff + ')</span>';
                        }
                        return name;
                    }
                });
                break;
            default:
                columns.push({data: column, render: BooklyDatatables.escapeHtml()});
                break;
        }
        columns[columns.length - 1].title = BooklyL10n.datatables[cabinetTable].titles[column] || column;
        columns[columns.length - 1].name  = column;
        columns[columns.length - 1].show  = show;
    });

    let bt = BooklyDatatables.showForm('bookly-' + cabinetTable + '-datatables', {
        serverSide: false,
        ajax: {
            url: ajaxurl,
            method: 'POST',
            data: function(d) {
                return $.extend({}, d, {
                    action: 'bookly_cloud_mobile_staff_cabinet_get_access_tokens',
                    csrf_token: BooklyL10nGlobal.csrf_token
                });
            }
        },
        columns: columns,
        tableSettings: Object.assign({}, BooklyL10n.datatables[cabinetTable], {
            l10n: Object.assign({}, BooklyL10n.datatables.l10n, {zeroRecords: BooklyL10n.zeroRecords})
        }),
        saveSettings: function(settings) {
            $.post(ajaxurl, Object.assign({
                action: 'bookly_update_table_settings',
                table: cabinetTable,
                csrf_token: BooklyL10nGlobal.csrf_token
            }, settings));
        },
        emptyState: {
            icon: 'key-round',
            title: BooklyL10n.empty_title,
            text: BooklyL10n.empty_text,
            actions: ['bookly-js-new-key']
        },
        topToolbar: [{
            id: 'bookly-js-new-key',
            label: BooklyL10n.new_token,
            icon: 'plus',
            click: function() {
                BooklyGrantAuthDialog.showDialog({
                    id: null,
                    token: null,
                    staff_id: null,
                    wp_user_id: null
                }, function() {
                    bt.reload();
                });
            }
        }],
        edit: function(row) {
            BooklyGrantAuthDialog.showDialog({
                id: row.id,
                token: row.token,
                staff_id: row.staff_id || null,
                wp_user_id: row.wp_user_id || null,
                name: row.full_name
            }, function() {
                bt.reload();
            });
        },
        rowActions: function(row) {
            return [{
                label: BooklyL10n.copy_link,
                icon: 'copy',
                variant: 'outline',
                click: function(r) {
                    booklyCopyTextToClipboard(app_auth_url + (r.token || ''));
                }
            }];
        },
        checked: function(rows) {
            const actions = [];
            actions.push({
                label: BooklyL10n.revoke,
                icon: 'trash',
                variant: 'destructive',
                click: function(selected) {
                    booklyModal(BooklyL10n.areYouSure, BooklyL10n.revokeTokensMessage, BooklyL10n.cancel, BooklyL10n.revoke_confirm)
                        .on('bs.click.main.button', function(event, modal, mainButton) {
                            let ladda = Ladda.create(mainButton);
                            ladda.start();
                            let tokens = selected.map(function(r) { return r.token; });
                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'bookly_cloud_mobile_staff_cabinet_revoke_access_tokens',
                                    csrf_token: BooklyL10nGlobal.csrf_token,
                                    keys: tokens
                                },
                                dataType: 'json',
                                success: function(response) {
                                    ladda.stop();
                                    if (response.success) {
                                        BooklyGrantAuthDialog.setStaffMembers(response.data.staff_members);
                                        bt.reload();
                                        modal.booklyModal('hide');
                                    } else {
                                        booklyAlert({error: [response.data.message]});
                                    }
                                }
                            });
                        });
                }
            });
            return actions;
        }
    });

    function booklyCopyTextToClipboard(text) {
        const done = () => booklyAlert({success: [BooklyL10n.copied]});
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(() => {});
        } else {
            // Fallback for insecure origins (HTTP) where navigator.clipboard is undefined.
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.cssText = 'position:fixed;opacity:0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            done();
        }
    }
});
