(function ($) {

    let calendar;

    let Calendar = function ($container, options) {
        let obj = this;
        jQuery.extend(obj.options, options);

        // Special locale for moment
        moment.locale('bookly', {
            months: obj.options.l10n.datePicker.monthNames,
            monthsShort: obj.options.l10n.datePicker.monthNamesShort,
            weekdays: obj.options.l10n.datePicker.dayNames,
            weekdaysShort: obj.options.l10n.datePicker.dayNamesShort,
            meridiem: function (hours, minutes, isLower) {
                return hours < 12
                    ? obj.options.l10n.datePicker.meridiem[isLower ? 'am' : 'AM']
                    : obj.options.l10n.datePicker.meridiem[isLower ? 'pm' : 'PM'];
            },
        });

        let allowEditAppointment = typeof BooklyAppointmentDialog !== 'undefined' && (!options.hasOwnProperty('disable_editor') || !options.disable_editor);

        let viewTypes = {
            dayGridMonth: 'resourceTimelineMonth',
            timeGridWeek: 'resourceTimelineWeek',
            resourceTimeGridDay: 'resourceTimelineDay'
        }
        // Event Popover
        let $popover = $();
        let showPopover = 0;
        let popoverEventEl;

        function animate(fn) {
            window.requestAnimationFrame(fn);
        }

        function popoverShow() {
            ++showPopover;
            $popover.css('opacity', 1);
        }

        function popoverRemove() {
            $popover.remove();
            $popover.length = 0;
            popoverEventEl = null;
            showPopover = 0;
        }

        // An event redrawn under the pointer is replaced without a mouseleave, so the
        // count never drops and the popover would stay for good: drop it once its event
        // is gone. Checked a frame later, when the calendar has redrawn its events.
        function popoverDropOrphan() {
            animate(function () {
                if (popoverEventEl && !popoverEventEl.isConnected) {
                    popoverRemove();
                }
            });
        }

        function popoverClose() {
            if (showPopover) {
                --showPopover;
                let start;

                function fade(time) {
                    if (showPopover) {
                        return;
                    }
                    if (!start) {
                        start = time;
                        return animate(fade);
                    }
                    let progress = time - start;
                    if (progress < 100) {
                        animate(fade);
                    } else if (progress >= 100 && progress <= 300) {
                        $popover.css('opacity', 1 - (progress - 100) / 200);
                        animate(fade);
                    } else {
                        start = null;
                        popoverRemove();
                    }
                }

                animate(fade);
            }
        }

        // Settings for Event Calendar
        let settings = {
            view: 'timeGridWeek',
            customButtons: {
                timeline: {
                    active: true,
                    text: {html: '<i class="far fa-fw fa-check-square"></i> ' + obj.options.l10n.timeline},
                    click: function (e) {
                        let headerToolbar = calendar.getOption('headerToolbar'),
                            view = calendar.getOption('view');
                        headerToolbar.end = 'dayGridMonth,timeGridWeek,resourceTimeGridDay,grid listWeek'
                        calendar.setOption('headerToolbar', headerToolbar);
                        let _viewTypes = Object.values(viewTypes);
                        if (_viewTypes.includes(view)) {
                            // Change view
                            Object.keys(viewTypes).forEach(function (key) {
                                if (viewTypes[key] === view) {
                                    calendar.setOption('view', key);
                                }
                            });
                        } else if (view === 'listWeek') {
                            setCookie('bookly_cal_view', 'listWeek');
                        }
                    }
                },
                grid: {
                    text: {html: '<i class="far fa-fw fa-square"></i> ' + obj.options.l10n.timeline},
                    click: function () {
                        let headerToolbar = calendar.getOption('headerToolbar'),
                            view = calendar.getOption('view');
                        headerToolbar.end = 'resourceTimelineMonth,resourceTimelineWeek,resourceTimelineDay,timeline listWeek'
                        calendar.setOption('headerToolbar', headerToolbar);
                        if (viewTypes.hasOwnProperty(view)) {
                            // Change view
                            calendar.setOption('view', viewTypes[view]);
                        } else if (view === 'listWeek') {
                            setCookie('bookly_cal_view', 'listWeekTimeline');
                        }
                    }
                },
            },
            views: {
                dayGridMonth: {
                    dayHeaderFormat: function (date) {
                        return moment(date).locale('bookly').format('ddd');
                    },
                    displayEventEnd: true,
                    dayMaxEvents: obj.options.l10n.monthDayMaxEvents === '1',
                    theme: function (theme) {
                        if (obj.options.l10n.monthDayMaxEvents === '1') {
                            theme.view += ' ec-minimalistic';
                        }
                        return theme;
                    }
                },
                timeGridDay: {
                    dayHeaderFormat: function (date) {
                        return moment(date).locale('bookly').format('dddd');
                    },
                    pointer: true,
                    columnWidth: obj.options.l10n.calendar_version === 'latest' ? 'minmax(120px, 1fr)' : undefined
                },
                timeGridWeek: {pointer: true},
                resourceTimeGridDay: {
                    pointer: true,
                    columnWidth: obj.options.l10n.calendar_version === 'latest' ? 'minmax(' + obj.options.l10n.clmn_min_width + 'px, 1fr)' : undefined
                },
                resourceTimelineDay: {
                    pointer: true,
                    displayEventEnd: true,
                    slotWidth: obj.options.l10n.calendar_version === 'latest' ? 120 : 180
                },
                resourceTimelineWeek: {
                    pointer: true,
                    displayEventEnd: true,
                    slotWidth: obj.options.l10n.calendar_version === 'latest' ? 120 : 180
                },
                resourceTimelineMonth: {
                    pointer: true,
                    displayEventEnd: true,
                    columnWidth: obj.options.l10n.calendar_version === 'latest' ? 'minmax(120px, 1fr)' : undefined
                }
            },
            nowIndicator: true,
            hiddenDays: obj.options.l10n.hiddenDays,
            slotDuration: obj.options.l10n.slotDuration,
            slotMinTime: obj.options.l10n.slotMinTime,
            slotMaxTime: obj.options.l10n.slotMaxTime,
            scrollTime: obj.options.l10n.scrollTime,
            moreLinkContent: function (arg) {
                return obj.options.l10n.more.replace('%d', arg.num)
            },
            flexibleSlotTimeLimits: true,
            eventStartEditable: false,
            eventDurationEditable: false,
            allDaySlot: false,
            allDayContent: obj.options.l10n.allDay,
            slotLabelFormat: function (date) {
                return moment(date).locale('bookly').format(obj.options.l10n.mjsTimeFormat);
            },
            dayHeaderFormat: function (date) {
                return moment(date).locale('bookly').format('ddd, D');
            },
            listDayFormat: function (date) {
                return moment(date).locale('bookly').format('dddd');
            },
            firstDay: obj.options.l10n.datePicker.firstDay,
            locale: obj.options.l10n.locale.replace('_', '-'),
            buttonText: {
                today: obj.options.l10n.today,
                dayGridMonth: obj.options.l10n.month,
                timeGridWeek: obj.options.l10n.week,
                timeGridDay: obj.options.l10n.day,
                resourceTimeGridDay: obj.options.l10n.day,
                resourceTimelineMonth: obj.options.l10n.month,
                resourceTimelineWeek: obj.options.l10n.week,
                resourceTimelineDay: obj.options.l10n.day,
                listWeek: obj.options.l10n.list
            },
            noEventsContent: obj.options.l10n.noEvents,
            customScrollbars: true,
            eventSources: [{
                url: ajaxurl,
                method: 'POST',
                extraParams: function () {
                    return {
                        action: 'bookly_get_staff_appointments',
                        csrf_token: BooklyL10nGlobal.csrf_token,
                        staff_ids: obj.options.getStaffMemberIds(),
                        location_ids: obj.options.getLocationIds(),
                        service_ids: obj.options.getServiceIds()
                    };
                }
            }],
            eventFilter: function (info) {
                return !['dayGridMonth', 'timeGridWeek', 'listWeek'].includes(info.view.type) || !info.event.extendedProps.resources_only;
            },
            eventBackgroundColor: '#ccc',
            eventMouseEnter: function (arg) {
                arg.jsEvent.stopPropagation();
                if (arg.event.display === 'background') {
                    return '';
                }
                let $event = $(arg.el);
                if (arg.event.display === 'auto' && arg.view.type !== 'listWeek') {
                    if (popoverEventEl !== arg.el) {
                        popoverRemove();
                        popoverEventEl = arg.el;
                    }
                    if (!$popover.length) {
                        $popover = $('<div class="bookly-popover bs-popover-top bookly-ec-popover">')
                        let $calendar_container = $event.closest('.ec');
                        let $arrow = $('<div class="arrow"></div><div class="bookly-arrow-background"></div>');
                        let $body = $('<div class="popover-body">');
                        let $buttons = allowEditAppointment ? popoverButtons(arg) : '';

                        $body.append(arg.event.extendedProps.tooltip).append($buttons).css({minWidth: '180px'});
                        $popover.append($arrow).append($body);
                        $calendar_container.append($popover);
                        $popover = $calendar_container.find('.bookly-ec-popover');

                        let event_offset = $event.offset(),
                            popover_height = $popover.outerHeight(),
                            event_width = $event.outerWidth(),
                            popover_on_right = event_offset.left > window.innerWidth / 2;

                        $popover.css('min-width', (Math.min(400, event_width - 2)) + 'px');

                        let popover_top = Math.min(arg.jsEvent.y, Math.max(0, Math.min(event_offset.top - $(document).scrollTop() + $event.outerHeight() - popover_height, $(window).height() - popover_height)));
                        let popover_left = popover_on_right ? event_offset.left - $popover.outerWidth() : Math.min(event_offset.left - 7 + event_width, $calendar_container.offset().left + $calendar_container.outerWidth() - $popover.outerWidth() - 32);
                        //Check if the popover is over a mouse pointer
                        if (popover_left <= arg.jsEvent.x && popover_left + $popover.outerWidth() >= arg.jsEvent.x && popover_top <= arg.jsEvent.y && popover_top + $popover.outerHeight() >= arg.jsEvent.y) {
                            popover_top = arg.jsEvent.y - popover_height - 8;
                        }
                        $popover.css('top', popover_top + 'px');
                        $popover.css('left', popover_left + 'px');

                        $arrow.css('top', Math.min(popover_height - 32, Math.max(arg.jsEvent.y - popover_top - 14, 8)) + 'px');
                        if (popover_on_right) {
                            $popover.removeClass('bs-popover-top').removeClass('bs-popover-right').addClass('bs-popover-left');
                            $arrow.css('right', '-8px');
                        } else {
                            $popover.removeClass('bs-popover-top').removeClass('bs-popover-left').addClass('bs-popover-right');
                            $arrow.css('left', '-8px');
                        }

                        $popover.on('pointerenter', function () {
                            popoverShow();
                        });
                        $popover.on('pointerleave', function () {
                            popoverClose();
                        });
                    }

                    popoverShow();
                }
            },
            eventMouseLeave: function () {
                popoverClose();
            },
            eventContent: function (arg) {
                if (arg.event.display === 'background') {
                    return '';
                }
                let event = arg.event;
                let props = event.extendedProps;
                let nodes = [];
                let $time = $('<div class="ec-event-time"/>');
                let $title = $('<div class="ec-event-title"/>');

                $time.append(props.header_text || (arg.event.display !== 'pointer' ? moment(arg.event.start).locale('bookly').format(obj.options.l10n.mjsTimeFormat) + ' - ' + moment(arg.event.end).locale('bookly').format(obj.options.l10n.mjsTimeFormat) : moment(arg.event.start).locale('bookly').format(obj.options.l10n.mjsTimeFormat)));
                nodes.push($time.get(0));
                if (arg.view.type === 'listWeek') {
                    let dot = $('<div class="ec-event-dot"></div>').css('border-color', event.backgroundColor);
                    nodes.push($('<div/>').append(dot).get(0));
                }
                $title.append(props.desc || '');
                nodes.push($title.get(0));

                switch (props.overall_status) {
                    case 'pending':
                        $time.addClass('text-muted');
                        $title.addClass('text-muted');
                        break;
                    case 'rejected':
                    case 'cancelled':
                        $time.addClass('text-muted').wrapInner('<s>');
                        $title.addClass('text-muted');
                        break;
                }

                if (arg.view.type === 'listWeek' && allowEditAppointment) {
                    $title.append(popoverButtons(arg));
                }

                return {domNodes: nodes};
            },
            eventClick: function (arg) {
                eventClick(arg)
            },
            dateClick: function (arg) {
                let staff_id, visible_staff_id;
                if (['resourceTimeGridDay', 'resourceTimelineMonth', 'resourceTimelineWeek', 'resourceTimelineDay'].includes(arg.view.type)) {
                    staff_id = arg.resource.id;
                    visible_staff_id = 0;
                } else {
                    staff_id = visible_staff_id = obj.options.getCurrentStaffId();
                }
                addAppointmentDialog(arg.date, staff_id, visible_staff_id);
            },
            noEventsClick: function (arg) {
                let staffId = obj.options.getCurrentStaffId();
                addAppointmentDialog(arg.view.activeStart, staffId, staffId);
            },
            loading: function (isLoading) {
                if (!calendar) {
                    return;
                }
                if (isLoading) {
                    if (allowEditAppointment) {
                        BooklyL10nAppDialog.refreshed = true;
                    }
                    if (dateSetFromDatePicker) {
                        dateSetFromDatePicker = false;
                    } else {
                        calendar.setOption('highlightedDates', []);
                    }
                    $('.bookly-ec-loading').show();
                } else {
                    let allDay = false;
                    if (calendar.getEvents().length) {
                        calendar.getEvents().forEach(function (event) {
                            if (event.allDay) {
                                allDay = true;
                            }
                        })
                    }
                    calendar.setOption('allDaySlot', allDay);
                    $('.bookly-ec-loading').hide();
                    obj.options.refresh();
                    popoverDropOrphan();
                }
            },
            viewDidMount: function (info) {
                calendar.setOption('highlightedDates', []);
                obj.options.viewChanged(info.view);
                popoverDropOrphan();
            },
            theme: function (theme) {
                theme.button = 'btn btn-default';
                theme.buttonGroup = 'btn-group';
                theme.active = 'active';
                return theme;
            }
        };

        function eventClick(arg) {
            if (arg.event.display === 'background') {
                return;
            }
            if (arg.jsEvent) {
                arg.jsEvent.stopPropagation();
            }

            if (allowEditAppointment) {
                if (arg.event.extendedProps.type === 'event') {
                    BooklyAttendeesDialog.showForm(
                        getBooklyModalContainer('bookly-attendees-dialog'),
                        {eventId: arg.event.extendedProps.id, onDone: function () { calendar.refetchEvents(); }}
                    )
                } else {
                    let visible_staff_id;
                    if (arg.view.type === 'resourceTimeGridDay') {
                        visible_staff_id = 0;
                    } else {
                        visible_staff_id = obj.options.getCurrentStaffId();
                    }
                    // What the classic form reports back: the saved appointment, a task with
                    // no time, or a plain refresh. Kept as it was — the card only changes
                    // what opens first, not what happens after the form is saved.
                    let onAppointmentSaved = function (event) {
                        if (event == 'refresh') {
                            calendar.refetchEvents();
                        } else {
                            if (event.start === null) {
                                // Task
                                calendar.removeEventById(event.id);
                            } else {
                                if (visible_staff_id == event.resourceId || visible_staff_id == 0) {
                                    // Update event in calendar.
                                    calendar.removeEventById(event.id);
                                    calendar.addEvent(event);
                                } else {
                                    // Switch to the event owner tab.
                                    jQuery('li > a[data-staff_id=' + event.resourceId + ']').click();
                                }
                            }
                        }

                        if (locationChanged) {
                            calendar.refetchEvents();
                            locationChanged = false;
                        }
                    };

                    // The card and the wizard are one interface and are switched on
                    // together: the card's own actions are the wizard's, and without them it
                    // is the classic form one click further away. Screens that have neither
                    // — the calendars outside wp-admin — keep the classic form as well.
                    if (typeof BooklyAppointmentCard !== 'undefined' && typeof BooklyBookingWizard !== 'undefined' && BooklyL10n.createWithWizard) {
                        // A click on an appointment opens the card — a view of the whole
                        // appointment, from which the classic form and the wizard are
                        // reached.
                        BooklyAppointmentCard.showCard(
                            getBooklyModalContainer('bookly-appointment-card'),
                            {
                                appointmentId: arg.event.id,
                                onEdit: function () {
                                    BooklyAppointmentDialog.showDialog(arg.event.id, null, null, onAppointmentSaved);
                                },
                                // A series is deleted by the card too: its delete window asks
                                // how far the deletion reaches — this visit, this and the
                                // later ones, the whole series — in the same words the
                                // wizard asks before moving one. Nothing is handed over.
                                // Deleting may take other appointments with it, so the
                                // calendar is refetched rather than told to drop one event.
                                onDeleted: function () { calendar.refetchEvents(); },
                                onChanged: function () { calendar.refetchEvents(); },
                            }
                        );
                    } else {
                        BooklyAppointmentDialog.showDialog(arg.event.id, null, null, onAppointmentSaved);
                    }
                }
            }
        }

        function popoverButtons(arg) {
            const $buttons = arg.view.type === 'listWeek' ? $('<div class="mt-2 d-flex"></div>') : $('<div class="mt-2 d-flex justify-content-end border-top pt-2"></div>');
            let props = arg.event.extendedProps;
            if (props.hasOwnProperty('type') && props.type === 'event') {
                $buttons.append(
                    $('<a class="btn btn-success btn-sm me-1">').append('<i class="fas fa-fw fa-users">')
                        .attr('title', obj.options.l10n.events.attendees)
                        .on('click', function (e) {
                            e.stopPropagation();
                            BooklyAttendeesDialog.showForm(
                                getBooklyModalContainer('bookly-attendees-dialog'),
                                {eventId: arg.event.extendedProps.id, onDone: function () { calendar.refetchEvents(); }}
                            )
                        })
                );
            } else {
                $buttons.append($('<button class="btn btn-success btn-sm me-1">').append('<i class="far fa-fw fa-edit">').on('click', function (e) {
                    e.stopPropagation();
                    eventClick(arg);
                }));
                // Reschedule the whole appointment in the booking wizard (all customers move
                // together); shown only when the wizard flow is enabled and its bundle loaded.
                if (typeof BooklyBookingWizard !== 'undefined' && BooklyL10n.createWithWizard) {
                    $buttons.append(
                        $('<a class="btn btn-default btn-sm me-1">').append('<i class="fas fa-fw fa-exchange-alt">')
                            .attr('title', obj.options.l10n.reschedule)
                            .on('click', function (e) {
                                e.stopPropagation();
                                popoverClose();
                                BooklyBookingWizard.showDialog({
                                    reschedule: parseInt(arg.event.id),
                                    onSaved: function () { calendar.refetchEvents(); }
                                });
                            })
                    );
                }
                if (props.participants == 'one') {
                    if (obj.options.l10n.recurring_appointments.active == '1' && props.series_id) {
                        $buttons.append(
                            $('<a class="btn btn-default btn-sm me-1">').append('<i class="fas fa-fw fa-link">')
                                .attr('title', obj.options.l10n.recurring_appointments.title)
                                .on('click', function (e) {
                                    e.stopPropagation();
                                    BooklySeriesDialog.showDialog({
                                        series_id: props.series_id,
                                        done: function () {
                                            calendar.refetchEvents();
                                        }
                                    });
                                })
                        );
                    }
                    if (obj.options.l10n.waiting_list.active == '1' && props.waitlisted > 0) {
                        $buttons.append(
                            $('<a class="btn btn-default btn-sm me-1">').append('<i class="far fa-fw fa-list-alt">')
                                .attr('title', obj.options.l10n.waiting_list.title)
                        );
                    }
                    if (obj.options.l10n.packages.active == '1' && props.package_id > 0) {
                        $buttons.append(
                            $('<a class="btn btn-default btn-sm me-1">').append('<i class="far fa-fw fa-calendar-alt">')
                                .attr('title', obj.options.l10n.packages.title)
                                .on('click', function (e) {
                                    e.stopPropagation();
                                    if (obj.options.l10n.packages.active == '1' && props.package_id) {
                                        $(document.body).trigger('bookly_packages.schedule_dialog', [props.package_id, function () {
                                            calendar.refetchEvents();
                                        }]);
                                    }
                                })
                        );
                    }
                    $buttons.append(
                        $('<a class="btn btn-danger btn-sm text-white">').append('<i class="far fa-fw fa-trash-alt">')
                            .attr('title', obj.options.l10n.delete)
                            .on('click', function(e) {
                                e.stopPropagation();
                                // Localize contains only string values
                                if (obj.options.l10n.recurring_appointments.active == '1' && props.series_id) {
                                    $(document.body).trigger('recurring_appointments.delete_dialog', [calendar, arg.event]);
                                } else {
                                    new BooklyConfirmDeletingAppointment({
                                            action: 'bookly_delete_appointment',
                                            appointment_id: arg.event.id,
                                            csrf_token: BooklyL10nGlobal.csrf_token
                                        },
                                        function(response) {
                                            calendar.removeEventById(arg.event.id);
                                        }
                                    );
                                }
                            })
                    );
                }
            }

            return $buttons;
        }

        function addAppointmentDialog(date, staffId, visibleStaffId) {
            if (!allowEditAppointment) {
                return;
            }
            var openWizard = typeof BooklyBookingWizard !== 'undefined' ? function (params) {
                BooklyBookingWizard.showDialog({
                    start_date: params && params.start_date,
                    start_time: params && params.start_time,
                    staff_id: params && params.staff_id,
                    service_id: params && params.service_id,
                    location_id: params && params.location_id,
                    onSaved: function () { calendar.refetchEvents(); },
                    onOpenClassic: openClassic
                });
            } : null;
            function openClassic(params) {
                BooklyAppointmentDialog.showDialog(
                    null,
                    params && params.staff_id ? params.staff_id : parseInt(staffId),
                    params && params.date ? moment(params.date) : moment(date),
                    function (event) {
                        if (event == 'refresh') {
                            calendar.refetchEvents();
                        } else {
                            if (visibleStaffId == event.resourceId || visibleStaffId == 0) {
                                if (event.start !== null) {
                                    if (event.id) {
                                        // Create event in calendar.
                                        calendar.addEvent(event);
                                    } else {
                                        calendar.refetchEvents();
                                    }
                                }
                            } else {
                                // Switch to the event owner tab.
                                jQuery('li[data-staff_id=' + event.resourceId + ']').click();
                            }
                        }

                        if (locationChanged) {
                            calendar.refetchEvents();
                            locationChanged = false;
                        }
                    },
                    params ? { service_id: params.service_id, location_id: params.location_id } : null,
                    openWizard
                );
            }
            // When the wizard is the chosen creation flow, an empty-slot click opens it
            // with the clicked day preset and, in a staff column, that staff preferred.
            // The header link inside the wizard falls back to the classic form.
            if (typeof BooklyBookingWizard !== 'undefined' && BooklyL10n.createWithWizard) {
                BooklyBookingWizard.showDialog({
                    start_date: moment(date).format('YYYY-MM-DD'),
                    start_time: moment(date).format('HH:mm'),
                    staff_id: parseInt(staffId) > 0 ? parseInt(staffId) : null,
                    onSaved: function () { calendar.refetchEvents(); },
                    onOpenClassic: openClassic
                });
                return;
            }
            openClassic();
        }


        /**
         * Set cookie.
         *
         * @param key
         * @param value
         */
        function setCookie(key, value) {
            var expires = new Date();
            expires.setFullYear(expires.getFullYear() + 3);
            document.cookie = key + '=' + value + ';expires=' + expires.toUTCString();
        }

        let dateSetFromDatePicker = false;

        calendar = EventCalendar.create($container.get(0), $.extend(true, {}, settings, obj.options.calendar));

        $('.ec-toolbar .ec-title', $container).on('click', function () {
            let picker = $(this).data('daterangepicker');
            picker.setStartDate(calendar.getOption('date'));
            picker.setEndDate(calendar.getOption('date'));
        });
        // Init date picker for fast navigation in Event Calendar.
        $('.ec-toolbar .ec-title', $container).daterangepicker({
            parentEl: '.bookly-js-calendar',
            singleDatePicker: true,
            showDropdowns: true,
            autoUpdateInput: false,
            locale: obj.options.l10n.datePicker
        }, function (start) {
            dateSetFromDatePicker = true;
            calendar.setOption('highlightedDates', [start.toDate()]);
            calendar.setOption('date', start.toDate());
        });

        // Export calendar
        this.ec = calendar;
    };

    var locationChanged = false;
    $('body').on('change', '#bookly-appointment-location', function () {
        locationChanged = true;
    });

    Calendar.prototype.options = {
        calendar: {},
        getCurrentStaffId: function () { return -1; },
        getStaffMemberIds: function () { return [this.getCurrentStaffId()]; },
        getServiceIds: function () { return ['all']; },
        getLocationIds: function () { return ['all']; },
        refresh: function () {},
        viewChanged: function () {},
        l10n: {}
    };

    window.BooklyCalendar = Calendar;
})(jQuery);