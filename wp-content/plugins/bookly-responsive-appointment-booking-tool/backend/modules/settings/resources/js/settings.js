jQuery(function ($) {
    let container = {
            $calendar: $('#bookly_settings_calendar'),
        },
        $helpBtn = $('#bookly-help-btn'),
        $businessHours = $('#business-hours'),
        $companyLogo = $('#bookly-js-company-logo'),
        $finalStepUrl = $('.bookly-js-final-step-url'),
        $finalStepUrlMode = $('#bookly_settings_final_step_url_mode'),
        $participants = $('#bookly_appointment_participants'),
        $defaultCountry = $('#bookly_cst_phone_default_country'),
        $defaultCountryCode = $('#bookly_cst_default_country_code'),
        $gcSyncMode = $('#bookly_gc_sync_mode'),
        $gcLimitEvents = $('#bookly_gc_limit_events'),
        $gcFullSyncOffset = $('#bookly_gc_full_sync_offset_days_before'),
        $gcFullSyncTitles = $('#bookly_gc_full_sync_titles'),
        $gcForceUpdateDescription = $('#bookly_gc_force_update_description'),
        $ocSyncMode = $('#bookly_oc_sync_mode'),
        $ocLimitEvents = $('#bookly_oc_limit_events'),
        $ocFullSyncOffset = $('#bookly_oc_full_sync_offset_days_before'),
        $ocFullSyncTitles = $('#bookly_oc_full_sync_titles'),
        $currency = $('#bookly_pmt_currency'),
        $formats = $('#bookly_pmt_price_format'),
        $calOneParticipant = $('[name="bookly_cal_one_participant"]'),
        $calManyParticipants = $('[name="bookly_cal_many_participants"]'),
        $woocommerceInfo = $('[name="bookly_l10n_wc_cart_info_value"]'),
        $customerAddress = $('[name="bookly_l10n_cst_address_template"]'),
        $gcDescription = $('[name="bookly_gc_event_description"]'),
        $ocDescription = $('[name="bookly_oc_event_description"]'),
        $acSyncMode = $('#bookly_ac_sync_mode'),
        $acLimitEvents = $('#bookly_ac_limit_events'),
        $acFullSyncOffset = $('#bookly_ac_full_sync_offset_days_before'),
        $acFullSyncTitles = $('#bookly_ac_full_sync_titles'),
        $acDescription = $('[name="bookly_ac_event_description"]'),
        $colorPicker = $('.bookly-js-color-picker', container.$calendar),
        $coloringMode = $('#bookly_cal_coloring_mode', container.$calendar),
        $colorsBy = $('.bookly-js-colors-by', container.$calendar),
        $cloudStripeCustomMetadata = $('#bookly_cloud_stripe_custom_metadata'),
        $cloudStripeMetadata = $('#bookly-cloud-stripe-metadata'),
        $icsCustomer = $('[name="bookly_l10n_ics_customer_template"]'),
        $icsStaff = $('[name="bookly_ics_staff_template"]')
    ;

    booklyAlert(BooklyL10n.alert);

    Ladda.bind('button[type=submit]', {timeout: 2000});

    // Customers tab.
    $.each(window.booklyIntlTelInput.getCountryData(), function (index, value) {
        $defaultCountry.append('<option value="' + value.iso2 + '" data-code="' + value.dialCode + '">' + value.name + ' +' + value.dialCode + '</option>');
    });
    $defaultCountry.val(BooklyL10n.default_country);
    $defaultCountry.on('change', function () {
        $defaultCountryCode.val($defaultCountry.find('option:selected').data('code'));
    });
    $('.bookly-js-drag-container').each(function () {
        Sortable.create(this, {
            handle: '.bookly-js-draghandle'
        });
    });

    $('#bookly-customer-reset').on('click', function (event) {
        $defaultCountry.val($defaultCountry.data('country'));
    });

    $icsCustomer.data('default', $icsCustomer.val());
    let icsCustomerEditor = $('#bookly-ics-customer-editor').booklyAceEditor();
    icsCustomerEditor.booklyAceEditor('onChange', function () {
        $icsCustomer.val(icsCustomerEditor.booklyAceEditor('getValue'));
    });

    $icsStaff.data('default', $icsStaff.val());
    let icsStaffEditor = $('#bookly-ics-staff-editor').booklyAceEditor();
    icsStaffEditor.booklyAceEditor('onChange', function () {
        $icsStaff.val(icsStaffEditor.booklyAceEditor('getValue'));
    });

    $customerAddress.data('default', $customerAddress.val());
    let customerAddressEditor = $('#bookly-settings-customers-editor').booklyAceEditor();
    customerAddressEditor.booklyAceEditor('onChange', function () {
        $customerAddress.val(customerAddressEditor.booklyAceEditor('getValue'));
    });

    $('#bookly_settings_customers button[type="reset"]').on('click', function () {
        customerAddressEditor.booklyAceEditor('setValue', $customerAddress.data('default'));
    });

    // Google Calendar tab.
    $gcSyncMode.on('change', function () {
        $gcLimitEvents.closest('.form-group').toggle(this.value == '1.5-way');
        $gcFullSyncOffset.closest('.form-group').toggle(this.value == '2-way');
        $gcFullSyncTitles.closest('.form-group').toggle(this.value == '2-way');
        $gcForceUpdateDescription.closest('.form-group').toggle(this.value == '2-way');
    }).trigger('change');

    $gcDescription.data('default', $gcDescription.val());
    let gcDescriptionEditor = $('#bookly_gc_event_description').booklyAceEditor();
    gcDescriptionEditor.booklyAceEditor('onChange', function () {
        $gcDescription.val(gcDescriptionEditor.booklyAceEditor('getValue'));
    });

    $('#bookly_settings_google_calendar button[type="reset"]').on('click', function () {
        gcDescriptionEditor.booklyAceEditor('setValue', $gcDescription.data('default'));
    });

    // Outlook Calendar tab.
    $ocSyncMode.on('change', function () {
        $ocLimitEvents.closest('.form-group').toggle(this.value == '1.5-way');
        $ocFullSyncOffset.closest('.form-group').toggle(this.value == '2-way');
        $ocFullSyncTitles.closest('.form-group').toggle(this.value == '2-way');
    }).trigger('change');

    $ocDescription.data('default', $ocDescription.val());
    let ocDescriptionEditor = $('#bookly_oc_event_description').booklyAceEditor();
    ocDescriptionEditor.booklyAceEditor('onChange', function () {
        $ocDescription.val(ocDescriptionEditor.booklyAceEditor('getValue'));
    });

    $('#bookly_settings_outlook_calendar button[type="reset"]').on('click', function () {
        ocDescriptionEditor.booklyAceEditor('setValue', $ocDescription.data('default'));
    });

    // Apple Calendar tab.
    if ($acSyncMode.length) {
        $acSyncMode.on('change', function () {
            $acLimitEvents.closest('.form-group').toggle(this.value == '1.5-way');
            $acFullSyncOffset.closest('.form-group').toggle(this.value == '2-way');
            $acFullSyncTitles.closest('.form-group').toggle(this.value == '2-way');
        }).trigger('change');

        $acDescription.data('default', $acDescription.val());
        let acDescriptionEditor = $('#bookly_ac_event_description').booklyAceEditor();
        acDescriptionEditor.booklyAceEditor('onChange', function () {
            $acDescription.val(acDescriptionEditor.booklyAceEditor('getValue'));
        });

        $('#bookly_settings_apple_calendar button[type="reset"]').on('click', function () {
            acDescriptionEditor.booklyAceEditor('setValue', $acDescription.data('default'));
        });
    }

    // Calendar tab.
    $participants.on('change', function () {
        $('#bookly_cal_one_participant').hide();
        $('#bookly_cal_many_participants').hide();
        $('#' + this.value).show();
    }).trigger('change');
    $('#bookly_settings_calendar button[type=reset]').on('click', function () {
        setTimeout(function () {
            $participants.trigger('change');
        }, 50);
    });

    $calOneParticipant.data('default', $calOneParticipant.val());
    $calManyParticipants.data('default', $calManyParticipants.val());
    let calendarEditorOneParticipant = $('#bookly_cal_editor_one_participant').booklyAceEditor();
    calendarEditorOneParticipant.booklyAceEditor('onChange', function () {
        $calOneParticipant.val(calendarEditorOneParticipant.booklyAceEditor('getValue'));
    });

    let calendarEditorManyParticipants = $('#bookly_cal_editor_many_participants').booklyAceEditor();
    calendarEditorManyParticipants.booklyAceEditor('onChange', function () {
        $calManyParticipants.val(calendarEditorManyParticipants.booklyAceEditor('getValue'));
    });

    $('#bookly_settings_calendar button[type="reset"]').on('click', function () {
        calendarEditorOneParticipant.booklyAceEditor('setValue', $calOneParticipant.data('default'));
        calendarEditorManyParticipants.booklyAceEditor('setValue', $calManyParticipants.data('default'));
    });

    // Woocommerce tab.
    $woocommerceInfo.data('default', $woocommerceInfo.val());
    let woocommerceEditor = $('#bookly_wc_cart_info').booklyAceEditor();
    woocommerceEditor.booklyAceEditor('onChange', function () {
        $woocommerceInfo.val(woocommerceEditor.booklyAceEditor('getValue'));
    });

    $('#bookly_settings_woo_commerce button[type="reset"]').on('click', function () {
        woocommerceEditor.booklyAceEditor('setValue', $woocommerceInfo.data('default'));
    });

    // Company tab.
    $companyLogo.find('.bookly-js-delete').on('click', function () {
        let $thumb = $companyLogo.find('.bookly-js-image');
        $thumb.attr('style', '');
        $companyLogo.find('[name=bookly_co_logo_attachment_id]').val('');
        $companyLogo.find('.bookly-thumb').removeClass('bookly-thumb-with-image');
        $(this).hide();
    });
    $companyLogo.find('.bookly-js-edit').on('click', function () {
        let frame = wp.media({
            library: {type: 'image'},
            multiple: false
        });
        frame.on('select', function () {
            let selection = frame.state().get('selection').toJSON(),
                img_src
            ;
            if (selection.length) {
                if (selection[0].sizes['thumbnail'] !== undefined) {
                    img_src = selection[0].sizes['thumbnail'].url;
                } else {
                    img_src = selection[0].url;
                }
                $companyLogo.find('[name=bookly_co_logo_attachment_id]').val(selection[0].id);
                $companyLogo.find('.bookly-js-image').css({
                    'background-image': 'url(' + img_src + ')',
                    'background-size': 'cover'
                });
                $companyLogo.find('.bookly-js-delete').show();
                $companyLogo.find('.bookly-thumb').addClass('bookly-thumb-with-image');
                $(this).hide();
            }
        });

        frame.open();
    });
    $('#bookly-company-reset').on('click', function () {
        var $div = $('#bookly-js-company-logo .bookly-js-image'),
            $input = $('[name=bookly_co_logo_attachment_id]');
        $div.attr('style', $div.data('style'));
        $input.val($input.data('default'));
    });

    // Payments tab.
    Sortable.create($('#bookly-payment-systems')[0], {
        handle: '.bookly-js-draghandle',
        onChange: function () {
            let order = [];
            $('#bookly_settings_payments .card[data-gateway]').each(function () {
                order.push($(this).data('gateway'));
            });
            $('#bookly_settings_payments [name="bookly_pmt_order"]').val(order.join(','));
        },
    });
    $currency.on('change', function () {
        $formats.find('option').each(function () {
            var decimals = this.value.match(/{price\|(\d)}/)[1],
                price = BooklyL10n.sample_price
            ;
            if (decimals < 3) {
                price = price.slice(0, -(decimals == 0 ? 4 : 3 - decimals));
            }
            var html = this.value
                .replace('{sign}', '')
                .replace('{symbol}', $currency.find('option:selected').data('symbol'))
                .replace(/{price\|\d}/, price)
            ;
            html += ' (' + this.value
                .replace('{sign}', '-')
                .replace('{symbol}', $currency.find('option:selected').data('symbol'))
                .replace(/{price\|\d}/, price) + ')'
            ;
            this.innerHTML = html;
        });
    }).trigger('change');

    $('#bookly_paypal_enabled').change(function () {
        $('.bookly-paypal-express-checkout').toggle(this.value == 'ec');
        $('.bookly-paypal-ps').toggle(this.value == 'ps');
        $('.bookly-paypal-checkout').toggle(this.value == 'checkout');
        $('.bookly-paypal').toggle(this.value != '0');
    }).change();

    $('#bookly-payments-reset').on('click', function (event) {
        setTimeout(function () {
            $('#bookly_pmt_currency,#bookly_paypal_enabled,#bookly_authorize_net_enabled,#bookly_stripe_enabled,#bookly_2checkout_enabled,#bookly_payu_biz_enabled,#bookly_payu_latam_enabled,#bookly_payson_enabled,#bookly_mollie_enabled,#bookly_payu_biz_sandbox,#bookly_payu_latam_sandbox,#bookly_cloud_stripe_enabled').change();
            $('#bookly-cloud-stripe-metadata').html('');
            $.each(BooklyL10n.stripeCloudMetadata, function (index, meta) {
                addCloudStripeMetadata(meta.name, meta.value);
            })
            $cloudStripeCustomMetadata.change();
        }, 0);
    });

    $('#bookly-cloud-stripe-add-metadata').on('click', function () {
        addCloudStripeMetadata('', '');
    });

    $.each(BooklyL10n.stripeCloudMetadata, function (index, meta) {
        addCloudStripeMetadata(meta.name, meta.value);
    })

    $cloudStripeMetadata.on('click', '.bookly-js-delete-metadata', function () {
        $(this).closest('.bookly-js-metadata-row').remove();
    });

    function addCloudStripeMetadata(name, value) {
        if ($cloudStripeMetadata.length > 0) {
            $cloudStripeMetadata.append(
                $('#bookly-stripe-metadata-template').clone()
                    .find('.bookly-js-meta-name').attr('name', 'bookly_cloud_stripe_meta_name[]').end()
                    .find('.bookly-js-meta-value').attr('name', 'bookly_cloud_stripe_meta_value[]').end()
                    .show().html()
                    .replace(/{{name}}/g, name)
                    .replace(/{{value}}/g, value)
            );
        }
    }

    // URL tab.
    if ($finalStepUrl.find('input').val()) {
        $finalStepUrlMode.val(1);
    }
    $finalStepUrlMode.change(function () {
        if (this.value == 0) {
            $finalStepUrl.hide().find('input').val('');
        } else {
            $finalStepUrl.show();
        }
    });

    // Holidays Tab.
    var d = new Date();
    $('.bookly-js-annual-calendar').jCal({
        day: new Date(d.getFullYear(), 0, 1),
        days: 1,
        showMonths: 12,
        scrollSpeed: 350,
        events: BooklyL10n.holidays,
        action: 'bookly_settings_holiday',
        csrf_token: BooklyL10nGlobal.csrf_token,
        dayOffset: parseInt(BooklyL10n.firstDay),
        loadingImg: BooklyL10n.loading_img,
        dow: BooklyL10n.days,
        ml: BooklyL10n.months,
        we_are_not_working: BooklyL10n.we_are_not_working,
        excluded_from_repeat: BooklyL10n.excluded_from_repeat,
        repeat: BooklyL10n.repeat,
        close: BooklyL10n.close
    });
    $('.bookly-js-jCalBtn').on('click', function (e) {
        e.preventDefault();
        var trigger = $(this).data('trigger');
        $('.bookly-js-annual-calendar').find($(trigger)).trigger('click');
    });

    // Business Hours tab.
    $('.bookly-js-parent-range-start', $businessHours)
        .on('change', function () {
            var $parentRangeStart = $(this),
                $rangeRow = $parentRangeStart.parents('.bookly-js-range-row');
            if ($parentRangeStart.val() == '') {
                $('.bookly-js-invisible-on-off', $rangeRow).addClass('invisible');
            } else {
                $('.bookly-js-invisible-on-off', $rangeRow).removeClass('invisible');
                rangeTools.hideInaccessibleEndTime($parentRangeStart, $('.bookly-js-parent-range-end', $rangeRow));
            }
        }).trigger('change');
    // Reset.
    $('#bookly-hours-reset', $businessHours).on('click', function () {
        $('.bookly-js-parent-range-start', $businessHours).each(function () {
            $(this).val($(this).data('default_value')).trigger('change');
        });
    });

    // Point the header's Documentation link at the activated settings tab. The link lives in the
    // Svelte PageHeader (Help dropdown) now, so we hand it the tab via an event and it builds the URL.
    $('#bookly-sidebar a[data-toggle="bookly-pill"]').on('shown.bs.tab', function (e) {
        var tab = e.target.getAttribute('href').replace('#bookly_settings_', '');
        window.dispatchEvent(new CustomEvent('bookly:settings-doc-tab', { detail: tab }));
    });

    // Tab calendar
    $coloringMode
        .on('change', function () {
            $colorsBy.hide();
            $('.bookly-js-colors-' + this.value).show()
        }).trigger('change');

    initColorPicker($colorPicker);

    function initColorPicker($jquery_collection) {
        $jquery_collection.wpColorPicker();
        $jquery_collection.each(function () {
            $(this).data('last-color', $(this).val());
            $('.wp-color-result-text', $(this).closest('.bookly-color-picker')).text($(this).data('title'));
        });
    }

    $('#bookly-calendar-reset', container.$calendar)
        .on('click', function (event) {
            $colorPicker.each(function () {
                $(this).wpColorPicker('color', $(this).data('last-color'));
            });
            setTimeout(function () {
                $coloringMode.trigger('change')
            }, 0);
        });

    $('[data-expand]').on('change', function () {
        let selector = '.' + this.id + '-expander';
        this.value == $(this).data('expand')
            ? $(selector).show()
            : $(selector).hide();
    }).trigger('change');

    // Activate tab.
    $('a[href="#bookly_settings_' + BooklyL10n.current_tab + '"]').click();

    // Deep-link highlight from the global ⌘K search (?highlight=<id>). The id is either a setting
    // input id (label result) or a gateway/provider collapse id. Scroll the matching row/card into
    // view and flash it; if it sits inside a collapsed payment/provider panel, expand it first.
    var highlightId = new URLSearchParams(window.location.search).get('highlight');
    if (highlightId) {
        // Consume the parameter so a reload or a copied URL doesn't re-flash the setting.
        var cleanParams = new URLSearchParams(window.location.search);
        cleanParams.delete('highlight');
        window.history.replaceState(null, '', window.location.pathname + '?' + cleanParams.toString() + window.location.hash);
        var target = document.getElementById(highlightId);
        if (target) {
            var collapse = target.classList.contains('bookly-collapse') ? target : target.closest('.bookly-collapse');
            if (collapse && !collapse.classList.contains('bookly-show')) {
                var toggle = document.querySelector('[data-toggle="bookly-collapse"][href="#' + collapse.id + '"]');
                toggle ? toggle.click() : $(collapse).booklyCollapse('show');
            }
            var box = target.closest('.form-group') || target.closest('.card') || target;
            // The element to flash: the field's <label>, or the gateway/provider panel title.
            var flash = document.querySelector('label[for="' + highlightId + '"]')
                || document.querySelector('[data-toggle="bookly-collapse"][href="#' + highlightId + '"]')
                || box;
            // Defer so the activated tab pane and any expand animation have laid out.
            setTimeout(function () {
                box.scrollIntoView({ behavior: 'smooth', block: 'center' });
                // Match the datatables search highlight: yellow-200 background, rounded, small inset.
                flash.style.transition = 'opacity .35s ease';
                flash.style.backgroundColor = '#fef08a';
                flash.style.borderRadius = '2px';
                flash.style.padding = '0 2px';
                var visible = true, ticks = 0;
                var blink = setInterval(function () {
                    visible = !visible;
                    flash.style.opacity = visible ? '1' : '.45';
                    if (++ticks >= 4) {
                        clearInterval(blink);
                        // Settle: keep the highlight so the matched setting stays easy to spot.
                        flash.style.opacity = '1';
                    }
                }, 360);
            }, 150);
        }
    }

    // (The legacy in-page settings search was removed — replaced by the global ⌘K command palette
    //  in the page header, which searches all admin pages and add-on features.)
});