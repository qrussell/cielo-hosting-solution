<?php
/**
 * SkyHS Subscription Cron
 *
 * Handles automated renewal processing via WordPress cron.
 *
 * How it works:
 *   1. Daily cron finds all active subscriptions with next_payment_date <= now.
 *   2. For each, it creates a WooCommerce renewal order (pending payment).
 *   3. It fires the dynamic hook:
 *        do_action( 'woocommerce_scheduled_subscription_payment_' . $payment_method, $amount, $renewal_order );
 *      — the standard WooCommerce subscription payment hook. Any installed WooCommerce
 *        payment gateway plugin (Stripe Gateway, PayPal, etc.) will automatically
 *        handle the charge.
 *   4. When the gateway marks the order complete, our order-complete listener
 *      advances the next_payment_date and fires skyhshoso_subscription_renewed.
 *
 * Additional daily jobs:
 *   - Suspend subscriptions with 3+ consecutive failed payments.
 *   - Send renewal reminder emails 3 days before next payment.
 *   - Expire pending-cancel subscriptions past their end date.
 *
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

class SkyHSHOSO_Subscription_Cron {

    private static $instance = null;

    /** How many consecutive failures before suspension. */
    const FAILED_PAYMENT_THRESHOLD = 3; // Default, overridden by settings.

    /** Days before renewal to send a reminder. */
    const REMINDER_DAYS = 3; // Default, overridden by settings.

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Register cron schedules.
        add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );

        // Register cron event handlers.
        add_action( 'skyhshoso_process_renewals',      array( $this, 'process_renewals' ) );
        add_action( 'skyhshoso_check_failed_payments', array( $this, 'check_failed_payments' ) );
        add_action( 'skyhshoso_send_reminders',        array( $this, 'send_renewal_reminders' ) );
        add_action( 'skyhshoso_expire_subscriptions',  array( $this, 'expire_subscriptions' ) );
        add_action( 'skyhshoso_process_terminations',  array( $this, 'process_terminations' ) );
        add_action( 'skyhshoso_send_deletion_warnings', array( $this, 'send_final_deletion_reminders' ) );

        // Listen for WooCommerce order completion to advance renewal dates.
        add_action( 'woocommerce_order_status_completed',  array( $this, 'handle_renewal_order_complete' ), 10, 1 );
        add_action( 'woocommerce_order_status_processing', array( $this, 'handle_renewal_order_complete' ), 10, 1 );
        add_action( 'woocommerce_payment_complete',        array( $this, 'handle_renewal_order_complete' ), 10, 1 );

        // Listen for failed payments to increment counter.
        add_action( 'woocommerce_order_status_failed', array( $this, 'handle_renewal_order_failed' ), 10, 1 );
    }

    // -------------------------------------------------------------------------
    // Cron scheduling
    // -------------------------------------------------------------------------

    /**
     * Register custom WP-Cron intervals.
     */
    public function add_cron_schedules( $schedules ) {
        if ( ! isset( $schedules['skyhshoso_daily'] ) ) {
            $schedules['skyhshoso_daily'] = array(
                'interval' => DAY_IN_SECONDS,
                'display'  => __( 'SkyHS Daily', 'skyhs-hosting-solution' ),
            );
        }
        return $schedules;
    }

    /**
     * Schedule all cron events — called on plugin activation.
     */
    public static function schedule_events() {
        $events = array(
            'skyhshoso_process_renewals'      => 'skyhshoso_daily',
            'skyhshoso_check_failed_payments' => 'skyhshoso_daily',
            'skyhshoso_send_reminders'        => 'skyhshoso_daily',
            'skyhshoso_expire_subscriptions'  => 'skyhshoso_daily',
            'skyhshoso_process_terminations' => 'skyhshoso_daily',
            'skyhshoso_send_deletion_warnings'=> 'skyhshoso_daily',
        );
        foreach ( $events as $hook => $recurrence ) {
            if ( ! wp_next_scheduled( $hook ) ) {
                wp_schedule_event( time(), $recurrence, $hook );
            }
        }
    }

    /**
     * Unschedule all cron events — called on plugin deactivation.
     */
    public static function unschedule_events() {
        $hooks = array(
            'skyhshoso_process_renewals',
            'skyhshoso_check_failed_payments',
            'skyhshoso_send_reminders',
            'skyhshoso_expire_subscriptions',
            'skyhshoso_process_terminations',
            'skyhshoso_send_deletion_warnings',
        );
        foreach ( $hooks as $hook ) {
            $timestamp = wp_next_scheduled( $hook );
            if ( $timestamp ) {
                wp_unschedule_event( $timestamp, $hook );
            }
        }
    }

    // -------------------------------------------------------------------------
    // Renewal processing
    // -------------------------------------------------------------------------

    /**
     * Find all active subscriptions due for renewal and trigger payment.
     * Runs daily via WP-Cron.
     */
    public function process_renewals() {
        $due = SkyHSHOSO_Subscription_DB::get_due_renewals();
        $count = count( $due );

        SkyHSHOSO_Activity_Log::log( 'cron', sprintf( 'Process renewals: %d subscription(s) due.', $count ), 'info' );

        foreach ( $due as $row ) {
            $subscription = new SkyHSHOSO_Subscription( $row );

            // Pending-cancel subscriptions: don't renew, just cancel them.
            if ( $row->status === 'pending-cancel' ) {
                $subscription->update_status( 'cancelled', __( 'Subscription cancelled at end of billing period.', 'skyhs-hosting-solution' ) );
                SkyHSHOSO_Activity_Log::log( 'cancelled', sprintf( 'Subscription #%d cancelled (pending-cancel reached end of period). Termination scheduled.', $row->id ), 'info', $row->id, 0, $row->user_id );
                continue;
            }

            // Skip free subscriptions — just advance the date.
            if ( $row->amount <= 0 ) {
                $subscription->advance_next_payment_date();
                do_action( 'skyhshoso_subscription_renewed', $subscription, null );
                SkyHSHOSO_Activity_Log::log( 'renewal', sprintf( 'Free subscription #%d renewed (date advanced).', $row->id ), 'success', $row->id, 0, $row->user_id );
                continue;
            }

			// Create a WooCommerce renewal order.
			$renewal_order = $this->create_renewal_order( $subscription );
			if ( ! $renewal_order ) {
				SkyHSHOSO_Activity_Log::log( 'renewal', sprintf( 'Failed to create renewal order for subscription #%d.', $row->id ), 'error', $row->id, 0, $row->user_id );
				SkyHSHOSO_Logger::error( 'Subscription renewal failed: could not create renewal order for subscription #' . $row->id, array( 'source' => 'subscription_cron' ) );
				continue;
			}

            // Store reference so we can match on completion.
            $renewal_order->update_meta_data( '_skyhshoso_renewal_subscription_id', $subscription->get_id() );
            $renewal_order->save();

            SkyHSHOSO_Activity_Log::log( 'renewal', sprintf( 'Renewal order #%d created for subscription #%d (amount: %s).', $renewal_order->get_id(), $row->id, wc_price( $row->amount ) ), 'info', $row->id, $renewal_order->get_id(), $row->user_id );

            // Fire the standard WooCommerce subscription payment hook — the installed
            // payment gateway plugin (Stripe Gateway, PayPal, etc.) will process the charge.
            $payment_method = $row->payment_method;
            if ( $subscription->is_manual() ) {
                // For manual renewals (like COD), put subscription on-hold pending payment.
                $subscription->update_status( 'on-hold', __( 'Subscription put on-hold pending manual renewal payment.', 'skyhs-hosting-solution' ) );
                SkyHSHOSO_Activity_Log::log( 'renewal', sprintf( 'Manual renewal for subscription #%d placed on-hold.', $row->id ), 'info', $row->id, $renewal_order->get_id(), $row->user_id );

                // Send the renewal invoice email to customer.
                $emails = WC()->mailer()->get_emails();
                if ( isset( $emails['WC_Email_Customer_Invoice'] ) ) {
                    $emails['WC_Email_Customer_Invoice']->trigger( $renewal_order->get_id() );
                }
            } elseif ( $payment_method && $renewal_order->needs_payment() ) {
                WC()->payment_gateways(); // Ensure gateways are loaded.
                do_action(
                    'woocommerce_scheduled_subscription_payment_' . $payment_method,
                    $renewal_order->get_total(),
                    $renewal_order
                );
                SkyHSHOSO_Activity_Log::log( 'renewal', sprintf( 'Payment triggered via %s for subscription #%d (order #%d).', $payment_method, $row->id, $renewal_order->get_id() ), 'info', $row->id, $renewal_order->get_id(), $row->user_id );
            }
        }

        SkyHSHOSO_Activity_Log::log( 'cron', sprintf( 'Process renewals complete: %d subscription(s) processed.', $count ), 'success' );
    }

    /**
     * Create a WooCommerce order for a renewal charge.
     *
     * @param SkyHSHOSO_Subscription $subscription
     * @return WC_Order|false
     */
    protected function create_renewal_order( $subscription ) {
        try {
            $order = wc_create_order( array(
                'customer_id'   => $subscription->get_customer_id(),
                'created_via'   => 'skyhshoso_renewal',
                'status'        => 'pending',
            ) );

            if ( is_wp_error( $order ) ) {
                return false;
            }

            // Add a line item reflecting the subscription amount.
            $product = wc_get_product( SkyHSHOSO_Subscription_DB::get( $subscription->get_id() )->product_id );
            if ( $product ) {
                $order->add_product( $product, 1, array(
                    'subtotal' => $subscription->get_total(),
                    'total'    => $subscription->get_total(),
                ) );
            } else {
                // Fallback: fee line.
                $item = new WC_Order_Item_Fee();
                $item->set_name( __( 'Subscription Renewal', 'skyhs-hosting-solution' ) );
                $item->set_total( $subscription->get_total() );
                $order->add_item( $item );
            }

            // Copy billing address from customer.
            $user = get_userdata( $subscription->get_customer_id() );
            if ( $user ) {
                $fields = array( 'first_name','last_name','company','address_1','address_2','city','state','postcode','country','email','phone' );
                foreach ( $fields as $field ) {
                    $value = get_user_meta( $user->ID, 'billing_' . $field, true );
                    if ( $value ) {
                        $order->{'set_billing_' . $field}( $value );
                    }
                }
            }

            $order->set_payment_method( $subscription->get_payment_method() );
            $order->calculate_totals();
            $order->save();

            return $order;

        } catch ( Exception $e ) {
            return false;
        }
    }

    /**
     * When a renewal WooCommerce order is marked complete/paid, advance the
     * subscription's next_payment_date and fire the renewal hook.
     *
     * @param int $order_id
     */
    public function handle_renewal_order_complete( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $sub_id = $order->get_meta( '_skyhshoso_renewal_subscription_id' );
        if ( ! $sub_id ) {
            return;
        }

		// Prevent double-processing.
		if ( $order->get_meta( '_skyhshoso_renewal_processed' ) ) {
			return;
		}

		$subscription = skyhshoso_get_subscription( $sub_id );
		if ( ! $subscription ) {
			SkyHSHOSO_Activity_Log::log( 'renewal', sprintf( 'Order #%d completed but subscription #%d not found.', $order_id, $sub_id ), 'error', $sub_id, $order_id );
			SkyHSHOSO_Logger::error( 'Subscription renewal failed: subscription #' . $sub_id . ' not found for completed order #' . $order_id, array( 'source' => 'subscription_cron' ) );
			return;
		}

        $subscription = skyhshoso_get_subscription( (int) $sub_id );
        if ( ! $subscription ) {
            SkyHSHOSO_Activity_Log::log( 'renewal', sprintf( 'Order #%d completed but subscription #%d not found.', $order_id, $sub_id ), 'error', $sub_id, $order_id );
            return;
        }

        // Advance the billing date.
        $subscription->advance_next_payment_date();

        // Reset failure counter.
        SkyHSHOSO_Subscription_DB::update( (int) $sub_id, array( 'failed_payment_count' => 0 ) );

        // If subscription is on-hold (e.g. manual renewal), reactivate it.
        if ( $subscription->has_status( 'on-hold' ) ) {
            $subscription->update_status( 'active', __( 'Subscription reactivated upon renewal payment.', 'skyhs-hosting-solution' ) );
        }

        $order->update_meta_data( '_skyhshoso_renewal_processed', true );
        $order->save();

        SkyHSHOSO_Activity_Log::log( 'renewal', sprintf( 'Renewal payment succeeded for subscription #%d (order #%d).', $sub_id, $order_id ), 'success', $sub_id, $order_id, $subscription->get_customer_id() );

        /**
         * Fires when a subscription renewal payment succeeds.
         *
         * @param SkyHSHOSO_Subscription $subscription
         * @param WC_Order               $renewal_order
         */
        do_action( 'skyhshoso_subscription_renewed', $subscription, $order );
    }

    /**
     * When a renewal order fails, increment the failure counter.
     *
     * @param int $order_id
     */
    public function handle_renewal_order_failed( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $sub_id = $order->get_meta( '_skyhshoso_renewal_subscription_id' );
        if ( ! $sub_id ) {
            return;
        }

        $row = SkyHSHOSO_Subscription_DB::get( (int) $sub_id );
        if ( ! $row ) {
            SkyHSHOSO_Activity_Log::log( 'renewal', sprintf( 'Order #%d failed but subscription #%d not found.', $order_id, $sub_id ), 'error', $sub_id, $order_id );
            return;
        }

        $new_count = (int) $row->failed_payment_count + 1;
        SkyHSHOSO_Subscription_DB::update( (int) $sub_id, array( 'failed_payment_count' => $new_count ) );

        SkyHSHOSO_Activity_Log::log( 'renewal', sprintf( 'Renewal payment failed for subscription #%d (order #%d). Failure count: %d.', $sub_id, $order_id, $new_count ), 'error', $sub_id, $order_id, $row->user_id );

        // Fire hook for retry system.
        $subscription = new SkyHSHOSO_Subscription( $row );
        do_action( 'woocommerce_subscription_renewal_payment_failed', $subscription, $order );
    }

    // -------------------------------------------------------------------------
    // Failed payment suspension
    // -------------------------------------------------------------------------

    /**
     * Suspend subscriptions that have hit the failure threshold.
     * Runs daily.
     */
    public function check_failed_payments() {
        global $wpdb;
        $table = $wpdb->prefix . SkyHSHOSO_Subscription_DB::TABLE;
        $threshold = class_exists( 'SkyHSHOSO_Settings' ) ? SkyHSHOSO_Settings::get_failed_payment_threshold() : self::FAILED_PAYMENT_THRESHOLD;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = 'active' AND failed_payment_count >= %d",
                $threshold
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

        SkyHSHOSO_Activity_Log::log( 'cron', sprintf( 'Check failed payments: threshold=%d, found %d subscription(s).', $threshold, count( $rows ) ), count( $rows ) > 0 ? 'warning' : 'info' );

        foreach ( $rows as $row ) {
            $subscription = new SkyHSHOSO_Subscription( $row );
            $subscription->update_status( 'on-hold', __( 'Suspended due to repeated payment failures.', 'skyhs-hosting-solution' ) );

            SkyHSHOSO_Activity_Log::log( 'suspension', sprintf( 'Subscription #%d suspended (%d failed payments).', $row->id, $row->failed_payment_count ), 'warning', $row->id, 0, $row->user_id );

            if ( class_exists( 'SkyHSHOSO_Emails' ) ) {
                SkyHSHOSO_Emails::send_suspension( $subscription );
            }

            // Schedule termination after grace period.
            $grace_days   = class_exists( 'SkyHSHOSO_Settings' ) ? SkyHSHOSO_Settings::get_grace_period_days() : 30;
            $base_time    = $row->next_payment_date ? strtotime( $row->next_payment_date ) : time();
            $terminate_on = gmdate( 'Y-m-d H:i:s', strtotime( '+' . $grace_days . ' days', $base_time ) );
            SkyHSHOSO_Subscription_DB::update_meta( (int) $row->id, '_skyhshoso_terminate_after', $terminate_on );
        }
    }

    // -------------------------------------------------------------------------
    // Renewal reminders
    // -------------------------------------------------------------------------

    /**
     * Email customers REMINDER_DAYS days before their next renewal.
     * Runs daily.
     */
    public function send_renewal_reminders() {
        global $wpdb;
        $table     = $wpdb->prefix . SkyHSHOSO_Subscription_DB::TABLE;
        $reminder_days = class_exists( 'SkyHSHOSO_Settings' ) ? SkyHSHOSO_Settings::get_reminder_days() : self::REMINDER_DAYS;
        $remind_on     = gmdate( 'Y-m-d H:i:s', strtotime( '+' . $reminder_days . ' days' ) );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = 'active' AND next_payment_date <= %s AND next_payment_date > %s",
                $remind_on,
                gmdate( 'Y-m-d H:i:s' )
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

        SkyHSHOSO_Activity_Log::log( 'cron', sprintf( 'Send reminders: %d subscription(s) in reminder window (%d days).', count( $rows ), $reminder_days ), 'info' );

        $sent_count = 0;
        foreach ( $rows as $row ) {
            // Skip if we already sent a reminder for this cycle.
            $last_reminder = SkyHSHOSO_Subscription_DB::get_meta( (int) $row->id, '_last_reminder_sent', true );
            if ( $last_reminder && strtotime( $last_reminder ) >= strtotime( $row->next_payment_date ) - DAY_IN_SECONDS ) {
                continue;
            }

            $user = get_userdata( (int) $row->user_id );
            if ( ! $user ) {
                continue;
            }

            $renewal_date = date_i18n( get_option( 'date_format' ), strtotime( $row->next_payment_date ) );
            $amount       = wc_price( $row->amount, array( 'currency' => $row->currency ) );

            $subject = sprintf(
                /* translators: %s: site name */
                __( 'Your subscription renews soon — %s', 'skyhs-hosting-solution' ),
                get_bloginfo( 'name' )
            );
            $message = sprintf(
                /* translators: 1: display name 2: renewal date 3: amount */
                __( "Hi %1\$s,\n\nThis is a friendly reminder that your subscription renews on %2\$s for %3\$s.\n\nIf you have any questions, please contact us.\n\nThank you!", 'skyhs-hosting-solution' ),
                $user->display_name,
                $renewal_date,
                wp_strip_all_tags( $amount )
            );

            $sent = wp_mail( $user->user_email, $subject, $message );

            SkyHSHOSO_Activity_Log::log( 'reminder', sprintf( 'Reminder %s for subscription #%d to %s (renews %s, amount %s).', $sent ? 'sent' : 'FAILED', $row->id, $user->user_email, $renewal_date, wp_strip_all_tags( $amount ) ), $sent ? 'success' : 'error', $row->id, 0, $row->user_id );

            if ( $sent ) {
                $sent_count++;
            }

            SkyHSHOSO_Subscription_DB::update_meta( (int) $row->id, '_last_reminder_sent', gmdate( 'Y-m-d H:i:s' ) );
        }

        SkyHSHOSO_Activity_Log::log( 'cron', sprintf( 'Send reminders complete: %d reminder(s) sent.', $sent_count ), 'success' );
    }

    /**
     * Send final deletion warnings for subscriptions nearing their termination date.
     * Runs daily.
     */
    public function send_final_deletion_reminders() {
        global $wpdb;
        $meta_table = $wpdb->prefix . SkyHSHOSO_Subscription_DB::META_TABLE;
        $sub_table  = $wpdb->prefix . SkyHSHOSO_Subscription_DB::TABLE;
        
        $warning_days = class_exists( 'SkyHSHOSO_Settings' ) ? SkyHSHOSO_Settings::get_deletion_reminder_days() : 3;
        
        // If warning_days is 0, we don't send any warning.
        if ( $warning_days <= 0 ) {
            return;
        }

        $now_time      = time();
        $warning_time  = strtotime( '+' . $warning_days . ' days', $now_time );
        $now_date      = gmdate( 'Y-m-d H:i:s', $now_time );
        $warning_date  = gmdate( 'Y-m-d H:i:s', $warning_time );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.*, sm.meta_value as terminate_after
                FROM {$sub_table} s
                INNER JOIN {$meta_table} sm ON s.id = sm.subscription_id
                WHERE sm.meta_key = '_skyhshoso_terminate_after'
                AND s.status IN ('on-hold', 'cancelled')
                AND sm.meta_value <= %s
                AND sm.meta_value > %s",
                $warning_date,
                $now_date
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

        SkyHSHOSO_Activity_Log::log( 'cron', sprintf( 'Send deletion warnings: %d subscription(s) in warning window (%d days).', count( $rows ), $warning_days ), 'info' );

        $sent_count = 0;
        foreach ( $rows as $row ) {
            $sub_id = (int) $row->id;
            
            // Check if we already sent the warning
            if ( SkyHSHOSO_Subscription_DB::get_meta( $sub_id, '_skyhshoso_deletion_warning_sent', true ) ) {
                continue;
            }

            $subscription = new SkyHSHOSO_Subscription( $row );
            
            // Calculate actual days left
            $terminate_timestamp = strtotime( $row->terminate_after );
            $diff_seconds = $terminate_timestamp - $now_time;
            $days_left = max( 1, ceil( $diff_seconds / DAY_IN_SECONDS ) );

            if ( class_exists( 'SkyHSHOSO_Emails' ) ) {
                $sent = SkyHSHOSO_Emails::send_deletion_warning( $subscription, $days_left );
                if ( $sent ) {
                    $sent_count++;
                }
            }
        }

        SkyHSHOSO_Activity_Log::log( 'cron', sprintf( 'Send deletion warnings complete: %d reminder(s) sent.', $sent_count ), 'success' );
    }

    // -------------------------------------------------------------------------
    // Expiry
    // -------------------------------------------------------------------------

    /**
     * Mark pending-cancel subscriptions as cancelled once their end date passes.
     * Runs daily.
     */
    public function expire_subscriptions() {
        global $wpdb;
        $table = $wpdb->prefix . SkyHSHOSO_Subscription_DB::TABLE;
        $now   = gmdate( 'Y-m-d H:i:s' );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = 'pending-cancel' AND end_date IS NOT NULL AND end_date <= %s",
                $now
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

        SkyHSHOSO_Activity_Log::log( 'cron', sprintf( 'Expire subscriptions: %d pending-cancel subscription(s) past end_date.', count( $rows ) ), 'info' );

        foreach ( $rows as $row ) {
            $subscription = new SkyHSHOSO_Subscription( $row );
            $subscription->update_status( 'cancelled', __( 'Subscription cancelled at end of billing period.', 'skyhs-hosting-solution' ) );

            SkyHSHOSO_Activity_Log::log( 'expiry', sprintf( 'Subscription #%d cancelled (pending-cancel period ended). Immediate termination scheduled.', $row->id ), 'info', $row->id, 0, $row->user_id );

            if ( class_exists( 'SkyHSHOSO_Emails' ) ) {
                SkyHSHOSO_Emails::send_termination_notice( $subscription );
            }

            // Schedule immediate termination (no grace period for cancellations).
            SkyHSHOSO_Subscription_DB::update_meta( (int) $row->id, '_skyhshoso_terminate_after', $now );
        }

        // Also put subscriptions past their next_payment_date on-hold.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $overdue = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = 'active' AND next_payment_date IS NOT NULL AND next_payment_date <= %s",
                $now
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

        SkyHSHOSO_Activity_Log::log( 'cron', sprintf( 'Expire subscriptions: %d active subscription(s) past next_payment_date — placing on-hold.', count( $overdue ) ), 'info' );

        foreach ( $overdue as $row ) {
            $subscription = new SkyHSHOSO_Subscription( $row );
            $subscription->update_status( 'on-hold', __( 'Subscription payment date reached. Account on hold pending renewal.', 'skyhs-hosting-solution' ) );

            SkyHSHOSO_Activity_Log::log( 'expiry', sprintf( 'Subscription #%d placed on-hold (next_payment_date reached).', $row->id ), 'warning', $row->id, 0, $row->user_id );

            if ( class_exists( 'SkyHSHOSO_Emails' ) ) {
                SkyHSHOSO_Emails::send_termination_notice( $subscription );
            }

            // Schedule termination after grace period.
            $grace_days   = class_exists( 'SkyHSHOSO_Settings' ) ? SkyHSHOSO_Settings::get_grace_period_days() : 30;
            $base_time    = $row->next_payment_date ? strtotime( $row->next_payment_date ) : time();
            $terminate_on = gmdate( 'Y-m-d H:i:s', strtotime( '+' . $grace_days . ' days', $base_time ) );
            SkyHSHOSO_Subscription_DB::update_meta( (int) $row->id, '_skyhshoso_terminate_after', $terminate_on );
        }
    }

    // -------------------------------------------------------------------------
    // Termination processing
    // -------------------------------------------------------------------------

    /**
     * Fully terminate cPanel accounts whose grace period has expired.
     * Runs daily.
     */
    public function process_terminations() {
        global $wpdb;
        $meta_table = $wpdb->prefix . SkyHSHOSO_Subscription_DB::META_TABLE;
        $now        = gmdate( 'Y-m-d H:i:s' );

        // Find all subscriptions whose termination grace period has passed.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $all_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT sm.*, s.product_id, s.user_id, s.status
                FROM {$meta_table} sm
                INNER JOIN {$wpdb->prefix}skyhshoso_subscriptions s ON s.id = sm.subscription_id
                WHERE sm.meta_key = '_skyhshoso_terminate_after'
                AND sm.meta_value <= %s",
                $now
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

        SkyHSHOSO_Activity_Log::log( 'cron', sprintf( 'Process terminations: %d subscription(s) past grace period.', count( $all_rows ) ), count( $all_rows ) > 0 ? 'warning' : 'info' );

        foreach ( $all_rows as $row ) {
            $sub_id     = (int) $row->subscription_id;
            $product_id = (int) $row->product_id;

            // Find the hosting post linked to this subscription's product.
            $hosting_posts = get_posts( array(
                'post_type'      => 'skyhshoso_hosting',
                'posts_per_page' => 1,
                'post_status'    => 'any',
                'meta_query'     => array(
                    array(
                        'key'   => '_skyhshoso_hosting_product_id',
                        'value' => $product_id,
                    ),
                ),
            ) );

            if ( empty( $hosting_posts ) ) {
                SkyHSHOSO_Activity_Log::log( 'termination', sprintf( 'No hosting post found for subscription #%d (product #%d). Removing termination marker.', $sub_id, $product_id ), 'warning', $sub_id, 0, $row->user_id );
                SkyHSHOSO_Subscription_DB::delete_meta( $sub_id, '_skyhshoso_terminate_after' );
                continue;
            }

            $hosting_post = $hosting_posts[0];
            $hosting_id   = $hosting_post->ID;
            $whm_username = get_post_meta( $hosting_id, 'skyhshoso_hosting_username', true );
            $server_id    = get_post_meta( $hosting_id, 'skyhshoso_server_id', true );

            if ( empty( $whm_username ) || empty( $server_id ) ) {
                SkyHSHOSO_Activity_Log::log( 'termination', sprintf( 'Hosting post #%d missing WHM username or server ID for subscription #%d.', $hosting_id, $sub_id ), 'error', $sub_id, 0, $row->user_id );
                SkyHSHOSO_Subscription_DB::delete_meta( $sub_id, '_skyhshoso_terminate_after' );
                continue;
            }

            // Terminate the cPanel account via WHM.
            $whm_api_user = get_post_meta( $server_id, '_skyhshoso_whm_user_id', true );
            $whm_token    = get_post_meta( $server_id, '_skyhshoso_whm_token', true );
            $whm_host     = get_post_meta( $server_id, '_skyhshoso_whm_host', true );

            if ( ! empty( $whm_api_user ) && ! empty( $whm_token ) && ! empty( $whm_host ) ) {
                $whm = new SkyHSHOSO_WHM_API( $whm_api_user, $whm_token, $whm_host );
                $whm->terminate_account( $whm_username );
                SkyHSHOSO_Activity_Log::log( 'termination', sprintf( 'WHM account "%s" terminated for subscription #%d (hosting #%d).', $whm_username, $sub_id, $hosting_id ), 'info', $sub_id, 0, $row->user_id );
            }

            // Send termination email.
            $subscription = new SkyHSHOSO_Subscription( (object) array(
                'id'       => $sub_id,
                'user_id'  => (int) $row->user_id,
                'status'   => $row->status,
                'product_id' => $product_id,
            ) );

            if ( class_exists( 'SkyHSHOSO_Emails' ) ) {
                SkyHSHOSO_Emails::send_terminated( $subscription );
            }

            // Update hosting post meta to reflect termination.
            update_post_meta( $hosting_id, 'skyhshoso_hosting_terminated', 'yes' );
            SkyHSHOSO_Activity_Log::log( 'termination', sprintf( 'Hosting #%d marked as terminated for subscription #%d.', $hosting_id, $sub_id ), 'success', $sub_id, 0, $row->user_id );

            // If the subscription was on-hold, mark it as expired (cPanel is gone).
            if ( $row->status === 'on-hold' ) {
                $sub = skyhshoso_get_subscription( $sub_id );
                if ( $sub ) {
                    $sub->update_status( 'expired', __( 'Grace period ended. Account terminated.', 'skyhs-hosting-solution' ) );
                }
            }

            // Remove the termination marker so we don't process again.
            SkyHSHOSO_Subscription_DB::delete_meta( $sub_id, '_skyhshoso_terminate_after' );
        }
    }
}

// Init.
SkyHSHOSO_Subscription_Cron::instance();
