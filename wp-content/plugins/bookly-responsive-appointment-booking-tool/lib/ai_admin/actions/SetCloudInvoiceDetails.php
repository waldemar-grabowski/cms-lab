<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\State;

/**
 * set_cloud_invoice_details — company details on the invoices of the Bookly Cloud account
 * (assets/ai-setup/actions/set_cloud_invoice_details.yaml). Cloud takes the whole set at once, so
 * the given fields go over the current ones and all nine keys are sent.
 */
class SetCloudInvoiceDetails implements ActionInterface
{
    /**
     * @param State $state
     * @return array
     */
    protected static function current( State $state )
    {
        if ( ! $state->has( 'cloud:invoice' ) ) {
            $state->set( 'cloud:invoice', CloudAccount::invoice() );
        }

        return $state->get( 'cloud:invoice' );
    }

    /**
     * Field labels as the dialog shows them.
     *
     * @return array
     */
    protected static function labels()
    {
        return array(
            'company_name'       => __( 'Company name', 'bookly-responsive-appointment-booking-tool' ),
            'company_address'    => __( 'Company address', 'bookly-responsive-appointment-booking-tool' ),
            'company_address_l2' => __( 'Company address line 2', 'bookly-responsive-appointment-booking-tool' ),
            'company_code'       => __( 'Company number', 'bookly-responsive-appointment-booking-tool' ),
            'company_vat'        => __( 'VAT / Tax number', 'bookly-responsive-appointment-booking-tool' ),
            'company_add_text'   => __( 'Additional text to include in invoice', 'bookly-responsive-appointment-booking-tool' ),
            'send'               => __( 'Send invoice', 'bookly-responsive-appointment-booking-tool' ),
            'send_copy'          => __( 'Copy invoice to another email(s)', 'bookly-responsive-appointment-booking-tool' ),
            'cc'                 => __( 'Email(s)', 'bookly-responsive-appointment-booking-tool' ),
        );
    }

    /**
     * @inheritDoc
     */
    public function validate( array $args, State $state )
    {
        if ( ! Access::allowed( array( 'admin' ) ) ) {
            return array( 'forbidden: only a Bookly administrator can change the Bookly Cloud invoice details' );
        }
        if ( ! CloudAccount::load() ) {
            return array( 'not_signed_in: the site is not signed in to Bookly Cloud — the owner signs in on any Bookly Cloud page first' );
        }
        if ( ! $args ) {
            return array( 'nothing_given: give at least one field' );
        }
        $keys   = explode( ',', CloudAccount::INVOICE_KEYS );
        $errors = array();
        foreach ( $args as $key => $value ) {
            if ( ! in_array( $key, $keys, true ) ) {
                $errors[] = 'arguments: unknown field ' . $key . ' — the action takes ' . implode( ', ', $keys );
            } elseif ( in_array( $key, array( 'send', 'send_copy' ), true ) ? ! is_bool( $value ) : ! is_string( $value ) ) {
                $errors[] = 'arguments: ' . $key . ( in_array( $key, array( 'send', 'send_copy' ), true ) ? ' is true or false' : ' is a string' );
            }
        }
        if ( $errors ) {
            return $errors;
        }
        $current = self::current( $state );
        $after   = array_merge( $current, $args );

        foreach ( array( 'company_name', 'company_address' ) as $key ) {
            if ( trim( $after[ $key ] ) === '' ) {
                $errors[] = 'name_address_required: ' . $key . ' must not be empty — without company name and address the invoice cannot be downloaded';
            }
        }
        foreach ( array( 'company_name', 'company_address', 'company_address_l2', 'company_code', 'company_vat', 'cc' ) as $key ) {
            if ( isset( $args[ $key ] ) && preg_match( '/[\r\n]/', $args[ $key ] ) ) {
                $errors[] = 'single_line: ' . $key . ' is one line';
            }
        }
        if ( array_key_exists( 'cc', $args ) && $args['cc'] !== '' && ! $after['send_copy'] ) {
            $errors[] = 'cc_needs_copy: cc is used only with send_copy true — set send_copy true in the same call';
        }
        if ( ( ! empty( $args['send_copy'] ) || ( array_key_exists( 'cc', $args ) && $after['send_copy'] ) ) && trim( $after['cc'] ) === '' ) {
            $errors[] = 'copy_needs_addresses: a copy needs at least one address in cc';
        }
        if ( trim( $after['cc'] ) !== '' && array_key_exists( 'cc', $args ) ) {
            foreach ( explode( ',', $after['cc'] ) as $email ) {
                if ( ! is_email( trim( $email ) ) ) {
                    $errors[] = 'cc_addresses: "' . trim( $email ) . '" is not an email address; cc is addresses separated by commas';
                }
            }
        }
        if ( ! $errors && $after === $current ) {
            $errors[] = 'nothing_to_change: the given values are the current ones';
        }
        if ( ! $errors ) {
            $state->set( 'cloud:invoice', $after );
        }

        return $errors;
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $current = self::current( $state );
        $after   = array_merge( $current, $args );
        $labels  = self::labels();
        $rows    = array();
        foreach ( $args as $key => $value ) {
            if ( $current[ $key ] === $value ) {
                continue;
            }
            $bool   = in_array( $key, array( 'send', 'send_copy' ), true );
            $rows[] = array(
                'label'  => $labels[ $key ],
                'before' => $bool ? CloudAccount::onOff( $current[ $key ] ) : $current[ $key ],
                'after'  => $bool ? CloudAccount::onOff( $value ) : $value,
            );
        }
        $warnings = array( __( 'The details go on invoices of top-ups paid after the change; invoices for card payments through Paddle come from Paddle.', 'bookly-responsive-appointment-booking-tool' ) );
        if ( $after['send'] && ! $current['send'] ) {
            $warnings[] = sprintf( __( 'Every invoice is emailed to the account email %s.', 'bookly-responsive-appointment-booking-tool' ), CloudAccount::api()->account->getUserName() );
        }
        if ( $after['send_copy'] && ! $after['send'] ) {
            $warnings[] = __( 'The copy goes only together with sending the invoice to the account email, which is off.', 'bookly-responsive-appointment-booking-tool' );
        }
        $state->set( 'cloud:invoice', $after );

        return array( 'rows' => $rows, 'warnings' => $warnings );
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        CloudAccount::load();
        $before = CloudAccount::invoice();
        $after  = array_merge( $before, $args );
        if ( ! CloudAccount::api()->account->setInvoiceData( self::forCloud( $after ) ) ) {
            return array( 'error' => 'cloud_rejected: Bookly Cloud did not save the invoice details' );
        }

        return array( 'before' => $before, 'after' => $after, 'request' => CloudAccount::request() );
    }

    /**
     * Restore the previous set unless the details were changed since apply().
     *
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        if ( CloudAccount::load() && ( CloudAccount::sameRequest( $snapshot ) || CloudAccount::invoice() == $snapshot['after'] ) ) {
            CloudAccount::api()->account->setInvoiceData( self::forCloud( $snapshot['before'] ) );
        }
    }

    /**
     * The set as the dialog sends it: send and send_copy as 1/0.
     *
     * @param array $invoice
     * @return array
     */
    protected static function forCloud( array $invoice )
    {
        $invoice['send']      = $invoice['send'] ? 1 : 0;
        $invoice['send_copy'] = $invoice['send_copy'] ? 1 : 0;

        return $invoice;
    }
}
