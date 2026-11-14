<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\State;

/**
 * set_cloud_account — the weekly summary and the cap on Auto-Recharges of the Bookly Cloud account
 * (assets/ai-setup/actions/set_cloud_account.yaml). Both live in Cloud: one request each, checked
 * for Cloud's answer (the page ignores it for the weekly summary).
 */
class SetCloudAccount implements ActionInterface
{
    /**
     * Current values: from the state when an earlier item changed them, else from Cloud.
     *
     * @param State $state
     * @return array array( 'weekly_summary' => bool, 'limit' => array, 'auto_recharge' => bool )
     */
    protected static function current( State $state )
    {
        if ( $state->has( 'cloud:account' ) ) {
            return $state->get( 'cloud:account' );
        }
        $account = CloudAccount::api()->account;
        $current = array(
            'weekly_summary' => (bool) $account->getNotifySummary(),
            'limit'          => CloudAccount::limit(),
            'auto_recharge'  => (bool) $account->autoRechargeEnabled(),
        );
        $state->set( 'cloud:account', $current );

        return $current;
    }

    /**
     * The cap after the item: the given fields over the current cap.
     *
     * @param array $limit current
     * @param array $given
     * @return array
     */
    protected static function newLimit( array $limit, array $given )
    {
        $enabled = array_key_exists( 'enabled', $given ) ? (bool) $given['enabled'] : $limit['enabled'];

        return array(
            'enabled'   => $enabled,
            'max_count' => isset( $given['max_count'] ) ? (int) $given['max_count'] : $limit['max_count'],
            'interval'  => isset( $given['interval'] ) ? (int) $given['interval'] : $limit['interval'],
        );
    }

    /**
     * @inheritDoc
     */
    public function validate( array $args, State $state )
    {
        if ( ! Access::allowed( array( 'admin' ) ) ) {
            return array( 'forbidden: only a Bookly administrator can change the Bookly Cloud account' );
        }
        if ( ! CloudAccount::load() ) {
            return array( 'not_signed_in: the site is not signed in to Bookly Cloud — the owner signs in on any Bookly Cloud page first' );
        }
        foreach ( array_keys( $args ) as $key ) {
            if ( ! in_array( $key, array( 'weekly_summary', 'auto_recharge_limit' ), true ) ) {
                return array( 'arguments: unknown argument ' . $key . ' — the action takes weekly_summary, auto_recharge_limit' );
            }
        }
        $has_summary = array_key_exists( 'weekly_summary', $args );
        $has_limit   = isset( $args['auto_recharge_limit'] ) && is_array( $args['auto_recharge_limit'] ) && $args['auto_recharge_limit'];
        if ( ! $has_summary && ! $has_limit ) {
            return array( 'nothing_given: give weekly_summary or auto_recharge_limit with at least one field' );
        }
        $current = self::current( $state );
        $errors  = array();
        $changed = false;
        $after   = $current;

        if ( $has_summary ) {
            if ( ! is_bool( $args['weekly_summary'] ) ) {
                $errors[] = 'arguments: weekly_summary is true or false';
            } elseif ( $args['weekly_summary'] !== $current['weekly_summary'] ) {
                $after['weekly_summary'] = $args['weekly_summary'];
                $changed = true;
            }
        }
        if ( $has_limit ) {
            $given = $args['auto_recharge_limit'];
            foreach ( array_keys( $given ) as $key ) {
                if ( ! in_array( $key, array( 'enabled', 'max_count', 'interval' ), true ) ) {
                    $errors[] = 'arguments: unknown field auto_recharge_limit.' . $key . ' — it takes enabled, max_count, interval';
                }
            }
            if ( ! $current['auto_recharge'] ) {
                $errors[] = 'limit_needs_auto_recharge: Auto-Recharge is off, so there is no cap to set — the owner turns Auto-Recharge on (Bookly Cloud → Account → Auto-Recharge) first';
            } elseif ( ! $errors ) {
                $enabled = array_key_exists( 'enabled', $given ) ? $given['enabled'] : $current['limit']['enabled'];
                if ( ! is_bool( $enabled ) ) {
                    $errors[] = 'arguments: auto_recharge_limit.enabled is true or false';
                } elseif ( ! $enabled ) {
                    if ( isset( $given['max_count'] ) || isset( $given['interval'] ) ) {
                        $errors[] = 'limit_values_when_off: with no cap (enabled false) do not give max_count or interval';
                    }
                } else {
                    if ( ! $current['limit']['enabled'] && ( ! isset( $given['max_count'] ) || ! isset( $given['interval'] ) ) ) {
                        $errors[] = 'limit_values_required: turning the cap on needs both max_count and interval';
                    }
                    if ( isset( $given['max_count'] ) && ( ! is_int( $given['max_count'] ) || $given['max_count'] < 1 ) ) {
                        $errors[] = 'max_count_positive: max_count is a whole number, at least 1';
                    }
                    if ( isset( $given['interval'] ) && ( ! is_int( $given['interval'] ) || ! in_array( $given['interval'], CloudAccount::intervals(), true ) ) ) {
                        $errors[] = 'interval_from_list: interval is hours, one of ' . implode( ', ', CloudAccount::intervals() );
                    }
                }
                if ( ! $errors ) {
                    $limit = self::newLimit( $current['limit'], $given );
                    $same  = $limit['enabled'] === $current['limit']['enabled']
                        && ( ! $limit['enabled'] || ( $limit['max_count'] === $current['limit']['max_count'] && $limit['interval'] === $current['limit']['interval'] ) );
                    if ( ! $same ) {
                        $after['limit'] = $limit;
                        $changed = true;
                    }
                }
            }
        }
        if ( ! $errors && ! $changed ) {
            $errors[] = 'nothing_to_change: the given values are the current ones';
        }
        if ( ! $errors ) {
            $state->set( 'cloud:account', $after );
        }

        return $errors;
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $current  = self::current( $state );
        $rows     = array();
        $warnings = array();
        $after    = $current;
        if ( array_key_exists( 'weekly_summary', $args ) && $args['weekly_summary'] !== $current['weekly_summary'] ) {
            $after['weekly_summary'] = $args['weekly_summary'];
            $rows[] = array(
                'label'  => __( 'Send weekly summary', 'bookly-responsive-appointment-booking-tool' ),
                'before' => CloudAccount::onOff( $current['weekly_summary'] ),
                'after'  => CloudAccount::onOff( $args['weekly_summary'] ),
            );
            if ( $args['weekly_summary'] ) {
                $warnings[] = sprintf( __( 'The summary goes to the account email %s once a week, while at least one Cloud product is on.', 'bookly-responsive-appointment-booking-tool' ), CloudAccount::api()->account->getUserName() );
            }
        }
        if ( ! empty( $args['auto_recharge_limit'] ) ) {
            $limit = self::newLimit( $current['limit'], $args['auto_recharge_limit'] );
            $after['limit'] = $limit;
            $rows[] = array(
                'label'  => __( 'Limit the number of Auto-Recharges', 'bookly-responsive-appointment-booking-tool' ),
                'before' => CloudAccount::limitText( $current['limit'] ),
                'after'  => CloudAccount::limitText( $limit ),
            );
            if ( $limit['enabled'] ) {
                $warnings[] = __( 'Once the limit is reached, further Auto-Recharges for SMS and Voice are skipped until the period passes, so the balance may run out; plan renewals still top up.', 'bookly-responsive-appointment-booking-tool' );
            }
        }
        $state->set( 'cloud:account', $after );

        return array( 'rows' => $rows, 'warnings' => $warnings );
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        CloudAccount::load();
        $account  = CloudAccount::api()->account;
        $snapshot = array( 'weekly_summary' => null, 'limit' => null, 'request' => CloudAccount::request() );

        if ( array_key_exists( 'weekly_summary', $args ) ) {
            $before = (bool) $account->getNotifySummary();
            if ( $args['weekly_summary'] !== $before ) {
                $ok = $args['weekly_summary'] ? $account->enableSendingWeeklySummary() : $account->disableSendingWeeklySummary();
                if ( ! $ok ) {
                    return array( 'error' => 'cloud_rejected: Bookly Cloud did not change the weekly summary' );
                }
                $snapshot['weekly_summary'] = array( 'before' => $before, 'after' => $args['weekly_summary'] );
            }
        }
        if ( ! empty( $args['auto_recharge_limit'] ) ) {
            $before = CloudAccount::limit();
            $limit  = self::newLimit( $before, $args['auto_recharge_limit'] );
            if ( ! $account->setAutoRechargeLimit( $limit['max_count'], $limit['enabled'] ? $limit['interval'] : 0 ) ) {
                $this->undo( $snapshot );

                return array( 'error' => 'cloud_rejected: Bookly Cloud did not change the Auto-Recharge limit' );
            }
            $snapshot['limit'] = array( 'before' => $before, 'after' => $limit );
        }

        return $snapshot;
    }

    /**
     * Restore what is still as the assistant left it.
     *
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        if ( ! CloudAccount::load() ) {
            return;
        }
        $account = CloudAccount::api()->account;
        $same    = CloudAccount::sameRequest( $snapshot );
        if ( ! empty( $snapshot['limit'] ) && $account->autoRechargeEnabled() && ( $same || CloudAccount::limit() == $snapshot['limit']['after'] ) ) {
            $before = $snapshot['limit']['before'];
            $account->setAutoRechargeLimit( $before['max_count'], $before['enabled'] ? $before['interval'] : 0 );
        }
        if ( ! empty( $snapshot['weekly_summary'] ) && ( $same || (bool) $account->getNotifySummary() === $snapshot['weekly_summary']['after'] ) ) {
            $snapshot['weekly_summary']['before'] ? $account->enableSendingWeeklySummary() : $account->disableSendingWeeklySummary();
        }
    }
}
