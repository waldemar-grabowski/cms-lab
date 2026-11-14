<?php
namespace Bookly\Backend\Modules\Setup;

use Bookly\Lib;
use Bookly\Backend\Modules\Services\Proxy;

class Ajax extends Lib\Base\Ajax
{
    /**
     * Get data for setup page.
     */
    public static function getSetupForm()
    {
        /** @global \WP_Locale $wp_locale */
        global $wp_locale;

        $services = Lib\Entities\Service::query( 's' )
            ->select( 'id, title, duration' )
            ->fetchArray();
        foreach ( $services as &$service ) {
            $service['duration'] = (int) $service['duration'];
        }
        $staff = Lib\Entities\Staff::query( 's' )
            ->select( 'id, full_name as name, email, phone' )
            ->fetchArray();

        // Business hours
        $week_day_ids = array(
            1 => 'sunday',
            'monday',
            'tuesday',
            'wednesday',
            'thursday',
            'friday',
            'saturday',
        );
        $start_of_week = (int) get_option( 'start_of_week' );
        $business_hours = array();

        for ( $i = 1; $i <= 7; $i++ ) {
            $day_index = ( $start_of_week + $i ) < 8 ? $start_of_week + $i : $start_of_week + $i - 7;
            $day = $week_day_ids[ $day_index ];
            foreach ( array( 'start', 'end' ) as $var ) {
                $$var = get_option( 'bookly_bh_' . $day . '_' . $var, 'not-exists' );
                if ( 'not-exists' === $$var ) {
                    if ( $day === 'saturday' || $day === 'sunday' ) {
                        $$var = '';
                    } else {
                        $$var = $var === 'start' ? '8:00' : '18:00';
                    }
                }
            }
            $business_hours[] = array( 'index' => $day_index, 'title' => $wp_locale->weekday[ $day_index == 7 ? 6 : ( $day_index - 1 ) ], 'start' => $start, 'end' => $end );
        }

        wp_send_json_success( array(
            'company' => get_option( 'bookly_co_name', '' ),
            'business_hours' => $business_hours,
            'industry' => get_option( 'bookly_co_industry', false ),
            'size' => get_option( 'bookly_co_size', '' ),
            'email' => get_option( 'bookly_co_email', '' ),
            'staff_members' => $staff,
            'services' => $services,
        ) );
    }

    /**
     * Save setup form data.
     */
    public static function saveSetupForm()
    {
        $step = self::parameter( 'step', 1 );
        switch ( $step ) {
            case 2:
                // Save business hours
                $week_day_ids = array(
                    1 => 'sunday',
                    'monday',
                    'tuesday',
                    'wednesday',
                    'thursday',
                    'friday',
                    'saturday',
                );
                foreach ( self::parameter( 'business_hours', array() ) as $data ) {
                    foreach ( array( 'start', 'end' ) as $var ) {
                        $option = 'bookly_bh_' . $week_day_ids[ $data['index'] ] . '_' . $var;
                        update_option( $option, $data[ $var ] );
                    }
                }

                // Save timeslot length
                $bookly_gen_time_slot_length = self::parameter( 'timeslot_length' );
                if ( in_array( $bookly_gen_time_slot_length, Lib\Config::getTimeSlotLengthOptions() ) ) {
                    update_option( 'bookly_gen_time_slot_length', $bookly_gen_time_slot_length );
                }

                // Save currency
                update_option( 'bookly_pmt_currency', self::parameter( 'currency' ) );
                $currencies = Lib\Utils\Price::getCurrencies();
                do_action( 'wpml_register_single_string', 'bookly', 'currency_' . self::parameter( 'currency' ), $currencies[ self::parameter( 'currency' ) ]['symbol'] );
                break;
            case 3:
                $existing_staff = array();
                foreach ( self::parameter( 'staff_members', array() ) as $staff_data ) {
                    $staff = new Lib\Entities\Staff();
                    if ( isset( $staff_data['id'] ) && $staff_data['id'] ) {
                        $staff->load( $staff_data['id'] );
                    }
                    $staff
                        ->setFullName( $staff_data['name'] ?: __( 'Staff', 'bookly-responsive-appointment-booking-tool' ) )
                        ->setPhone( $staff_data['phone'] )
                        ->setEmail( $staff_data['email'] )
                        ->save();
                    $existing_staff[] = $staff->getId();
                    foreach ( Lib\Entities\Service::query()->find() as $service ) {
                        $staff_service = new Lib\Entities\StaffService();
                        $staff_service->loadBy( array( 'staff_id' => $staff->getId(), 'service_id' => $service->getId() ) );
                        if ( ! $staff_service->isLoaded() ) {
                            $staff_service
                                ->setStaffId( $staff->getId() )
                                ->setServiceId( $service->getId() )
                                ->save();
                        }
                    }
                }
                Lib\Entities\Staff::query()->delete()->whereNotIn( 'id', $existing_staff )->execute();
                break;
            case 4:
                $existing_services = array();
                foreach ( self::parameter( 'services', array() ) as $service_data ) {
                    $service = new Lib\Entities\Service();
                    if ( isset( $service_data['id'] ) && $service_data['id'] ) {
                        $service->load( $service_data['id'] );
                    }
                    $service
                        ->setTitle( $service_data['title'] ?: __( 'Service', 'bookly-responsive-appointment-booking-tool' ) )
                        ->setDuration( $service_data['duration'] )
                        ->save();
                    Proxy\Shared::serviceCreated( $service );
                    $existing_services[] = $service->getId();
                    foreach ( Lib\Entities\Staff::query()->find() as $staff ) {
                        $staff_service = new Lib\Entities\StaffService();
                        $staff_service->loadBy( array( 'staff_id' => $staff->getId(), 'service_id' => $service->getId() ) );
                        if ( ! $staff_service->isLoaded() ) {
                            $staff_service
                                ->setStaffId( $staff->getId() )
                                ->setServiceId( $service->getId() )
                                ->save();
                        }
                    }
                }
                Lib\Entities\Service::query()->delete()->whereNotIn( 'id', $existing_services )->execute();
                break;
        }
        if ( $step < 4 ) {
            update_option( 'bookly_setup_step', ++$step );
            Lib\UsageStats::track( 'setup.step', array( 'variant' => 'legacy', 'step' => $step ) );
        }

        wp_send_json_success();
    }

    public static function sendWizardSMS()
    {
        $cloud = Lib\Cloud\API::getInstance();
        $sms_sender = $cloud->getProduct( Lib\Cloud\Account::PRODUCT_SMS_NOTIFICATIONS );
        $sms_sender->sendWizardSms( self::parameter( 'phone' ));

        wp_send_json_success();
    }

    /**
     * Finish initial setup.
     */
    public static function finishSetupForm()
    {
        delete_option( 'bookly_setup_step' );
        Lib\UsageStats::track( 'setup.finished', array(
            'variant' => 'legacy',
            'services' => Lib\Entities\Service::query()->count(),
            'staff' => Lib\Entities\Staff::query()->count(),
        ) );

        wp_send_json_success();
    }

    /**
     * Billing data for the wizard Done screen (requested after in-wizard sign-in).
     */
    public static function getWizardBilling()
    {
        wp_send_json_success( Page::getCloudBilling() );
    }

    /**
     * Save setup wizard v2 data (single bulk request at finish).
     * Empty rows are skipped; free plan limits are enforced server-side.
     */
    public static function saveSetupV2()
    {
        $pro_active = Lib\Config::proActive();

        // Company
        $company = trim( self::parameter( 'company', '' ) );
        if ( $company !== '' ) {
            update_option( 'bookly_co_name', $company );
        }

        // Services. Free plan: max 5 in total — mirrors Services\Ajax::createService() gate.
        $services_left = $pro_active || get_option( 'bookly_updated_from_legacy_version' ) == 'lite'
            ? PHP_INT_MAX
            : max( 0, 5 - Lib\Entities\Service::query()->count() );
        $services_created = 0;
        foreach ( (array) self::parameter( 'services', array() ) as $service_data ) {
            $title = isset( $service_data['title'] ) ? trim( $service_data['title'] ) : '';
            if ( $title === '' || $services_left <= 0 ) {
                continue;
            }
            $service = new Lib\Entities\Service();
            $service
                ->setTitle( $title )
                ->setDuration( (int) $service_data['duration'] ?: Lib\Config::getTimeSlotLength() )
                ->save();
            Proxy\Shared::serviceCreated( $service );
            $services_left --;
            $services_created ++;
        }

        // Staff. Free plan: max 1 in total (same UI-level rule as the old wizard, enforced here).
        $staff_left = $pro_active ? PHP_INT_MAX : max( 0, 1 - Lib\Entities\Staff::query()->count() );
        $staff_created = 0;
        foreach ( (array) self::parameter( 'staff', array() ) as $staff_data ) {
            $name = isset( $staff_data['name'] ) ? trim( $staff_data['name'] ) : '';
            if ( $name === '' || $staff_left <= 0 ) {
                continue;
            }
            $staff = new Lib\Entities\Staff();
            $staff
                ->setFullName( $name )
                ->setEmail( isset( $staff_data['email'] ) ? trim( $staff_data['email'] ) : '' )
                ->setPhone( isset( $staff_data['phone'] ) ? trim( $staff_data['phone'] ) : '' )
                ->save();
            $staff_left --;
            $staff_created ++;
        }

        $sample_created = self::createSampleData();
        Lib\UsageStats::track( 'setup.finished', array(
            'variant' => 'v2',
            'services' => $services_created,
            'staff' => $staff_created,
            'sample' => $sample_created,
        ) );

        // Link every staff member to every service (same full matrix as the old wizard).
        foreach ( Lib\Entities\Staff::query()->find() as $staff ) {
            foreach ( Lib\Entities\Service::query()->find() as $service ) {
                $staff_service = new Lib\Entities\StaffService();
                $staff_service->loadBy( array( 'staff_id' => $staff->getId(), 'service_id' => $service->getId() ) );
                if ( ! $staff_service->isLoaded() ) {
                    $staff_service
                        ->setStaffId( $staff->getId() )
                        ->setServiceId( $service->getId() )
                        ->save();
                }
            }
        }

        wp_send_json_success( array( 'sample' => $sample_created ) );
    }

    /**
     * Finish setup mode. Called on the final screen when the user proceeds to
     * the calendar, so the wizard page stays available until they leave it.
     */
    public static function completeSetupV2()
    {
        delete_option( 'bookly_setup_step' );

        wp_send_json_success();
    }

    /**
     * Top up missing entities with samples and add demo appointments so the calendar
     * is alive right after the wizard. Sample customers are fake (@example.com, no
     * phones). Created ids are tracked in the bookly_sample_data option.
     *
     * @return bool Whether anything was created.
     */
    protected static function createSampleData()
    {
        $registry = array( 'staff' => array(), 'services' => array(), 'customers' => array(), 'appointments' => array() );

        // Everything auto-created is visibly marked "(sample)" — staff, services, customers.
        $sample_mark = ' (' . __( 'sample', 'bookly-responsive-appointment-booking-tool' ) . ')';

        // Staff: the calendar page renders only when at least one staff member exists.
        if ( ! Lib\Entities\Staff::query()->count() ) {
            $staff = new Lib\Entities\Staff();
            $staff->setFullName( __( 'Jane Doe', 'bookly-responsive-appointment-booking-tool' ) . $sample_mark )->save();
            $registry['staff'][] = $staff->getId();
        }
        if ( ! Lib\Entities\Service::query()->count() ) {
            $samples = array(
                array( __( 'Teeth Whitening', 'bookly-responsive-appointment-booking-tool' ), 3600 ),
                array( __( 'Consultation', 'bookly-responsive-appointment-booking-tool' ), 1800 ),
            );
            foreach ( $samples as $sample ) {
                $service = new Lib\Entities\Service();
                $service->setTitle( $sample[0] . $sample_mark )->setDuration( $sample[1] )->save();
                Proxy\Shared::serviceCreated( $service );
                $registry['services'][] = $service->getId();
            }
        }

        // Demo appointments — only on a virgin calendar.
        if ( ! Lib\Entities\Appointment::query()->count() ) {
            $staff_id = Lib\Entities\Staff::query()->limit( 1 )->fetchRow()['id'];
            $services = Lib\Entities\Service::query()->limit( 3 )->fetchArray();
            $customers = array();
            $customer = new Lib\Entities\Customer();
            $customer
                ->setFullName( 'Nick Johnson' . $sample_mark )
                ->setEmail( 'nick@example.com' )
                ->save();
            $registry['customers'][] = $customer->getId();
            $customers[] = $customer->getId();
            // Tue/Wed/Thu of the week displayed by the calendar (start_of_week based),
            // so the default week view opens with visible sample entries.
            $timezone = wp_timezone();
            $start_of_week = (int) get_option( 'start_of_week' );
            $today = new \DateTimeImmutable( 'today', $timezone );
            $week_start = $today->modify( sprintf( '-%d days', ( (int) $today->format( 'w' ) - $start_of_week + 7 ) % 7 ) );
            $slots = array( array( 2, '10:00' ), array( 3, '14:00' ), array( 4, '16:30' ) );
            foreach ( $slots as $i => $slot ) {
                $service = $services[ $i % count( $services ) ];
                $start = $week_start->modify( sprintf( '+%d days', ( $slot[0] - $start_of_week + 7 ) % 7 ) )->modify( $slot[1] );
                $appointment = new Lib\Entities\Appointment();
                $appointment
                    ->setStaffId( $staff_id )
                    ->setServiceId( $service['id'] )
                    ->setStartDate( $start->format( 'Y-m-d H:i:s' ) )
                    ->setEndDate( $start->modify( '+' . (int) $service['duration'] . ' seconds' )->format( 'Y-m-d H:i:s' ) )
                    ->setInternalNote( __( 'Sample appointment created by the setup wizard.', 'bookly-responsive-appointment-booking-tool' ) )
                    ->setCreatedFrom( 'backend' )
                    ->save();
                $ca = new Lib\Entities\CustomerAppointment();
                $ca
                    ->setAppointmentId( $appointment->getId() )
                    ->setCustomerId( $customers[ $i % count( $customers ) ] )
                    ->setStatus( Lib\Entities\CustomerAppointment::STATUS_APPROVED )
                    ->setCreatedFrom( 'backend' )
                    ->setCreatedAt( current_time( 'mysql' ) )
                    ->save();
                $registry['appointments'][] = $appointment->getId();
            }
        }

        if ( array_filter( $registry ) ) {
            update_option( 'bookly_sample_data', $registry );

            return true;
        }

        return false;
    }
}