<?php
namespace Bookly\Lib\Utils;

use Bookly\Lib;

abstract class Common extends Lib\Base\Cache
{
    /** @var string CSRF token */
    private static $csrf;

    /**
     * Get e-mails of WP & Bookly admins
     *
     * @return array
     */
    public static function getAdminEmails()
    {
        global $wpdb;
        static $emails = null;

        if ( $emails === null ) {
            // Add to filter capability manage_options or manage_bookly
            $meta_query = array(
                'relation' => 'OR',
                array( 'key' => $wpdb->prefix . 'capabilities', 'compare' => 'LIKE', 'value' => '"manage_options"', ),
                array( 'key' => $wpdb->prefix . 'capabilities', 'compare' => 'LIKE', 'value' => '"manage_bookly"', ),
            );
            $roles = new \WP_Roles();
            // Find roles with capabilities manage_options or manage_bookly
            foreach ( $roles->role_objects as $role ) {
                if ( $role->has_cap( 'manage_options' ) || $role->has_cap( 'manage_bookly' ) ) {
                    $meta_query[] = array( 'key' => $wpdb->prefix . 'capabilities', 'compare' => 'LIKE', 'value' => '"' . $role->name . '"', );
                }
            }

            $emails = array_map(
                function( $a ) { return $a->data->user_email; },
                get_users( compact( 'meta_query' ) )
            );
        }

        return $emails;
    }

    /**
     * @return string
     */
    public static function getCurrentPageURL()
    {
        if ( ( ! empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== 'off' ) || $_SERVER['SERVER_PORT'] == 443 ) {
            $url = 'https://';
        } else {
            $url = 'http://';
        }
        $url .= isset( $_SERVER['HTTP_X_FORWARDED_HOST'] ) ? $_SERVER['HTTP_X_FORWARDED_HOST'] : $_SERVER['HTTP_HOST'];

        return $url . $_SERVER['REQUEST_URI'];
    }

    /**
     * @param bool $allow
     */
    public static function cancelAppointmentRedirect( $allow )
    {
        if ( $url = $allow ? get_option( 'bookly_url_cancel_page_url' ) : get_option( 'bookly_url_cancel_denied_page_url' ) ) {
            self::redirect( $url );
        }

        $url = home_url();
        if ( isset ( $_SERVER['HTTP_REFERER'] ) ) {
            if ( parse_url( $_SERVER['HTTP_REFERER'], PHP_URL_HOST ) == parse_url( $url, PHP_URL_HOST ) ) {
                // Redirect back if user came from our site.
                $url = $_SERVER['HTTP_REFERER'];
            }
        }

        self::redirect( $url );
    }

    /**
     * Render redirection page
     *
     * @param string $url
     */
    public static function redirect( $url )
    {
        header( 'Location: ' . $url, true, 302 );
        printf( '<!doctype html>
                <html>
                <head>
                    <meta charset="UTF-8">
                    <meta http-equiv="refresh" content="1;url=%s">
                    <script type="text/javascript">
                        window.location.href = %s;
                    </script>
                    <title>%s</title>
                </head>
                <body>
                %s
                </body>
                </html>',
            esc_attr( $url ),
            json_encode( $url ),
            __( 'Page Redirection', 'bookly-responsive-appointment-booking-tool' ),
            sprintf( __( 'If you are not redirected automatically, follow the <a href="%s">link</a>.', 'bookly-responsive-appointment-booking-tool' ), esc_attr( $url ) )
        );
        exit ( 0 );
    }

    /**
     * Escape params for admin.php?page
     *
     * @param $page_slug
     * @param array $params
     * @return string
     */
    public static function escAdminUrl( $page_slug, $params = array() )
    {
        $path = 'admin.php?page=' . $page_slug;
        if ( ( $query = build_query( $params ) ) != '' ) {
            $path .= '&' . $query;
        }

        return esc_url( admin_url( $path ) );
    }

    /**
     * Check whether any of the current posts in the loop contains given short code.
     *
     * @param string $short_code
     * @return bool
     */
    public static function postsHaveShortCode( $short_code )
    {
        $key = __FUNCTION__ . '-' . $short_code;
        if ( ! self::hasInCache( $key ) ) {
            /** @global \WP_Query $wp_query */
            global $wp_query;
            $result = false;
            if ( $wp_query && $wp_query->posts !== null ) {
                foreach ( $wp_query->posts as $post ) {
                    if ( has_shortcode( $post->post_content, $short_code ) || ( function_exists( 'parse_blocks' ) && self::hasBooklyShortCode( parse_blocks( $post->post_content ), $short_code ) ) ) {
                        $result = true;
                        break;
                    }
                    // Fusion builder
                    if ( strpos( $post->post_content, '[fusion' ) !== false ) {
                        $content = apply_filters( 'fusion_add_globals', $post->post_content, $post->guid );
                        if ( has_shortcode( apply_filters( 'fusion_add_globals', $post->post_content, $post->guid ), $short_code ) ) {
                            $result = true;
                            break;
                        }

                        try {
                            if ( preg_match_all( '/' . get_shortcode_regex( array( 'fusion_code' ) ) . '/s', $content, $matches ) ) {
                                foreach ( $matches[5] as $code ) {
                                    if ( has_shortcode( base64_decode( $code ), $short_code ) ) {
                                        $result = true;
                                        break 2;
                                    }
                                }
                            }
                        } catch ( \Exception $e ) {
                        }
                    }

                    try {
                        foreach ( get_post_meta( $post->ID ) ?: array() as $meta ) {
                            if ( is_string( $meta[0] ) && has_shortcode( $meta[0], $short_code ) ) {
                                $result = true;
                                break 2;
                            }
                        }
                    } catch ( \Exception $e ) {
                    }

                }
            }

            self::putInCache( $key, $result );
        }

        return self::getFromCache( $key );
    }

    /**
     * @param array $blocks
     * @param string $short_code
     * @return bool
     */
    private static function hasBooklyShortCode( $blocks, $short_code )
    {
        foreach ( $blocks as $block ) {
            if ( ! empty( $block['innerBlocks'] ) ) {
                return self::hasBooklyShortCode( $block['innerBlocks'], $short_code );
            }

            if ( $block['blockName'] === 'core/block' && ! empty( $block['attrs']['ref'] ) && has_shortcode( get_post( $block['attrs']['ref'] )->post_content, $short_code ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add utm_source, utm_medium, utm_campaign parameters to url
     *
     * @param $url
     * @param $campaign
     * @return string
     */
    public static function prepareUrlReferrers( $url, $campaign )
    {
        return add_query_arg(
            array(
                'utm_source' => 'bookly_admin',
                'utm_medium' => Lib\Config::proActive() ? 'pro_active' : 'pro_not_active',
                'utm_campaign' => $campaign,
            ),
            $url
        );
    }

    /**
     * Get option translated with WPML.
     *
     * @param $option_name
     * @return string
     */
    public static function getTranslatedOption( $option_name )
    {
        return self::getTranslatedString( $option_name, get_option( $option_name ) );
    }

    /**
     * Get string translated with WPML.
     *
     * @param string $name
     * @param string $original_value
     * @param null|string $language_code Return the translation in this language
     * @return string
     */
    public static function getTranslatedString( $name, $original_value = '', $language_code = null )
    {
        $result = apply_filters( 'wpml_translate_single_string', $original_value, 'bookly', $name, $language_code );

        return $result === null ? '' : $result;
    }

    /**
     * Check whether the current user is administrator or not.
     *
     * @return bool
     */
    public static function isCurrentUserAdmin()
    {
        return current_user_can( 'manage_options' ) || current_user_can( 'manage_bookly' );
    }

    /**
     * Check whether the current user is supervisor or not.
     *
     * @return bool
     */
    public static function isCurrentUserSupervisor()
    {
        return self::isCurrentUserAdmin() || current_user_can( 'manage_bookly_appointments' );
    }

    /**
     * Check whether the current user is staff or not.
     *
     * @return bool
     */
    public static function isCurrentUserStaff()
    {
        return self::isCurrentUserAdmin()
            || Lib\Entities\Staff::query()->where( 'wp_user_id', get_current_user_id() )->count() > 0;
    }

    /**
     * Staff members whose records the current user is allowed to work with.
     *
     * Supervisors and administrators work with every staff member, which is reported as
     * null so that callers can skip filtering altogether.
     *
     * @return int[]|null
     */
    public static function getCurrentUserStaffIds()
    {
        if ( self::isCurrentUserSupervisor() ) {
            return null;
        }

        return array_map( 'intval', Lib\Entities\Staff::query()
            ->where( 'wp_user_id', get_current_user_id() )
            ->fetchCol( 'id' ) );
    }

    /**
     * Whether the current user may read and change records of the given staff member.
     *
     * A staff member without supervisor rights is limited to their own records, so that the
     * appointments, customers and payments of a colleague stay out of reach.
     *
     * @param int $staff_id
     * @return bool
     */
    public static function currentUserCanManageStaff( $staff_id )
    {
        $allowed = self::getCurrentUserStaffIds();

        return $allowed === null || in_array( (int) $staff_id, $allowed, true );
    }

    /**
     * Whether the current user may read and change the given payment.
     *
     * A payment is reachable through the appointments it pays for, so it belongs to every
     * staff member serving those appointments and is available only when all of them are
     * within reach. A payment that pays for nothing has no owner among the staff, and a
     * follow-up payment is judged by the parent it extends.
     *
     * @param int $payment_id
     * @return bool
     */
    public static function currentUserCanManagePayment( $payment_id )
    {
        $allowed = self::getCurrentUserStaffIds();
        if ( $allowed === null ) {
            return true;
        }
        if ( ! $allowed || ! $payment_id ) {
            return false;
        }

        $staff_ids = array();
        $seen = array();
        while ( $payment_id && ! isset ( $seen[ $payment_id ] ) ) {
            $seen[ $payment_id ] = true;
            $staff_ids = Lib\Entities\CustomerAppointment::query( 'ca' )
                ->select( 'a.staff_id' )
                ->leftJoin( 'Appointment', 'a', 'a.id = ca.appointment_id' )
                ->where( 'ca.payment_id', $payment_id )
                ->fetchCol( 'staff_id' );
            if ( $staff_ids ) {
                break;
            }
            $payment = Lib\Entities\Payment::find( $payment_id );
            $payment_id = $payment ? $payment->getParentId() : null;
        }

        foreach ( $staff_ids as $staff_id ) {
            if ( ! in_array( (int) $staff_id, $allowed, true ) ) {
                return false;
            }
        }

        return $staff_ids !== array();
    }

    /**
     * Check whether the current user is customer or not.
     *
     * @return bool
     */
    public static function isCurrentUserCustomer()
    {
        return self::isCurrentUserSupervisor()
            || Lib\Entities\Customer::query()->where( 'wp_user_id', get_current_user_id() )->count() > 0
            || self::isCurrentUserStaff();
    }

    /**
     * Determine the current user time zone which may be the staff or WP time zone
     *
     * @return string
     */
    public static function getCurrentUserTimeZone()
    {
        if ( ! self::isCurrentUserSupervisor() ) {
            $staff = Lib\Entities\Staff::query()->where( 'wp_user_id', get_current_user_id() )->findOne();

            return self::getStaffTimeZone( $staff );
        }

        // Use WP time zone by default
        return Lib\Config::getWPTimeZone();
    }

    /**
     * @param  Lib\Base\Entity $staff
     * @return string
     */
    public static function getStaffTimeZone( $staff )
    {
        if ( $staff && ( $staff_tz = $staff->getTimeZone() ) ) {
            return $staff_tz;
        }

        return Lib\Config::getWPTimeZone();
    }

    /**
     * Get required capability for view menu.
     *
     * @return string
     */
    public static function getRequiredCapability()
    {
        return current_user_can( 'manage_options' ) ? 'manage_options' : 'manage_bookly';
    }

    /**
     * @param int $duration
     * @return array
     */
    public static function getDurationSelectOptions( $duration )
    {
        $step = (int) get_option( 'bookly_gen_time_slot_length', 15 );
        // up 12 hours
        $durations = range( $step, 720, $step );
        // add $duration
        $durations[] = (int) ( $duration / 60 );
        // up 1 week
        for ( $day = 1; $day <= 7; $day++ ) {
            $durations[] = $day * 1440;
        }
        // add extra lengths
        foreach ( explode( ',', get_option( 'bookly_advanced_time_slot_length_minutes', '' ) ) ?: array() as $minutes ) {
            $minutes && $durations[] = (int) $minutes;
        }
        // sort
        $durations = array_unique( $durations, SORT_NUMERIC );
        sort( $durations, SORT_NUMERIC );
        $options = array();
        foreach ( $durations as $min ) {
            $sec = $min * 60;
            $options[] = array(
                'value' => $sec,
                'label' => DateTime::secondsToInterval( $sec ),
                'selected' => selected( $duration, $sec, false ),
            );
        }

        return $options;
    }

    /**
     * Get services grouped by categories for drop-down list.
     *
     * @param string $raw_where
     * @return array
     */
    public static function getServiceDataForDropDown( $raw_where = null )
    {
        $result = array();

        $query = Lib\Entities\Service::query( 's' )
            ->select( 'c.id AS category_id, c.name, s.id, s.title' )
            ->leftJoin( 'Category', 'c', 'c.id = s.category_id' )
            ->sortBy( 'COALESCE(c.position,99999), s.position' );
        if ( $raw_where !== null ) {
            $query->whereRaw( $raw_where, array() );
        }
        foreach ( $query->fetchArray() as $row ) {
            $category_id = (int) $row['category_id'];
            if ( ! isset ( $result[ $category_id ] ) ) {
                $result[ $category_id ] = array(
                    'name' => $category_id ? $row['name'] : __( 'Uncategorized', 'bookly-responsive-appointment-booking-tool' ),
                    'items' => array(),
                );
            }
            $result[ $category_id ]['items'][] = array(
                'id' => $row['id'],
                'title' => $row['title'],
            );
        }

        return $result;
    }

    /**
     * @param callable $func
     * @param array $arr
     * @return array
     */
    public static function arrayMapRecursive( callable $func, array $arr )
    {
        array_walk_recursive( $arr, function( &$v ) use ( $func ) {
            $v = $func( $v );
        } );

        return $arr;
    }

    /**
     * XOR encrypt/decrypt.
     *
     * @param string $str
     * @param string $password
     * @return string
     */
    private static function _xor( $str, $password = '' )
    {
        $len = strlen( $str );
        $gamma = '';
        $n = $len > 100 ? 8 : 2;
        while ( strlen( $gamma ) < $len ) {
            $gamma .= substr( pack( 'H*', sha1( $password . $gamma ) ), 0, $n );
        }

        return $str ^ $gamma;
    }

    /**
     * XOR encrypt with Base64 encode.
     *
     * @param string $str
     * @param string $password
     * @return string
     */
    public static function xorEncrypt( $str, $password = '' )
    {
        return base64_encode( self::_xor( $str, $password ) );
    }

    /**
     * XOR decrypt with Base64 decode.
     *
     * @param string $str
     * @param string $password
     * @return string
     */
    public static function xorDecrypt( $str, $password = '' )
    {
        return self::_xor( base64_decode( $str ), $password );
    }

    /**
     * Generate unique value for entity field.
     *
     * @param string $entity_class_name
     * @param string $token_field
     * @return string
     */
    public static function generateToken( $entity_class_name, $token_field )
    {
        /** @var Lib\Base\Entity $entity */
        $entity = new $entity_class_name();
        do {
            $token = md5( uniqid( time(), true ) );
        } while ( $entity->loadBy( array( $token_field => $token ) ) === true );

        return $token;
    }

    /**
     * Get CSRF token.
     *
     * @return string
     */
    public static function getCsrfToken()
    {
        if ( self::$csrf === null ) {
            self::$csrf = wp_create_nonce( 'bookly' );
        }

        return self::$csrf;
    }

    /**
     * Set nocache constants.
     *
     * @param bool $forcibly
     */
    public static function noCache( $forcibly = false )
    {
        if ( $forcibly || get_option( 'bookly_gen_prevent_caching' ) ) {
            if ( ! defined( 'DONOTCACHEPAGE' ) ) {
                define( 'DONOTCACHEPAGE', true );
            }
            if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
                define( 'DONOTCACHEOBJECT', true );
            }
            if ( ! defined( 'DONOTCACHEDB' ) ) {
                define( 'DONOTCACHEDB', true );
            }
        }
    }

    /**
     * Disable WP Emoji
     */
    public static function disableEmoji()
    {
        remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
        remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
        remove_action( 'embed_head', 'print_emoji_detection_script' );
        remove_action( 'wp_print_styles', 'print_emoji_styles' );
        remove_action( 'admin_print_styles', 'print_emoji_styles' );
    }

    /**
     * @return \WP_Filesystem_Direct
     */
    public static function getFilesystem()
    {
        global $wp_filesystem;

        require_once ABSPATH . 'wp-admin/includes/file.php';

        if ( ! $wp_filesystem ) {
            WP_Filesystem();
        }

        // Emulate WP_Filesystem to avoid FS_METHOD and filters overriding "direct" type
        if ( ! class_exists( 'WP_Filesystem_Direct', false ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        }

        return new \WP_Filesystem_Direct( null );
    }

    /**
     * Get sorted payment systems
     *
     * @return array
     */
    public static function getGateways()
    {
        $gateways = array();
        if ( Lib\Config::payLocallyEnabled() ) {
            $gateways[ Lib\Entities\Payment::TYPE_LOCAL ] = Lib\Entities\Payment::typeToString( Lib\Entities\Payment::TYPE_LOCAL );
        }

        if ( Lib\Config::stripeCloudEnabled() ) {
            $gateways[ Lib\Entities\Payment::TYPE_CLOUD_STRIPE ] = Lib\Entities\Payment::typeToString( Lib\Entities\Payment::TYPE_CLOUD_STRIPE );
        }

        foreach ( \Bookly\Backend\Modules\Appearance\Proxy\Shared::paymentGateways( array() ) as $type => $gateway ) {
            $gateways[ $type ] = $gateway['title'];
        }

        $order = Lib\Config::getGatewaysPreference();
        $payment_systems = array();

        if ( $order ) {
            foreach ( $order as $payment_system ) {
                if ( array_key_exists( $payment_system, $gateways ) ) {
                    $payment_systems[ $payment_system ] = $gateways[ $payment_system ];
                    unset( $gateways[ $payment_system ] );
                }
            }
        }

        return array_merge( $payment_systems, $gateways );
    }

    /**
     * Payment systems switched on in Settings -> Payments.
     *
     * @return array[] [ type => [ title, label_option_name, logo_url ] ]
     */
    public static function getEnabledGateways()
    {
        $gateways = array();

        if ( Lib\Config::payLocallyEnabled() ) {
            $gateways[ Lib\Entities\Payment::TYPE_LOCAL ] = array(
                'label_option_name' => 'bookly_l10n_label_pay_locally',
                'title' => Lib\Entities\Payment::typeToString( Lib\Entities\Payment::TYPE_LOCAL ),
                'logo_url' => null,
            );
        }

        if ( Lib\Config::stripeCloudEnabled() ) {
            $gateways[ Lib\Entities\Payment::TYPE_CLOUD_STRIPE ] = array(
                'label_option_name' => 'bookly_l10n_label_pay_cloud_stripe',
                'title' => Lib\Entities\Payment::typeToString( Lib\Entities\Payment::TYPE_CLOUD_STRIPE ),
                'logo_url' => plugins_url( 'frontend/resources/images/payments.svg', Lib\Plugin::getMainFile() ),
            );
        }

        // Every payment add-on registers itself here with a title and a logo, the same
        // source the appearance editor and getGateways() read. WooCommerce is the one entry
        // whose "is it on" flag is not bookly_woocommerce_enabled but Config::wooCommerceEnabled().
        foreach ( \Bookly\Backend\Modules\Appearance\Proxy\Shared::paymentGateways( array() ) as $type => $gateway ) {
            $enabled = $type === Lib\Entities\Payment::TYPE_WOOCOMMERCE
                ? Lib\Config::wooCommerceEnabled()
                : get_option( 'bookly_' . $type . '_enabled' );
            if ( ! isset( $gateways[ $type ] ) && $enabled ) {
                $gateways[ $type ] = $gateway + array( 'label_option_name' => null, 'logo_url' => null );
            }
        }

        return $gateways;
    }

    /**
     * Get common settings for Bookly calendar
     *
     * @return array
     */
    public static function getCalendarSettings()
    {
        $slot_length_minutes = get_option( 'bookly_gen_time_slot_length', '15' );
        $slot = new \DateInterval( 'PT' . $slot_length_minutes . 'M' );

        $hidden_days = array();
        $min_time = '00:00:00';
        $max_time = '24:00:00';
        $scroll_time = '08:00:00';
        // Find min and max business hours
        $min = $max = null;
        foreach ( Lib\Config::getBusinessHours() as $day => $bh ) {
            if ( $bh['start'] === null ) {
                if ( Lib\Config::showOnlyBusinessDaysInCalendar() ) {
                    $hidden_days[] = $day;
                }
                continue;
            }
            if ( $min === null || $bh['start'] < $min ) {
                $min = $bh['start'];
            }
            if ( $max === null || $bh['end'] > $max ) {
                $max = $bh['end'];
            }
        }
        if ( $min !== null ) {
            $scroll_time = $min;
            if ( Lib\Config::showOnlyBusinessHoursInCalendar() ) {
                $min_time = $min;
                $max_time = $max;
            } elseif ( $max > '24:00:00' ) {
                $min_time = DateTime::buildTimeString( DateTime::timeToSeconds( $max ) - DAY_IN_SECONDS );
                $max_time = $max;
            }
        }

        return array(
            'hiddenDays' => $hidden_days,
            'slotDuration' => $slot->format( '%H:%I:%S' ),
            'slotMinTime' => $min_time,
            'slotMaxTime' => $max_time,
            'scrollTime' => $scroll_time,
            'locale' => Lib\Config::getShortLocale(),
            'monthDayMaxEvents' => (int) ( get_option( 'bookly_cal_month_view_style' ) == 'minimalistic' ),
            'mjsTimeFormat' => DateTime::convertFormat( 'time', DateTime::FORMAT_MOMENT_JS ),
            'datePicker' => DateTime::datePickerOptions(),
            'dateRange' => DateTime::dateRangeOptions(),
            'today' => __( 'Today', 'bookly-responsive-appointment-booking-tool' ),
            'week' => __( 'Week', 'bookly-responsive-appointment-booking-tool' ),
            'day' => __( 'Day', 'bookly-responsive-appointment-booking-tool' ),
            'month' => __( 'Month', 'bookly-responsive-appointment-booking-tool' ),
            'list' => __( 'List', 'bookly-responsive-appointment-booking-tool' ),
            'allDay' => __( 'All day', 'bookly-responsive-appointment-booking-tool' ),
            'noEvents' => __( 'No appointments for selected period.', 'bookly-responsive-appointment-booking-tool' ),
            'more' => __( '+%d more', 'bookly-responsive-appointment-booking-tool' ),
            'timeline' => __( 'Timeline', 'bookly-responsive-appointment-booking-tool' ),
        );
    }

    /**
     * @return array
     */
    public static function getIndustries()
    {
        return array(
            __( 'Education', 'bookly-responsive-appointment-booking-tool' ) => array(
                '34' => __( 'Universities', 'bookly-responsive-appointment-booking-tool' ),
                '35' => __( 'Colleges', 'bookly-responsive-appointment-booking-tool' ),
                '36' => __( 'Schools', 'bookly-responsive-appointment-booking-tool' ),
                '37' => __( 'Libraries', 'bookly-responsive-appointment-booking-tool' ),
                '38' => __( 'Teaching', 'bookly-responsive-appointment-booking-tool' ),
                '39' => __( 'Tutoring lessons', 'bookly-responsive-appointment-booking-tool' ),
                '40' => __( 'Parent meetings', 'bookly-responsive-appointment-booking-tool' ),
                '41' => __( 'Services', 'bookly-responsive-appointment-booking-tool' ),
                '42' => __( 'Child care', 'bookly-responsive-appointment-booking-tool' ),
                '43' => __( 'Driving Schools', 'bookly-responsive-appointment-booking-tool' ),
                '44' => __( 'Driving Instructors', 'bookly-responsive-appointment-booking-tool' ),
                '45' => __( 'Other', 'bookly-responsive-appointment-booking-tool' ),
            ),
            __( 'Beauty and wellness', 'bookly-responsive-appointment-booking-tool' ) => array(
                '11' => __( 'Beauty salons', 'bookly-responsive-appointment-booking-tool' ),
                '12' => __( 'Hair salons', 'bookly-responsive-appointment-booking-tool' ),
                '13' => __( 'Nail salons', 'bookly-responsive-appointment-booking-tool' ),
                '14' => __( 'Eyelash extensions', 'bookly-responsive-appointment-booking-tool' ),
                '15' => __( 'Spa', 'bookly-responsive-appointment-booking-tool' ),
                '16' => __( 'Other', 'bookly-responsive-appointment-booking-tool' ),
            ),
            __( 'Events and entertainment', 'bookly-responsive-appointment-booking-tool' ) => array(
                '46' => __( 'Events (One time and Recurring)', 'bookly-responsive-appointment-booking-tool' ),
                '47' => __( 'Business events', 'bookly-responsive-appointment-booking-tool' ),
                '48' => __( 'Meeting rooms', 'bookly-responsive-appointment-booking-tool' ),
                '49' => __( 'Escape rooms', 'bookly-responsive-appointment-booking-tool' ),
                '50' => __( 'Art classes', 'bookly-responsive-appointment-booking-tool' ),
                '51' => __( 'Equipment rental', 'bookly-responsive-appointment-booking-tool' ),
                '52' => __( 'Photographers', 'bookly-responsive-appointment-booking-tool' ),
                '53' => __( 'Restaurants', 'bookly-responsive-appointment-booking-tool' ),
                '54' => __( 'Other', 'bookly-responsive-appointment-booking-tool' ),
            ),
            __( 'Medical', 'bookly-responsive-appointment-booking-tool' ) => array(
                '17' => __( 'Medical Clinics & Doctors', 'bookly-responsive-appointment-booking-tool' ),
                '18' => __( 'Dentists', 'bookly-responsive-appointment-booking-tool' ),
                '19' => __( 'Chiropractors', 'bookly-responsive-appointment-booking-tool' ),
                '20' => __( 'Acupuncture', 'bookly-responsive-appointment-booking-tool' ),
                '21' => __( 'Massage', 'bookly-responsive-appointment-booking-tool' ),
                '22' => __( 'Physiologists', 'bookly-responsive-appointment-booking-tool' ),
                '23' => __( 'Psychologists', 'bookly-responsive-appointment-booking-tool' ),
                '24' => __( 'Other', 'bookly-responsive-appointment-booking-tool' ),
            ),
            __( 'Officials', 'bookly-responsive-appointment-booking-tool' ) => array(
                '55' => __( 'City councils', 'bookly-responsive-appointment-booking-tool' ),
                '56' => __( 'Embassies and consulates', 'bookly-responsive-appointment-booking-tool' ),
                '57' => __( 'Attorneys', 'bookly-responsive-appointment-booking-tool' ),
                '58' => __( 'Legal services', 'bookly-responsive-appointment-booking-tool' ),
                '59' => __( 'Financial services', 'bookly-responsive-appointment-booking-tool' ),
                '60' => __( 'Interview scheduling', 'bookly-responsive-appointment-booking-tool' ),
                '61' => __( 'Call centers', 'bookly-responsive-appointment-booking-tool' ),
                '62' => __( 'Other', 'bookly-responsive-appointment-booking-tool' ),
            ),
            __( 'Personal meetings and services', 'bookly-responsive-appointment-booking-tool' ) => array(
                '25' => __( 'Consulting', 'bookly-responsive-appointment-booking-tool' ),
                '26' => __( 'Counselling', 'bookly-responsive-appointment-booking-tool' ),
                '27' => __( 'Coaching', 'bookly-responsive-appointment-booking-tool' ),
                '28' => __( 'Spiritual services', 'bookly-responsive-appointment-booking-tool' ),
                '29' => __( 'Design consultants', 'bookly-responsive-appointment-booking-tool' ),
                '30' => __( 'Cleaning', 'bookly-responsive-appointment-booking-tool' ),
                '31' => __( 'Household', 'bookly-responsive-appointment-booking-tool' ),
                '32' => __( 'Pet services', 'bookly-responsive-appointment-booking-tool' ),
                '33' => __( 'Other', 'bookly-responsive-appointment-booking-tool' ),
            ),
            __( 'Retailers', 'bookly-responsive-appointment-booking-tool' ) => array(
                '1' => __( 'Supermarket', 'bookly-responsive-appointment-booking-tool' ),
                '2' => __( 'Retail Finance', 'bookly-responsive-appointment-booking-tool' ),
                '3' => __( 'Other retailers', 'bookly-responsive-appointment-booking-tool' ),
            ),
            __( 'Sport', 'bookly-responsive-appointment-booking-tool' ) => array(
                '4' => __( 'Personal trainers', 'bookly-responsive-appointment-booking-tool' ),
                '5' => __( 'Gyms', 'bookly-responsive-appointment-booking-tool' ),
                '6' => __( 'Fitness classes', 'bookly-responsive-appointment-booking-tool' ),
                '7' => __( 'Yoga classes', 'bookly-responsive-appointment-booking-tool' ),
                '8' => __( 'Golf classes', 'bookly-responsive-appointment-booking-tool' ),
                '9' => __( 'Sport items renting', 'bookly-responsive-appointment-booking-tool' ),
                '10' => __( 'Other', 'bookly-responsive-appointment-booking-tool' ),
            ),
            __( 'Other', 'bookly-responsive-appointment-booking-tool' ) => array(
                '63' => __( 'Other', 'bookly-responsive-appointment-booking-tool' ),
            ),
        );
    }

    /**
     * Remove XSS by wp_kses_post()
     *
     * @param string $html
     * @return string
     */
    public static function stripWpKses( $html )
    {
        return wp_kses( stripslashes( (string) $html ), 'post' );
    }

    /**
     * Remove <script> tags from the given string
     *
     * @param string $html
     * @return string
     */
    public static function stripScripts( $html )
    {
        return preg_replace( '@<script[^>]*?>.*?</script>@si', '', $html );
    }

    /**
     * Prepare html for output (currently allow all tags)
     *
     * @param string $html
     * @return string
     */
    public static function html( $html )
    {
        // Currently, allow any HTML tags
        return $html;
    }

    /**
     * Prepare css for output
     *
     * @param string $css
     * @return string
     */
    public static function css( $css )
    {
        return trim( preg_replace( '#<style[^>]*>(.*)</style>#is', '$1', $css ) );
    }

    /**
     * Update user meta only for blog users.
     *
     * @param string $meta_key
     * @param string $meta_value
     */
    public static function updateBlogUsersMeta( $meta_key, $meta_value, $blog_id = null )
    {
        global $wpdb;
        if ( is_multisite() ) {
            $prefix = $wpdb->get_blog_prefix( $blog_id );
            $query = 'UPDATE `' . $wpdb->usermeta . '` AS um
                   LEFT JOIN `' . $wpdb->usermeta . '` AS um2
                          ON (um2.user_id = um.user_id)
                         SET um.meta_value = %s 
                       WHERE um2.meta_key = %s
                         AND um.meta_key = %s';
            $wpdb->query( $wpdb->prepare( $query, $meta_value, $prefix . 'capabilities', $meta_key ) );
        } else {
            $wpdb->update( $wpdb->usermeta, compact( 'meta_value' ), compact( 'meta_key' ) );
        }
    }

    /**
     * @param $attachment_id
     * @param $size
     * @return string
     */
    public static function getAttachmentUrl( $attachment_id, $size = 'full' )
    {
        if ( $attachment_id && $img = wp_get_attachment_image_src( $attachment_id, $size ) ) {
            return $img[0];
        }

        return '';
    }

    /**
     * @param string $url
     * @param string $alt
     * @return string
     */
    public static function getImageTag( $url, $alt )
    {
        return $url
            ? sprintf( '<img src="%s" alt="%s" />', esc_attr( $url ), esc_attr( $alt ) )
            : '';
    }

    /**
     * @param int $response_code
     * @return void
     */
    public static function emptyResponse( $response_code )
    {
        if ( ! headers_sent() ) {
            header( 'Content-Type: text/html; charset=utf-8' );
            http_response_code( $response_code );
        }
        exit;
    }

    /**
     * @param Lib\Entities\Appointment $appointment
     * @return void
     */
    public static function syncWithCalendars( Lib\Entities\Appointment $appointment )
    {
        list( $sync, $gc, $oc, $ac ) = Lib\Config::syncCalendars();
        if ( $sync && $appointment->getStartDate() ) {
            $gc && Lib\Proxy\Pro::syncGoogleCalendarEvent( $appointment );
            $oc && Lib\Proxy\OutlookCalendar::syncEvent( $appointment );
            $ac && Lib\Proxy\AppleCalendar::syncEvent( $appointment );
        }
    }

    /**
     * @return array
     */
    public static function getAllStatuses()
    {
        if ( ! self::hasInCache( __FUNCTION__ ) ) {
            $statuses = array(
                array( 'slug' => Lib\Entities\CustomerAppointment::STATUS_PENDING, 'busy' => true ),
                array( 'slug' => Lib\Entities\CustomerAppointment::STATUS_APPROVED, 'busy' => true ),
                array( 'slug' => Lib\Entities\CustomerAppointment::STATUS_CANCELLED, 'busy' => false ),
                array( 'slug' => Lib\Entities\CustomerAppointment::STATUS_REJECTED, 'busy' => false ),
            );
            if ( Lib\Config::waitingListActive() ) {
                $statuses[] = array( 'slug' => Lib\Entities\CustomerAppointment::STATUS_WAITLISTED, 'busy' => false );
            }
            $statuses[] = array( 'slug' => Lib\Entities\CustomerAppointment::STATUS_DONE, 'busy' => false );

            foreach ( Lib\Proxy\CustomStatuses::getAll() as $status ) {
                $statuses[] = array( 'slug' => $status->getSlug(), 'busy' => (bool) $status->getBusy() );
            }
            foreach ( $statuses as &$data ) {
                $data['title'] = Lib\Entities\CustomerAppointment::statusToString( $data['slug'] );
            }

            self::putInCache( __FUNCTION__, $statuses );
        }

        return self::getFromCache( __FUNCTION__ );
    }

    /**
     * In-page tabs of pages that have them, keyed by admin page slug. Consumed by the
     * fullscreen header sidebar so any page's tabs are reachable as a submenu from anywhere
     * (navigated via ?page=<slug>&tab=<tab>). Each tab: [ 'label' => string, 'tab' => string,
     * 'badge' => string ]. Core ships Settings and Cloud SMS; add-ons extend the map (their own
     * pages, or extra Settings tabs) via Lib\Proxy\Shared::buildHeaderSubmenus().
     *
     * @return array
     */
    /**
     * Cloud products in the order they are shown: the flagship ones lead, the rest keep
     * the catalogue order. One rule for the Products page and the sidebar submenu.
     *
     * @param array $products Catalogue products
     * @return array
     */
    public static function sortCloudProducts( array $products )
    {
        $weights = array(
            Lib\Cloud\Account::PRODUCT_SMS_NOTIFICATIONS => 10,
            Lib\Cloud\Account::PRODUCT_AI => 20,
            Lib\Cloud\Account::PRODUCT_AI_ADMIN => 25,
            Lib\Cloud\Account::PRODUCT_CRON => 30,
        );
        $ordered = array();
        foreach ( array_values( $products ) as $position => $product ) {
            $id = isset( $product['id'] ) ? $product['id'] : '';
            $ordered[] = array(
                'pos' => isset( $weights[ $id ] ) ? $weights[ $id ] : 100 + $position,
                'product' => $product,
            );
        }
        usort( $ordered, function ( $a, $b ) { return $a['pos'] - $b['pos']; } );

        return array_map( function ( $item ) { return $item['product']; }, $ordered );
    }

    public static function getSubmenus()
    {
        if ( ! self::hasInCache( __FUNCTION__ ) ) {
            $undelivered = Lib\Cloud\SMS::getUndeliveredSmsCount();
            $submenus = array(
                'bookly-settings' => array(
                    array( 'label' => __( 'General', 'bookly-responsive-appointment-booking-tool' ),        'tab' => 'general',        'badge' => '' ),
                    array( 'label' => __( 'URL Settings', 'bookly-responsive-appointment-booking-tool' ),   'tab' => 'url',            'badge' => '' ),
                    array( 'label' => __( 'Calendar', 'bookly-responsive-appointment-booking-tool' ),       'tab' => 'calendar',       'badge' => '' ),
                    array( 'label' => __( 'Company', 'bookly-responsive-appointment-booking-tool' ),        'tab' => 'company',        'badge' => '' ),
                    array( 'label' => __( 'Customers', 'bookly-responsive-appointment-booking-tool' ),      'tab' => 'customers',      'badge' => '' ),
                    array( 'label' => __( 'Appointments', 'bookly-responsive-appointment-booking-tool' ),   'tab' => 'appointments',   'badge' => '' ),
                    array( 'label' => __( 'Payments', 'bookly-responsive-appointment-booking-tool' ),       'tab' => 'payments',       'badge' => '' ),
                    array( 'label' => __( 'Business Hours', 'bookly-responsive-appointment-booking-tool' ), 'tab' => 'business_hours', 'badge' => '' ),
                    array( 'label' => __( 'Holidays', 'bookly-responsive-appointment-booking-tool' ),       'tab' => 'holidays',       'badge' => '' ),
                ),
                'bookly-cloud-sms' => array(
                    array( 'label' => __( 'Notifications', 'bookly-responsive-appointment-booking-tool' ), 'tab' => 'notifications', 'badge' => '' ),
                    array( 'label' => __( 'Campaigns', 'bookly-responsive-appointment-booking-tool' ),     'tab' => 'campaigns',     'badge' => '' ),
                    array( 'label' => __( 'SMS Details', 'bookly-responsive-appointment-booking-tool' ),   'tab' => 'sms_details',   'badge' => $undelivered ? (string) $undelivered : '' ),
                    array( 'label' => __( 'Price list', 'bookly-responsive-appointment-booking-tool' ),    'tab' => 'price_list',    'badge' => '' ),
                    array( 'label' => __( 'Sender ID', 'bookly-responsive-appointment-booking-tool' ),     'tab' => 'sender_id',     'badge' => '' ),
                ),
                'bookly-notifications' => array(
                    array( 'label' => __( 'Notifications', 'bookly-responsive-appointment-booking-tool' ), 'tab' => 'notifications', 'badge' => '' ),
                    array( 'label' => __( 'Settings', 'bookly-responsive-appointment-booking-tool' ),      'tab' => 'settings',      'badge' => '' ),
                ),
                // Appearance form types the core ships itself; add-ons append theirs via the
                // proxy below. `pos` mirrors the Appearance catalog card order (ModernAppearance)
                // — the submenu is sorted by it after the proxy pass.
                'bookly-appearance' => array(
                    array( 'label' => __( 'AI assistant', 'bookly-responsive-appointment-booking-tool' ),      'url' => admin_url( 'admin.php?page=bookly-appearance&' . Lib\Entities\Form::TYPE_AI_ASSISTANT ), 'flag' => Lib\Entities\Form::TYPE_AI_ASSISTANT, 'badge' => '', 'pos' => 10 ),
                    array( 'label' => __( 'Step by step form', 'bookly-responsive-appointment-booking-tool' ), 'url' => admin_url( 'admin.php?page=bookly-appearance&' . Lib\Entities\Form::TYPE_BOOKLY_FORM ),  'flag' => Lib\Entities\Form::TYPE_BOOKLY_FORM,  'badge' => '', 'pos' => 90 ),
                ),
            );
            if ( ! Lib\Config::proActive() ) {
                // Free build: the modern booking form promo card has its own screen —
                // keep the submenu in sync with the catalog.
                $submenus['bookly-appearance'][] = array( 'label' => __( 'Modern booking form', 'bookly-responsive-appointment-booking-tool' ), 'url' => admin_url( 'admin.php?page=bookly-appearance&modern-form-promo' ), 'flag' => 'modern-form-promo', 'badge' => '', 'pos' => 95 );
            }

            // Cloud products: each has a page of its own on the Products page
            // (?page=bookly-cloud-products&product=<id>). Titles come from the catalogue,
            // already in the site's language. `param`/`value` rather than a bare `flag`:
            // the active one is told by the value, and the search index generator, which
            // turns flagged items into form entries, leaves these alone.
            $products = array();
            foreach ( self::sortCloudProducts( (array) Lib\Cloud\API::getInstance()->general->getProducts() ) as $product ) {
                if ( ! empty( $product['id'] ) && ! empty( $product['texts']['title'] ) ) {
                    $products[] = array(
                        'label' => $product['texts']['title'],
                        'url' => admin_url( 'admin.php?page=bookly-cloud-products&product=' . urlencode( $product['id'] ) ),
                        'param' => 'product',
                        'value' => $product['id'],
                        'badge' => '',
                    );
                }
            }
            if ( $products ) {
                $submenus['bookly-cloud-products'] = $products;
            }

            // Add-ons extend the map (own pages / extra Settings tabs). No-op when none implement it.
            $submenus = Lib\Proxy\Shared::buildHeaderSubmenus( $submenus );

            // The Appearance submenu mirrors the catalog card order: sort by the same
            // pos weights (items without one land in the middle add-on cluster).
            usort( $submenus['bookly-appearance'], function ( $a, $b ) {
                $wa = isset( $a['pos'] ) ? $a['pos'] : 50;
                $wb = isset( $b['pos'] ) ? $b['pos'] : 50;

                return $wa === $wb ? strcmp( $a['flag'], $b['flag'] ) : $wa - $wb;
            } );

            // Order the Settings submenu to mirror the in-page Settings menu (single source of order).
            // The page renders core/Pro tabs at fixed positions and every other add-on tab as an
            // alphabetical-by-slug group where Proxy\Shared::renderMenuItem() sits (between Cart and
            // Online Meetings). Add-ons just append their tab — the weight map below imposes the order,
            // so a new add-on slots into the alphabetical group automatically.
            if ( isset( $submenus['bookly-settings'] ) ) {
                $weights = array(
                    'general' => 0, 'url' => 1, 'calendar' => 2, 'company' => 3, 'customers' => 4, 'appointments' => 5,
                    'mailchimp' => 6, 'google_calendar' => 7, 'woo_commerce' => 8, 'facebook' => 9, 'cart' => 10,
                    // 11 — alphabetical add-on group (rendered via Proxy\Shared on the page)
                    'online_meetings' => 12, 'user_permissions' => 13, 'payments' => 14, 'additional' => 15,
                    'business_hours' => 16, 'holidays' => 17,
                );
                usort( $submenus['bookly-settings'], function ( $a, $b ) use ( $weights ) {
                    $wa = isset( $weights[ $a['tab'] ] ) ? $weights[ $a['tab'] ] : 11;
                    $wb = isset( $weights[ $b['tab'] ] ) ? $weights[ $b['tab'] ] : 11;
                    // Within the add-on group sort by tab slug — the page sorts by the English
                    // name, not the localized label, so the slug keeps both lists aligned.
                    return $wa === $wb ? strcmp( $a['tab'], $b['tab'] ) : $wa - $wb;
                } );
            }

            self::putInCache( __FUNCTION__, $submenus );
        }

        return self::getFromCache( __FUNCTION__ );
    }
}