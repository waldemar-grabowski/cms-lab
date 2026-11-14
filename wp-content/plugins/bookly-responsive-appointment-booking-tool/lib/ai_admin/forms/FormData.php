<?php
namespace Bookly\Lib\AiAdmin\Forms;

use Bookly\Backend\Modules\Appearance\ModernAppearance;
use Bookly\Lib;

/**
 * Saved forms of Appearance (bookly_forms) as the setup assistant reads and writes them: the
 * settings of a form are its JSON row on top of the defaults of its kind, as the editor builds
 * them (ModernAppearance::prepareAppearanceSettings()), but with the texts as saved, not
 * translated for the current language.
 */
abstract class FormData
{
    /** Kinds of modern booking forms the form actions change (set_form, duplicate_form). */
    const BOOKING_KINDS = 'search-form,services-form,staff-form,tags-form,cancellation-confirmation';

    /**
     * @return string[]
     */
    public static function bookingKinds()
    {
        return explode( ',', self::BOOKING_KINDS );
    }

    /**
     * Row of bookly_forms, or null.
     *
     * @param int $id
     * @return array|null
     */
    public static function row( $id )
    {
        $row = Lib\Entities\Form::query()->where( 'id', (int) $id )->fetchRow();

        return $row ?: null;
    }

    /**
     * Settings as saved in the row (decoded JSON).
     *
     * @param array $row
     * @return array
     */
    public static function saved( array $row )
    {
        $settings = isset( $row['settings'] ) && $row['settings'] ? json_decode( $row['settings'], true ) : array();

        return is_array( $settings ) ? $settings : array();
    }

    /**
     * Full settings of a form: the saved values on top of the defaults of the kind, as the editor
     * opens the form; l10n holds the saved texts (not translated) on top of the default texts.
     *
     * @param string $type
     * @param array  $saved
     * @return array
     */
    public static function full( $type, array $saved )
    {
        $defaults = ModernAppearance::prepareAppearanceSettings( $type, null );
        $full = $saved
            ? ModernAppearance::prepareAppearanceSettings( $type, array( 'settings' => json_encode( $saved ), 'custom_css' => '', 'token' => '' ) )
            : $defaults;
        unset( $full['custom_css'], $full['token'] );
        if ( in_array( $type, array( 'search-form', 'services-form', 'staff-form', 'tags-form' ), true ) ) {
            // Values the editor's Settings panel shows (and saves) for keys the row does not have yet.
            $full += array( 'adaptive_grid' => false, 'transparent_bg' => false, 'filters_position' => 'left', 'corner_radius' => 'medium', 'shadows' => 'none' );
        }
        $l10n =isset( $defaults['l10n'] ) && is_array( $defaults['l10n'] ) ? $defaults['l10n'] : array();
        if ( isset( $saved['l10n'] ) && is_array( $saved['l10n'] ) ) {
            $l10n = array_replace_recursive( $l10n, $saved['l10n'] );
        }
        $full['l10n'] = $l10n;

        return $full;
    }

    /**
     * Name of a form kind as the Appearance catalog shows it.
     *
     * @param string $type
     * @return string
     */
    public static function kindTitle( $type )
    {
        return Lib\Entities\Form::getTitle( $type );
    }

    /**
     * @param string $type
     * @param string $slug
     * @return string
     */
    public static function shortcode( $type, $slug )
    {
        return '[bookly-' . $type . ' ' . $slug . ']';
    }

    /**
     * Slug the first save makes from a name before a taken one gets a number (Form::generateToken()).
     *
     * @param string $name
     * @return string
     */
    public static function slugBase( $name )
    {
        return sanitize_key( preg_replace( '/\s+/', '-', (string) $name ) ) ?: 'token';
    }

    /**
     * @param string $slug
     * @return bool
     */
    public static function slugTaken( $slug )
    {
        return Lib\Entities\Form::query()->where( 'token', $slug )->count() > 0;
    }

    /**
     * Id of the first AI assistant form of the site (lowest id) — the form the chat reads its
     * payment captions and payment result texts from.
     *
     * @return int|null
     */
    public static function firstAiId()
    {
        $row = Lib\Entities\Form::query()->select( 'id' )->where( 'type', Lib\Entities\Form::TYPE_AI_ASSISTANT )->sortBy( 'id' )->fetchRow();

        return $row ? (int) $row['id'] : null;
    }

    /**
     * Settings a new AI assistant form starts with, as the tile "Add new form" of the editor: a copy
     * of the first AI form (without custom CSS and slug) or the defaults of the chat.
     *
     * @return array
     */
    public static function aiCopy()
    {
        $first = self::firstAiId();

        return self::full( Lib\Entities\Form::TYPE_AI_ASSISTANT, $first ? self::saved( self::row( $first ) ) : array() );
    }

    /**
     * Payment methods the editor of a form offers buttons for (BooklyL10nModernAppearance.payment_systems).
     *
     * @return string[]
     */
    public static function editorPaymentSystems()
    {
        if ( Lib\Config::proActive() && class_exists( '\BooklyPro\Frontend\Modules\ModernBookingForm\Lib\PaymentFlow' ) ) {
            return \BooklyPro\Frontend\Modules\ModernBookingForm\Lib\PaymentFlow::getSupportedGateways();
        }

        return Lib\Config::payLocallyEnabled() ? array( Lib\Entities\Payment::TYPE_LOCAL ) : array();
    }

    /**
     * Whether the editor shows the Payment step of a booking form: Coupons or Gift Cards, or a
     * payment method other than "on site".
     *
     * @return bool
     */
    public static function hasPaymentStep()
    {
        if ( Lib\Config::couponsActive() || Lib\Config::giftCardsActive() ) {
            return true;
        }
        $systems = self::editorPaymentSystems();

        return ! ( count( $systems ) === 0 || ( count( $systems ) === 1 && $systems[0] === Lib\Entities\Payment::TYPE_LOCAL ) );
    }

    /**
     * Name of a form theme ("" = Default).
     *
     * @param string $slug
     * @return string
     */
    public static function themeName( $slug )
    {
        if ( ! $slug ) {
            return '';
        }
        if ( Lib\Config::proActive() && class_exists( '\BooklyPro\Lib\Plugin' ) ) {
            $file = \BooklyPro\Lib\Plugin::getDirectory() . '/frontend/resources/css/themes/' . basename( $slug ) . '.json';
            if ( is_readable( $file ) ) {
                $manifest = json_decode( file_get_contents( $file ), true );
                if ( is_array( $manifest ) && ! empty( $manifest['name'] ) ) {
                    return (string) $manifest['name'];
                }
            }
        }

        return (string) $slug;
    }

    /**
     * Names of services by id (packages excluded, as in the editor).
     *
     * @return array id => title
     */
    public static function services()
    {
        $out = array();
        foreach ( Lib\Entities\Service::query()->select( 'id, title' )->whereNot( 'type', Lib\Entities\Service::TYPE_PACKAGE )->sortBy( 'position, id' )->fetchArray() as $row ) {
            $out[ (int) $row['id'] ] = $row['title'] === '' ? __( 'Untitled', 'bookly-responsive-appointment-booking-tool' ) : $row['title'];
        }

        return $out;
    }

    /**
     * @return array id => full name
     */
    public static function staff()
    {
        $out = array();
        foreach ( Lib\Entities\Staff::query()->select( 'id, full_name' )->sortBy( 'position, id' )->fetchArray() as $row ) {
            $out[ (int) $row['id'] ] = $row['full_name'];
        }

        return $out;
    }

    /**
     * Service categories with "Uncategorized" (-1) first, as the editor lists them.
     *
     * @return array id => name
     */
    public static function categories()
    {
        $out = array( -1 => __( 'Uncategorized', 'bookly-responsive-appointment-booking-tool' ) );
        foreach ( Lib\Entities\Category::query()->select( 'id, name' )->sortBy( 'position, id' )->fetchArray() as $row ) {
            $out[ (int) $row['id'] ] = $row['name'];
        }

        return $out;
    }

    /**
     * Staff categories with "Uncategorized" (-1) first.
     *
     * @return array id => name
     */
    public static function staffCategories()
    {
        $out = array( -1 => __( 'Uncategorized', 'bookly-responsive-appointment-booking-tool' ) );
        if ( class_exists( '\BooklyPro\Lib\Entities\StaffCategory' ) ) {
            foreach ( \BooklyPro\Lib\Entities\StaffCategory::query()->select( 'id, name' )->sortBy( 'position, id' )->fetchArray() as $row ) {
                $out[ (int) $row['id'] ] = $row['name'];
            }
        }

        return $out;
    }

    /**
     * @return array id => name
     */
    public static function locations()
    {
        $out = array();
        if ( Lib\Config::locationsActive() && class_exists( '\BooklyLocations\Lib\Entities\Location' ) ) {
            foreach ( \BooklyLocations\Lib\Entities\Location::query()->select( 'id, name' )->sortBy( 'position, id' )->fetchArray() as $row ) {
                $out[ (int) $row['id'] ] = $row['name'];
            }
        }

        return $out;
    }
}
