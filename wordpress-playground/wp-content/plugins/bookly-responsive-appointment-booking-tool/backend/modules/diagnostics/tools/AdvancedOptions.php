<?php
namespace Bookly\Backend\Modules\Diagnostics\Tools;

use Bookly\Lib;

class AdvancedOptions extends Tool
{
    protected $slug = 'advanced-options';
    protected $hidden = true;
    protected $list;
    /** @var array Raw default values, keyed by option name */
    protected $defaults = array();

    public $position = 40;

    protected $bookly_methods = array( 'getOption' );

    protected $excluded_options = array(
        'bookly_cloud_account_products',
        'bookly_cloud_promotions',
        'bookly_setup_step',
        'bookly_pro_licensed_products'
    );

    protected $required = array(
        'bookly_cst_phone_default_country',
    );

    public function __construct()
    {
        $this->title = 'Advanced options';
    }

    public function render()
    {
        $this->getList();
        $list = $this->getErrorsList();
        $known_options = array_keys( $this->list );
        $can_write = current_user_can( 'manage_options' );

        return self::renderTemplate( '_advanced_options', compact( 'list', 'known_options', 'can_write' ), false );
    }

    /**
     * @inheritDoc
     */
    public function hasError()
    {
        $this->getList();
        $list = $this->getErrorsList();

        return ! empty( $list );
    }

    /**
     * @return array
     */
    private function getList()
    {
        if ( $this->list === null ) {
            $this->list = array();
            foreach ( apply_filters( 'bookly_plugins', array() ) as $plugin ) {
                /** @var Lib\Base\Plugin $plugin */
                $installer_class = $plugin::getRootNamespace() . '\Lib\Installer';
                /** @var Lib\Base\Installer $installer */
                $installer = new $installer_class();
                foreach ( $installer->getOptions() as $option => $value ) {
                    $this->defaults[ $option ] = $value;
                    $list_value = self::isProtected( $option )
                        ? array( 'current' => '', 'default' => '' )
                        : array( 'current' => self::formatValue( get_option( $option, 'not-exists' ) ), 'default' => self::formatValue( $value ) );
                    if ( ! $this->verifyOption( $option, $value ) ) {
                        $list_value['incorrect'] = true;
                    }
                    $this->list[ $option ] = $list_value;
                }
            }

        }

        return $this->list;
    }

    private function verifyOption( $option, $default )
    {
        if ( in_array( $option, $this->excluded_options, true ) ) {
            return true;
        }

        $wp_value = get_option( $option, 'not-exists' );
        if ( $wp_value === 'not-exists' ) {
            return false;
        }

        if ( $wp_value === '' && in_array( $option, $this->required, true ) ) {
            return false;
        }

        // Some special options check

        switch ( $option ) {
            case 'bookly_gen_time_slot_length':
                if ( ! ( $wp_value > 0 ) ) {
                    return false;
                }
                break;
            case 'bookly_paypal_enabled':
                if ( in_array( $wp_value, array( '0', 'ec', 'ps', 'checkout' ), true ) ) {
                    return true;
                }
                break;
        }

        if ( $default !== '' ) {
            if ( ( is_array( $default ) && ! is_array( $wp_value ) ) || ( ! is_array( $default ) && is_array( $wp_value ) ) ) {
                return false;
            }

            if ( is_string( $default ) && $this->isJson( $default ) && ! ( is_string( $wp_value ) && $this->isJson( $wp_value ) ) ) {
                return false;
            }

//            if ( is_numeric( $default ) && ! is_numeric( $wp_value ) ) {
//                return false;
//            }
        }

        return true;
    }

    /**
     * Reset option value to default
     *
     * @return void
     */
    public function setDefault()
    {
        $this->getList();

        $option = trim( self::parameter( 'option' ) );
        if ( ! isset( $this->list[ $option ] ) ) {
            wp_send_json_error();
        }

        $this->updateOption( $option, $this->defaults[ $option ] );

        wp_send_json_success();
    }

    /**
     * Get option
     *
     * @return void
     */
    public function getOption()
    {
        $this->getList();

        $option = trim( self::parameter( 'option' ) );
        if ( ! isset( $this->list[ $option ] ) ) {
            wp_send_json_error();
        }

        if ( self::isProtected( $option ) ) {
            wp_send_json_success( array( 'current' => '', 'default' => '', 'protected' => true ) );
        }

        wp_send_json_success( array(
            'current' => self::formatValue( get_option( $option, 'not-exists' ) ),
            'default' => $this->list[ $option ]['default'],
            'protected' => false,
        ) );
    }

    /**
     * Set option
     *
     * @return void
     */
    public function setOption()
    {
        $this->getList();

        $option = trim( self::parameter( 'option' ) );
        if ( ! isset( $this->list[ $option ] ) ) {
            wp_send_json_error();
        }

        $raw_value = (string) self::parameter( 'value' );
        if ( is_array( $this->defaults[ $option ] ) ) {
            // Arrays are passed as JSON. Raw input is never unserialized, that would allow object injection.
            $value = json_decode( $raw_value, true );
            if ( ! is_array( $value ) ) {
                wp_send_json_error( array( 'message' => 'The value of this option must be a valid JSON array' ) );
            }
        } else {
            $value = $raw_value;
        }

        $this->updateOption( $option, $value );

        wp_send_json_success();
    }

    /**
     * Write the option and leave an audit record.
     *
     * @param string $option
     * @param mixed  $value
     */
    private function updateOption( $option, $value )
    {
        update_option( $option, $value );

        if ( strncmp( $option, 'bookly_l10n_', 12 ) === 0 ) {
            do_action( 'wpml_register_single_string', 'bookly', $option, $value );
        }

        Lib\Utils\Log::put( Lib\Utils\Log::ACTION_UPDATE, $option, null, self::formatValue( $value ), null, 'Advanced options' );
    }

    private function getErrorsList()
    {
        return array_filter( $this->list, static function( $val ) { return isset( $val['incorrect'] ) && $val['incorrect']; } );
    }

    /**
     * Check whether the option value must be hidden from the current user.
     *
     * @param string $option
     * @return bool
     */
    private static function isProtected( $option )
    {
        return in_array( $option, self::getSensitiveOptions(), true ) && ! current_user_can( 'manage_options' );
    }

    /**
     * Cast an option value to the text shown in the UI and accepted back from it.
     *
     * @param mixed $value
     * @return string
     */
    private static function formatValue( $value )
    {
        if ( is_array( $value ) || is_object( $value ) ) {
            return json_encode( $value );
        }

        return (string) $value;
    }

    private function isJson( $string )
    {
        $json = json_decode( $string );

        return is_array( $json ) && json_last_error() === JSON_ERROR_NONE;
    }
}
