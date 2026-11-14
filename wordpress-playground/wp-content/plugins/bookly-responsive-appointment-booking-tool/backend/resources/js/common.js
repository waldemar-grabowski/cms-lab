function booklyAlert(alert) {
    // type => [legacy hook class (kept for Selenium and old callers), icon]
    const types = {
        success: ['alert-success', '<svg class="bookly:toast-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>'],
        error: ['alert-danger', '<svg class="bookly:toast-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>'],
        warning: ['alert-warning', '<svg class="bookly:toast-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>'],
        info: ['alert-info', '<svg class="bookly:toast-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>'],
    };
    // Check if there are messages in alert.
    let not_empty = false;
    for (let type in alert) {
        if (types.hasOwnProperty(type) && alert[type].length) {
            not_empty = true;
            break;
        }
    }

    if (not_empty) {
        let $container = jQuery('#bookly-alert');
        if ($container.length === 0) {
            $container = jQuery('<div id="bookly-alert" class="bookly-css-root bookly-toast-container"></div>').appendTo(document.body);
        }
        for (let type in alert) {
            if (!types.hasOwnProperty(type)) {
                continue;
            }
            alert[type].forEach(function (message) {
                const $toast = jQuery('<div class="bookly:toast alert ' + types[type][0] + '"></div>')
                    .append(types[type][1])
                    .append(jQuery('<b class="bookly:toast-message"></b>').html(message))
                    .append(
                        jQuery('<button type="button" class="bookly:toast-close" aria-label="Close"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>')
                            .on('click', function () {
                                $toast.remove();
                            })
                    )
                    .appendTo($container);
                if (type !== 'error') {
                    setTimeout(function () {
                        $toast.remove();
                    }, 10000);
                }
            });
        }
    }
}

function booklyModal(title, text, closeCaption, mainActionCaption) {
    const closeIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';
    let $mainButton = '',
        $modal = jQuery('<div>', {class: 'bookly-css-root bookly:modal-overlay', tabindex: -1});

    function onKeydown(e) {
        if (e.key === 'Escape') {
            hide();
        }
    }

    function hide() {
        $modal.trigger('hide.bs.modal');
        document.removeEventListener('keydown', onKeydown);
        $modal.remove();
    }

    // Compatibility shim: builder consumers call modal.booklyModal('hide') on the
    // returned instance — keep that working without the legacy bootstrap plugin.
    $modal.booklyModal = function (action) {
        if (action === 'hide') {
            hide();
        }
        return this;
    };

    if (mainActionCaption) {
        // ladda-button + .ladda-label kept: consumers run Ladda.create(mainButton).
        $mainButton = jQuery('<button>', {
            class: 'bookly:alert-btn bookly:alert-btn-primary ladda-button',
            type: 'button',
            title: mainActionCaption,
            'data-spinner-size': 40,
            'data-style': 'zoom-in'
        })
            .append(jQuery('<span>', {class: 'ladda-label'}).text(mainActionCaption));
        $mainButton.on('click', function (e) {
            e.stopPropagation();
            $modal.trigger('bs.click.main.button', [$modal, $mainButton.get(0)]);
        });
    }

    $modal
        .append(
            jQuery('<div>', {class: 'bookly:modal-box', role: 'dialog'})
                .append(
                    jQuery('<div>', {class: 'bookly:modal-header'})
                        .append(jQuery('<div>', {class: 'bookly:modal-title', html: title}))
                        .append(
                            jQuery('<button>', {class: 'bookly:alert-close', type: 'button', 'aria-label': 'Close', html: closeIcon})
                                .on('click', hide)
                        )
                )
                .append(
                    text ? jQuery('<div>', {class: 'bookly:modal-body', html: text}) : ''
                )
                .append(
                    jQuery('<div>', {class: 'bookly:modal-footer'})
                        .append($mainButton)
                        .append(
                            jQuery('<button>', {class: 'bookly:alert-btn bookly:alert-btn-outline', type: 'button'})
                                .text(closeCaption)
                                .on('click', hide)
                        )
                )
        )
        .on('click', function (e) {
            if (e.target === this) {
                hide();
            }
        });

    setTimeout(function () {
        jQuery(document.body).append($modal);
        document.addEventListener('keydown', onKeydown);
        $modal.trigger('show.bs.modal');
    }, 0);

    return $modal;
}

function getBooklyModalContainer(id) {
    if (!document.getElementsByClassName('bookly-modals-container').length) {
        let modalsContainer = document.createElement('div');
        modalsContainer.setAttribute('id', 'bookly-tbs');
        modalsContainer.className = 'bookly-modals-container';
        document.body.appendChild(modalsContainer);
    }
    if (!document.getElementById(id)) {
        let container = document.createElement('div');
        container.setAttribute('id', id);
        document.getElementsByClassName('bookly-modals-container')[0].appendChild(container);
    }

    return document.getElementById(id);
}

function requiredBooklyPro() {
    jQuery.ajax({
        url: ajaxurl,
        type: 'POST',
        data: {
            action: 'bookly_required_bookly_pro',
            csrf_token: BooklyL10nGlobal.csrf_token
        },
        success: function (response) {
            if (response.success) {
                const checkIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>';
                let $features = jQuery('<div>', {class: 'bookly:grid bookly:gap-1.5 bookly:mt-4'}),
                    $content = jQuery('<div>')
                ;
                response.data.features.forEach(function (feature) {
                    $features.append(
                        jQuery('<div>', {class: 'bookly:flex bookly:items-start bookly:gap-2'})
                            .append(jQuery('<span>', {class: 'bookly:shrink-0 bookly:mt-0.5 bookly:text-primary', html: checkIcon}))
                            .append(jQuery('<div>', {html: feature}))
                    );
                });

                $content
                    .append(jQuery('<div>', {class: 'bookly:-mx-4 bookly:-mt-4'})
                        .append(
                            jQuery('<img/>', {src: response.data.image, alt: 'Bookly Pro', class: 'bookly:w-full bookly:rounded-t-xl'})
                        )
                    )
                    .append(jQuery('<div>', {class: 'bookly:text-lg bookly:font-semibold bookly:text-center bookly:mt-4', html: response.data.caption}))
                    .append(jQuery('<div>', {class: 'bookly:text-center bookly:mt-2', html: response.data.body}))
                    .append($features);

                booklyModal('', $content, response.data.close, response.data.upgrade)
                    .on('bs.click.main.button', function (event, modal, mainButton) {
                        Ladda.create(mainButton).start();
                        window.location.href = 'https://www.booking-wp-plugin.com/pricing';
                        modal.booklyModal('hide');
                    })
                    .on('show.bs.modal', function () {
                        jQuery('.bookly\\:modal-header', jQuery(this)).remove();
                    });
            }
        },
    });
}

/**
 * Record a usage event, see Bookly\Lib\UsageStats.
 * Sent as a beacon, so it survives a click that leaves the page.
 *
 * @param {string} name
 * @param {Object} [props]
 */
function booklyTrackUsage(name, props) {
    const data = new FormData();
    data.append('action', 'bookly_track_usage');
    data.append('csrf_token', BooklyL10nGlobal.csrf_token);
    data.append('name', name);
    for (const key in props || {}) {
        if (props[key] !== null && props[key] !== undefined) {
            data.append('props[' + key + ']', props[key]);
        }
    }
    const url = BooklyL10nGlobal.ajax_url_backend;
    if (!(navigator.sendBeacon && navigator.sendBeacon(url, data))) {
        fetch(url, {method: 'POST', body: data, keepalive: true, credentials: 'same-origin'}).catch(function () {});
    }
}

(function ($) {

    window.booklySerialize = {
        form: function ($form) {
            let data = {},
                serialized = $form.serializeArray();
            $('input[type=radio]:not(:checked)', $form).each(function () {
                if (this.name) {
                    let find = false,
                        that = this;
                    serialized.forEach(function (item) {
                        if (!find && item.name === that.name) {
                            find = true;
                        }
                    });
                    if (!find) {
                        serialized.push({name: this.name, value: null});
                    }
                }
            });
            $('input[type=checkbox]:not(:checked)', $form).each(function () {
                if (this.name) {
                    serialized.push({name: this.name, value: null});
                }
            });
            $.map(serialized, function (n) {
                const keys = n.name.match(/[a-zA-Z0-9_-]+|(?=\[\])/g);
                if (keys.length > 1) {
                    let tmp = data, key = keys.pop();
                    for (let i = 0; i < keys.length, j = keys[i]; i++) {
                        tmp[j] = (!tmp[j]
                                ? (key == '' && i == keys.length - 1) ? [] : {}
                                : tmp[j]
                        );
                        tmp = tmp[j];
                    }
                    if (n.value !== null) {
                        if (Array.isArray(tmp)) {
                            tmp.push(n.value);
                        } else {
                            tmp[key] = n.value;
                        }
                    }
                } else data[keys.pop()] = n.value;
            });

            return data;
        },
        buildRequestDataFromForm: function (action, $form) {
            return this.buildRequestData(action, this.form($form));
        },
        buildRequestData: function (action, data) {
            return {
                action: action,
                csrf_token: BooklyL10nGlobal.csrf_token,
                json_data: JSON.stringify(data),
            }
        }
    }

    window.booklyRequest = {
        objectToQueryString: function (initialObj) {
            const reducer = (obj, parentPrefix = null) => (prev, key) => {
                const val = obj[key];
                key = encodeURIComponent(key);
                const prefix = parentPrefix ? `${parentPrefix}[${key}]` : key;

                if (val == null || typeof val === 'function') {
                    prev.push(`${prefix}=`);
                    return prev;
                }

                if (['number', 'boolean', 'string'].includes(typeof val)) {
                    prev.push(`${prefix}=${encodeURIComponent(val)}`);
                    return prev;
                }

                prev.push(Object.keys(val).reduce(reducer(val, prefix), []).join('&'));
                return prev;
            };

            return Object.keys(initialObj).reduce(reducer(initialObj), []).join('&');
        },
        /**
         * @param data
         * @returns {Promise<unknown>}
         */
        send: function (data) {
            return new Promise((resolve, reject) => {
                fetch(ajaxurl, {
                    method: 'POST',
                    body: this.objectToQueryString(data),
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    }
                })
                    .then(response => {
                        if (!response.ok) {
                            return response.json().then(err => {
                                throw new Error(err.message || 'Something went wrong');
                            });
                        }

                        return response.json();
                    })
                    .then(data => resolve(data))
                    .catch(error => {
                        console.log('Invalid response for ' + (data?.action || 'request') + '. Reason ' + error.message);
                        reject(error);
                    });
            });
        }
    }
})(jQuery);

(function () {
    // Deep-link action from the global search palette: ?bookly-action=<button-id> clicks that
    // button once it appears (datatable toolbars are mounted asynchronously). The parameter is
    // consumed immediately — the address bar is cleaned up so a reload doesn't repeat the action.
    let params = new URLSearchParams(window.location.search);
    let actionId = params.get('bookly-action');
    if (!actionId) {
        return;
    }
    params.delete('bookly-action');
    window.history.replaceState(null, '', window.location.pathname + '?' + params.toString() + window.location.hash);
    let deadline = Date.now() + 10000;
    (function attempt() {
        let el = document.getElementById(actionId);
        if (el) {
            el.click();
        } else if (Date.now() < deadline) {
            setTimeout(attempt, 200);
        }
    })();
})();