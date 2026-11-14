<?php
namespace Bookly\Backend\Components\Cloud\Account;

use Bookly\Lib;

class Ajax extends Lib\Base\Ajax
{
    /**
     * Forgot password.
     */
    public static function forgotPassword()
    {
        $cloud    = Lib\Cloud\API::getInstance();
        $step     = self::parameter( 'step' );
        $code     = self::parameter( 'code' );
        $username = self::parameter( 'username' );
        $password = self::parameter( 'password' );
        $result   = $cloud->account->forgotPassword( $username, $step, $code, $password );
        if ( $result === false ) {
            $errors = $cloud->getErrors();
            wp_send_json_error( array( 'code' => key( $errors ), 'message' => current( $errors ) ) );
        } else {
            wp_send_json_success();
        }
    }

    /**
     * Login.
     */
    public static function cloudLogin()
    {
        $cloud  = Lib\Cloud\API::getInstance();
        $result = $cloud->account->login( self::parameter( 'username' ), self::parameter( 'password' ) );
        if ( $result ) {
            wp_send_json_success();
        }

        wp_send_json_error( array( 'message' => current( $cloud->getErrors() ) ) );
    }

    /**
     * Registration.
     */
    public static function cloudRegister()
    {
        $cloud = Lib\Cloud\API::getInstance();

        if ( self::parameter( 'accept_tos', false ) ) {
            $response = $cloud->account->register(
                self::parameter( 'username' ),
                self::parameter( 'password' ),
                self::parameter( 'password_repeat' ),
                self::parameter( 'country' ),
                self::parameter( 'source' )
            );
            if ( $response ) {
                update_option( 'bookly_cloud_token', $response['token'] );

                wp_send_json_success();
            }
        } else {
            wp_send_json_error( array( 'message' => __( 'Please accept terms and conditions.', 'bookly-responsive-appointment-booking-tool' ) ) );
        }

        wp_send_json_error( array( 'message' => current( $cloud->getErrors() ) ) );
    }

    /**
     * Registration without password — credentials are generated
     * on the server and emailed to the user.
     */
    public static function cloudRegisterNoPassword()
    {
        $cloud = Lib\Cloud\API::getInstance();

        if ( self::parameter( 'accept_tos', false ) ) {
            // Wizard variant travels server-side from the sticky option (JS doesn't know it)
            $setup = get_option( 'bookly_setup_wizard' );
            $response = $cloud->account->registerNoPassword(
                self::parameter( 'username' ),
                self::parameter( 'country' ),
                self::parameter( 'source' ),
                is_array( $setup ) && isset( $setup['variant'] ) ? $setup['variant'] : null
            );
            if ( $response ) {
                update_option( 'bookly_cloud_token', $response['token'] );

                wp_send_json_success();
            }
            $errors = $cloud->getErrors();
            wp_send_json_error( array( 'code' => key( $errors ), 'message' => current( $errors ) ) );
        }

        wp_send_json_error( array( 'message' => __( 'Please accept terms and conditions.', 'bookly-responsive-appointment-booking-tool' ) ) );
    }

    /**
     * Send one-time sign-in code.
     */
    public static function cloudSendOtp()
    {
        $cloud = Lib\Cloud\API::getInstance();
        $result = $cloud->account->sendOtp( self::parameter( 'username' ) );
        if ( $result === false ) {
            $errors = $cloud->getErrors();
            wp_send_json_error( array( 'code' => key( $errors ), 'message' => current( $errors ) ) );
        }

        wp_send_json_success();
    }

    /**
     * Login with one-time code.
     */
    public static function cloudLoginOtp()
    {
        $cloud = Lib\Cloud\API::getInstance();
        $result = $cloud->account->loginOtp( self::parameter( 'username' ), self::parameter( 'otp' ) );
        if ( $result === false ) {
            $errors = $cloud->getErrors();
            wp_send_json_error( array( 'code' => key( $errors ), 'message' => current( $errors ) ) );
        }

        wp_send_json_success();
    }

    /**
     * Logout.
     */
    public static function cloudLogout()
    {
        Lib\Cloud\API::getInstance()->account->logout();

        wp_send_json_success();
    }

    /**
     * Apply confirmation code.
     */
    public static function applyConfirmationCode()
    {
        $code = self::parameter( 'code' );

        $result = Lib\Cloud\API::getInstance()->account->confirmEmail( $code );
        if ( $result === false ) {
            wp_send_json_error( array( 'message' => current( Lib\Cloud\API::getInstance()->getErrors() ) ) );
        } else {
            wp_send_json( $result );
        }
    }

    /**
     * Resend confirmation code.
     */
    public static function resendConfirmationCode()
    {
        $result = Lib\Cloud\API::getInstance()->account->resendConfirmation();
        if ( $result === false ) {
            wp_send_json_error( array( 'message' => current( Lib\Cloud\API::getInstance()->getErrors() ) ) );
        } else {
            wp_send_json( $result );
        }
    }
}