<?php
/**
 * SkyHS Subscription Object
 *
 * A lightweight subscription object that mirrors the WC_Subscription interface
 * used by the existing plugin code — so every existing call to
 *   $subscription->get_status()
 *   $subscription->get_date('next_payment')
 *   $subscription->update_status()
 *   $subscription->add_order_note()
 *   $subscription->get_meta()
 *   $subscription->update_meta_data()
 * …continues to work unchanged.
 *
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

class SkyHSHOSO_Subscription {

    /**
     * Raw DB row.
     *
     * @var object
     */
    protected $data;

    /**
     * Pending meta changes (key => value).
     *
     * @var array
     */
    protected $meta_changes = array();

    /**
     * Constructor.
     *
     * @param object $row DB row from wp_skyhshoso_subscriptions.
     */
    public function __construct( $row ) {
        $this->data = $row;
    }

    // -------------------------------------------------------------------------
    // Getters
    // -------------------------------------------------------------------------

    /** @return int */
    public function get_id() {
        return (int) $this->data->id;
    }

    /** @return string e.g. 'active', 'on-hold', 'cancelled' */
    public function get_status() {
        return $this->data->status;
    }

    /** @return int WordPress user ID */
    public function get_customer_id() {
        return (int) $this->data->user_id;
    }

    /** @return string e.g. 'month', 'year' */
    public function get_billing_period() {
        return $this->data->billing_period;
    }

    /** @return int e.g. 1, 3 */
    public function get_billing_interval() {
        return (int) $this->data->billing_interval;
    }

    /** @return string Payment method slug */
    public function get_payment_method() {
        return $this->data->payment_method;
    }

    /** @return float */
    public function get_total() {
        return (float) $this->data->amount;
    }

    /** @return int */
    public function get_order_id() {
        return (int) $this->data->order_id;
    }

    /**
     * Mirrors WC_Subscription::get_date().
     * Returns a GMT datetime string for the requested date type.
     *
     * @param string $date_type  'next_payment' | 'start' | 'end' | 'trial_end' | 'last_payment' | 'date_created'
     * @return string|null  e.g. '2025-06-13 12:00:00'
     */
    public function get_date( $date_type ) {
        $map = array(
            'next_payment' => 'next_payment_date',
            'start'        => 'start_date',
            'end'          => 'end_date',
            'trial_end'    => 'trial_end_date',
            'last_payment' => 'last_payment_date',
            'date_created' => 'created_at',
        );

        if ( 'last_order_date_created' === $date_type ) {
            $last_order = $this->get_last_order( 'all' );
            if ( $last_order ) {
                $date_created = $last_order->get_date_created();
                return $date_created ? $date_created->date( 'Y-m-d H:i:s' ) : null;
            }
            $date_type = 'date_created';
        }

        $col = isset( $map[ $date_type ] ) ? $map[ $date_type ] : null;
        return ( $col && ! empty( $this->data->$col ) ) ? $this->data->$col : null;
    }

    /**
     * Mirrors WC_Subscription::get_date_created().
     * Returns a WC_DateTime object so existing code that checks
     * `$date instanceof WC_DateTime` still works.
     *
     * @return WC_DateTime|null
     */
    public function get_date_created() {
        $value = $this->data->created_at ?? null;
        if ( empty( $value ) ) {
            return null;
        }
        try {
            return new WC_DateTime( $value, new DateTimeZone( 'UTC' ) );
        } catch ( Exception $e ) {
            return null;
        }
    }

    /**
     * Get a meta value — mirrors WC_Data::get_meta().
     *
     * @param string $key
     * @param bool   $single
     * @return mixed
     */
    public function get_meta( $key, $single = true ) {
        // Check pending changes first.
        if ( array_key_exists( $key, $this->meta_changes ) ) {
            return $this->meta_changes[ $key ];
        }
        return SkyHSHOSO_Subscription_DB::get_meta( $this->get_id(), $key, $single );
    }

    /**
     * Get a URL to view the original order (mirrors WC_Subscription::get_view_order_url()).
     *
     * @return string
     */
    public function get_view_order_url() {
        if ( $this->data->order_id ) {
            return wc_get_endpoint_url( 'view-order', $this->data->order_id, wc_get_page_permalink( 'myaccount' ) );
        }
        return wc_get_page_permalink( 'myaccount' );
    }

    // -------------------------------------------------------------------------
    // Setters / Mutators
    // -------------------------------------------------------------------------

    /**
     * Stage a meta change. Call save() to persist.
     * Mirrors WC_Data::update_meta_data().
     *
     * @param string $key
     * @param mixed  $value
     */
    public function update_meta_data( $key, $value ) {
        $this->meta_changes[ $key ] = $value;
    }

    /**
     * Complete payment on this subscription.
     */
    public function payment_complete() {
        SkyHSHOSO_Subscription_DB::update(
            $this->get_id(),
            array(
                'last_payment_date'    => gmdate( 'Y-m-d H:i:s' ),
                'failed_payment_count' => 0,
            )
        );

        $this->data->last_payment_date = gmdate( 'Y-m-d H:i:s' );
        $this->data->failed_payment_count = 0;

        if ( $this->has_status( 'on-hold' ) ) {
            $this->update_status( 'active', __( 'Subscription reactivated upon payment.', 'skyhs-hosting-solution' ) );
        }

        do_action( 'skyhshoso_subscription_renewed', $this, null );
    }

    /**
     * Update subscription status and fire lifecycle hooks.
     * Mirrors WC_Subscription::update_status().
     *
     * @param string $new_status  e.g. 'active', 'on-hold', 'cancelled'
     * @param string $note        Optional note to store.
     */
    public function update_status( $new_status, $note = '' ) {
        $old_status = $this->data->status;

        // Normalise — statuses are stored without 'wc-' prefix.
        $new_status = str_replace( 'wc-', '', $new_status );

        if ( $old_status === $new_status ) {
            return;
        }

        SkyHSHOSO_Subscription_DB::update( $this->get_id(), array( 'status' => $new_status ) );
        $this->data->status = $new_status;

        if ( $new_status === 'on-hold' ) {
            $existing_terminate = SkyHSHOSO_Subscription_DB::get_meta( $this->get_id(), '_skyhshoso_terminate_after', true );
            $grace_days         = class_exists( 'SkyHSHOSO_Settings' ) ? SkyHSHOSO_Settings::get_grace_period_days() : 30;
            $base_time          = $this->get_date( 'next_payment' ) ? strtotime( $this->get_date( 'next_payment' ) ) : time();
            $correct_terminate  = gmdate( 'Y-m-d H:i:s', strtotime( '+' . $grace_days . ' days', $base_time ) );

            if ( ! $existing_terminate || $existing_terminate !== $correct_terminate ) {
                SkyHSHOSO_Subscription_DB::update_meta( $this->get_id(), '_skyhshoso_terminate_after', $correct_terminate );
            }
        } elseif ( $new_status === 'cancelled' ) {
            SkyHSHOSO_Subscription_DB::update_meta( $this->get_id(), '_skyhshoso_terminate_after', gmdate( 'Y-m-d H:i:s' ) );
        } elseif ( in_array( $new_status, array( 'active', 'pending' ), true ) ) {
            SkyHSHOSO_Subscription_DB::delete_meta( $this->get_id(), '_skyhshoso_terminate_after' );
            SkyHSHOSO_Subscription_DB::delete_meta( $this->get_id(), '_skyhshoso_deletion_warning_sent' );
        }

        if ( $note ) {
            $this->add_order_note( $note );
        }

         /**
          * Fired whenever a SkyHS subscription status changes.
          *
          * @param SkyHSHOSO_Subscription $subscription
          * @param string $new_status
          * @param string $old_status
          */
        do_action( 'skyhshoso_subscription_status_updated', $this, $new_status, $old_status );

        // Fire the specific transition hook.
        do_action( "skyhshoso_subscription_status_{$old_status}_to_{$new_status}", $this );

        // Fire gateway-specific status hooks.
        $payment_method = $this->get_payment_method();
        if ( ! empty( $payment_method ) ) {
            switch ( $new_status ) {
                case 'active':
                    $hook_prefix = 'skyhshoso_subscription_activated_';
                    break;
                case 'on-hold':
                    $hook_prefix = 'skyhshoso_subscription_on-hold_';
                    break;
                case 'pending-cancel':
                    $hook_prefix = 'skyhshoso_subscription_pending-cancel_';
                    break;
                case 'cancelled':
                    $hook_prefix = 'skyhshoso_subscription_cancelled_';
                    break;
                case 'expired':
                    $hook_prefix = 'skyhshoso_subscription_expired_';
                    break;
                default:
                    $hook_prefix = 'skyhshoso_subscription_status_updated_';
                    break;
            }
            do_action( $hook_prefix . $payment_method, $this );
        }
    }
    public function add_item( $item ) {
        if ( is_object( $item ) ) {
            if ( method_exists( $item, 'set_order_id' ) ) {
                $item->set_order_id( $this->get_id() );
            }
            if ( method_exists( $item, 'save' ) ) {
                $item->save();
            }
        }
    }

    /**
     * Validate date updates (stub for WC_Subscription compatibility).
     *
     * @param array $dates
     * @return array
     */
    public function validate_date_updates( &$dates ) {
        return $dates;
    }


    /**
     * Add a product to the subscription (stub for WC_Subscription compatibility).
     *
     * @param WC_Product $product
     * @param int        $qty
     * @return int|false
     */
    public function add_product( $product, $qty = 1 ) {
        if ( ! is_object( $product ) ) {
            return false;
        }

        $product_id   = $product->get_id();
        $variation_id = 0;

        if ( $product->is_type( 'variation' ) || $product->is_type( 'subscription_variation' ) ) {
            $variation_id = $product->get_id();
            $product_id   = $product->get_parent_id();
        }

        $data_to_update = array(
            'product_id' => $product_id,
        );

        if ( $variation_id ) {
            $data_to_update['variation_id'] = $variation_id;
        }

        // Also get price/amount
        $price = $product->get_price();
        if ( ! empty( $price ) ) {
            $data_to_update['amount'] = (float) $price;
            $this->data->amount = (float) $price;
        }

        $this->data->product_id   = $product_id;
        $this->data->variation_id = $variation_id;

        SkyHSHOSO_Subscription_DB::update( $this->get_id(), $data_to_update );

        return 1; // Return dummy item ID to indicate success
    }

    /**
     * Calculate totals (stub for WC_Subscription compatibility).
     */
    public function calculate_totals() {
        return true;
    }

    /**
     * Save pending meta changes.
     * Mirrors WC_Data::save().
     *
     * @return int Subscription ID.
     */
    public function save() {
        foreach ( $this->meta_changes as $key => $value ) {
            SkyHSHOSO_Subscription_DB::update_meta( $this->get_id(), $key, $value );
        }
        $this->meta_changes = array();
        return $this->get_id();
    }

    /**
     * Add a note / log entry for this subscription.
     * Mirrors WC_Order::add_order_note().
     *
     * @param string $note
     * @return int|false Meta ID.
     */
    public function add_order_note( $note ) {
        $entry = array(
            'note'    => $note,
            'added'   => gmdate( 'Y-m-d H:i:s' ),
            'user_id' => get_current_user_id(),
        );
        return SkyHSHOSO_Subscription_DB::add_meta( $this->get_id(), '_note', $entry );
    }

    /**
     * Soft-delete. Marks subscription as 'cancelled' and cleans up.
     *
     * @param bool $force_delete  If true, removes DB row entirely.
     */
    public function delete( $force_delete = false ) {
        if ( $force_delete ) {
            SkyHSHOSO_Subscription_DB::delete( $this->get_id() );
        } else {
            $this->update_status( 'cancelled' );
        }
    }

    /**
     * Mirrors WC_Subscription::has_status().
     *
     * @param string|array $statuses
     * @return bool
     */
    public function has_status( $statuses ) {
        if ( is_string( $statuses ) ) {
            $statuses = array( $statuses );
        }
        return in_array( $this->data->status, $statuses, true );
    }

    /**
     * Calculate and update the next payment date based on billing period/interval.
     * Stores the new date to the DB immediately.
     */
    public function advance_next_payment_date() {
        $current = $this->data->next_payment_date
            ? new DateTime( $this->data->next_payment_date, new DateTimeZone( 'UTC' ) )
            : new DateTime( 'now', new DateTimeZone( 'UTC' ) );

        $period   = $this->data->billing_period;
        $interval = (int) $this->data->billing_interval;

        switch ( $period ) {
            case 'day':
                $current->modify( "+{$interval} day" );
                break;
            case 'week':
                $current->modify( "+{$interval} week" );
                break;
            case 'year':
                $current->modify( "+{$interval} year" );
                break;
            case 'month':
            default:
                $current->modify( "+{$interval} month" );
                break;
        }

        $new_date                      = $current->format( 'Y-m-d H:i:s' );
        $this->data->next_payment_date = $new_date;

        SkyHSHOSO_Subscription_DB::update(
            $this->get_id(),
            array(
                'next_payment_date' => $new_date,
                'last_payment_date' => gmdate( 'Y-m-d H:i:s' ),
                'failed_payment_count' => 0,
            )
        );
    }

    // -------------------------------------------------------------------------
    // Methods for change-payment-gateway support
    // -------------------------------------------------------------------------

    /**
     * Get order key for payment URL verification.
     *
     * @return string
     */
    public function get_order_key() {
        $key = $this->get_meta( '_order_key' );
        if ( empty( $key ) ) {
            $key = wp_hash( $this->get_id() . '_skyhshoso_' . $this->get_customer_id() );
            $this->update_meta_data( '_order_key', $key );
            $this->save();
        }
        return $key;
    }

    /**
     * Get the checkout payment URL for this subscription.
     *
     * @return string
     */
    public function get_checkout_payment_url() {
        $pay_url = wc_get_endpoint_url( 'order-pay', $this->get_id(), wc_get_page_permalink( 'checkout' ) );
        return add_query_arg( 'key', $this->get_order_key(), $pay_url );
    }

    /**
     * Get the change payment method URL.
     *
     * @return string
     */
    public function get_change_payment_method_url() {
        return wp_nonce_url(
            add_query_arg(
                array( 'change_payment_method' => $this->get_id() ),
                $this->get_checkout_payment_url()
            )
        );
    }

    /**
     * Check if a payment gateway is set.
     *
     * @return bool
     */
    public function has_payment_gateway() {
        return ! empty( $this->data->payment_method );
    }

    /**
     * Get payment method title.
     *
     * @return string
     */
    public function get_payment_method_title() {
        $title = $this->get_meta( '_payment_method_title' );
        return ! empty( $title ) ? $title : $this->data->payment_method;
    }

    /**
     * Set payment method title.
     *
     * @param string $title
     */
    public function set_payment_method_title( $title ) {
        $this->update_meta_data( '_payment_method_title', $title );
    }

    /**
     * Set the payment method on this subscription.
     *
     * @param WC_Payment_Gateway|string $payment_method Gateway object or ID.
     * @param array                     $payment_method_meta Optional meta data for the payment method.
     */
    public function set_payment_method( $payment_method, $payment_method_meta = array() ) {
        $new_payment_method = is_object( $payment_method ) ? $payment_method->id : $payment_method;
        $this->data->payment_method = $new_payment_method;

        // Persist immediately.
        SkyHSHOSO_Subscription_DB::update( $this->get_id(), array( 'payment_method' => $new_payment_method ) );

        if ( is_object( $payment_method ) && method_exists( $payment_method, 'get_title' ) ) {
            $this->update_meta_data( '_payment_method_title', $payment_method->get_title() );
        }

        // Process payment method meta array (table => field => data).
        if ( is_array( $payment_method_meta ) ) {
            foreach ( $payment_method_meta as $meta_table => $meta ) {
                if ( ! is_array( $meta ) ) {
                    continue;
                }
                foreach ( $meta as $meta_key => $meta_data ) {
                    if ( isset( $meta_data['value'] ) && ! ( isset( $meta_data['disabled'] ) && true == $meta_data['disabled'] ) ) {
                        $this->update_meta_data( $meta_key, $meta_data['value'] );
                    }
                }
            }
        }
    }

    /**
     * Check whether manual renewal is required.
     *
     * @return bool
     */
    public function get_requires_manual_renewal() {
        return (bool) $this->get_meta( '_requires_manual_renewal' );
    }

    /**
     * Set manual renewal flag.
     *
     * @param bool $value
     */
    public function set_requires_manual_renewal( $value ) {
        $this->update_meta_data( '_requires_manual_renewal', wc_bool_to_string( $value ) );
    }

    /**
     * Whether the subscription is manually renewed.
     *
     * @return bool
     */
    public function is_manual() {
        if ( 'cod' === $this->get_payment_method() ) {
            return true;
        }
        if ( class_exists( 'SkyHSHOSO_Manual_Renewal_Manager' ) && SkyHSHOSO_Manual_Renewal_Manager::is_manual_renewal_required() ) {
            return true;
        }
        return $this->get_requires_manual_renewal();
    }

    /**
     * Get Unix timestamp for a date type.
     *
     * @param string $date_type e.g. 'next_payment', 'end'
     * @return int
     */
    public function get_time( $date_type ) {
        $date = $this->get_date( $date_type );
        if ( empty( $date ) ) {
            return 0;
        }
        return strtotime( $date );
    }

    /**
     * Get a date for display.
     *
     * @param string $date_type
     * @return string
     */
    public function get_date_to_display( $date_type ) {
        $timestamp = $this->get_time( $date_type );
        if ( ! $timestamp ) {
            return '';
        }
        return date_i18n( wc_date_format() . ' ' . wc_time_format(), $timestamp );
    }

    /**
     * Check if the payment gateway supports a feature.
     *
     * @param string $feature e.g. 'subscription_cancellation'
     * @return bool
     */
    public function payment_method_supports( $feature ) {
        if ( $this->is_manual() ) {
            return true;
        }
        $payment_method = $this->get_payment_method();
        if ( empty( $payment_method ) ) {
            return false;
        }
        $gateways = WC()->payment_gateways->get_available_payment_gateways();
        if ( isset( $gateways[ $payment_method ] ) ) {
            return $gateways[ $payment_method ]->supports( $feature );
        }
        return false;
    }

    /**
     * Check whether the subscription can be updated to a given status.
     *
     * @param string $new_status
     * @return bool
     */
    public function can_be_updated_to( $new_status ) {
        if ( 'new-payment-method' !== $new_status ) {
            return false;
        }

        return apply_filters( 'skyhshoso_can_subscription_be_updated_to_new-payment-method', true, $this );
    }

    public function set_billing_period( $period ) {
        SkyHSHOSO_Subscription_DB::update( $this->get_id(), array( 'billing_period' => $period ) );
        $this->data->billing_period = $period;
    }

    public function set_billing_interval( $interval ) {
        SkyHSHOSO_Subscription_DB::update( $this->get_id(), array( 'billing_interval' => (int) $interval ) );
        $this->data->billing_interval = (int) $interval;
    }

    public function delete_date( $date_type ) {
        if ( 'next_payment' === $date_type ) {
            SkyHSHOSO_Subscription_DB::update( $this->get_id(), array( 'next_payment_date' => null ) );
            $this->data->next_payment_date = null;
        } elseif ( 'end' === $date_type ) {
            SkyHSHOSO_Subscription_DB::update( $this->get_id(), array( 'end_date' => null ) );
            $this->data->end_date = null;
        } elseif ( 'trial_end' === $date_type ) {
            SkyHSHOSO_Subscription_DB::update( $this->get_id(), array( 'trial_end_date' => null ) );
            $this->data->trial_end_date = null;
        }
    }

    public function update_dates( $dates ) {
        $update_data = array();
        foreach ( $dates as $date_type => $datetime ) {
            $formatted = $datetime ? gmdate( 'Y-m-d H:i:s', strtotime( $datetime ) ) : null;
            if ( 'next_payment' === $date_type ) {
                $update_data['next_payment_date'] = $formatted;
                $this->data->next_payment_date = $formatted;
            } elseif ( 'end' === $date_type ) {
                $update_data['end_date'] = $formatted;
                $this->data->end_date = $formatted;
            } elseif ( 'trial_end' === $date_type ) {
                $update_data['trial_end_date'] = $formatted;
                $this->data->trial_end_date = $formatted;
            } elseif ( 'start' === $date_type ) {
                $update_data['start_date'] = $formatted;
                $this->data->start_date = $formatted;
            }
        }
        if ( ! empty( $update_data ) ) {
            SkyHSHOSO_Subscription_DB::update( $this->get_id(), $update_data );
        }
    }

    /**
     * Delete subscription meta data.
     *
     * @param string $key
     */
    public function delete_meta_data( $key ) {
        SkyHSHOSO_Subscription_DB::delete_meta( $this->get_id(), $key );
        unset( $this->meta_changes[ $key ] );
    }

    /**
     * Save meta data (alias for save()).
     *
     * @return int
     */
    public function save_meta_data() {
        return $this->save();
    }

    /**
     * Get the order number for display.
     *
     * @return string
     */
    public function get_order_number() {
        return (string) $this->get_id();
    }

    // -------------------------------------------------------------------------
    // Billing address getters (delegated to the associated order)
    // -------------------------------------------------------------------------

    /**
     * Get billing country from the associated order.
     *
     * @return string
     */
    public function get_billing_country() {
        $order_id = $this->get_order_id();
        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                return $order->get_billing_country();
            }
        }
        return WC()->customer ? WC()->customer->get_billing_country() : '';
    }

    /**
     * Get billing state from the associated order.
     *
     * @return string
     */
    public function get_billing_state() {
        $order_id = $this->get_order_id();
        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                return $order->get_billing_state();
            }
        }
        return WC()->customer ? WC()->customer->get_billing_state() : '';
    }

    /**
     * Get billing postcode from the associated order.
     *
     * @return string
     */
    public function get_billing_postcode() {
        $order_id = $this->get_order_id();
        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                return $order->get_billing_postcode();
            }
        }
        return WC()->customer ? WC()->customer->get_billing_postcode() : '';
    }

    /**
     * Get billing city from the associated order.
     *
     * @return string
     */
    public function get_billing_city() {
        $order_id = $this->get_order_id();
        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                return $order->get_billing_city();
            }
        }
        return WC()->customer ? WC()->customer->get_billing_city() : '';
    }

    // -------------------------------------------------------------------------
    // Order item helpers (delegated to the associated order)
    // -------------------------------------------------------------------------

    /**
     * Get order item totals (delegated to the associated order).
     *
     * @return array
     */
    public function get_order_item_totals() {
        $order_id = $this->get_order_id();
        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order && method_exists( $order, 'get_order_item_totals' ) ) {
                return $order->get_order_item_totals();
            }
        }
        return array();
    }

    public function get_items( $type = 'line_item' ) {
        $order_id = $this->get_id();
        if ( $order_id ) {
            global $wpdb;
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $item_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id = %d AND order_item_type = %s",
                $order_id,
                $type
            ) );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $items = array();
            foreach ( $item_ids as $item_id ) {
                if ( $type === 'line_item' ) {
                    $items[ $item_id ] = new WC_Order_Item_Product( $item_id );
                } elseif ( $type === 'fee' ) {
                    $items[ $item_id ] = new WC_Order_Item_Fee( $item_id );
                } elseif ( $type === 'shipping' ) {
                    $items[ $item_id ] = new WC_Order_Item_Shipping( $item_id );
                } else {
                    $class = 'WC_Order_Item_' . implode( '', array_map( 'ucfirst', explode( '_', $type ) ) );
                    if ( class_exists( $class ) ) {
                        $items[ $item_id ] = new $class( $item_id );
                    }
                }
            }
            return $items;
        }
        return array();
    }

    /**
     * Get fee items (delegated to the associated order).
     *
     * @return array
     */
    public function get_fees() {
        return $this->get_items( 'fee' );
    }

    /**
     * Get shipping methods (delegated to the associated order).
     *
     * @return array
     */
    public function get_shipping_methods() {
        return $this->get_items( 'shipping' );
    }

    /**
     * Get address (delegated to the associated order).
     *
     * @param string $type Address type (billing/shipping)
     * @return array
     */
    public function get_address( $type = 'billing' ) {
        $order_id = $this->get_order_id();
        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order && method_exists( $order, 'get_address' ) ) {
                return $order->get_address( $type );
            }
        }
        return array();
    }

    /**
     * Set address (delegated to the associated order).
     *
     * @param array  $address
     * @param string $type
     */
    public function set_address( $address, $type = 'billing' ) {
        $order_id = $this->get_order_id();
        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order && method_exists( $order, 'set_address' ) ) {
                $order->set_address( $address, $type );
                $order->save();
            }
        }
    }

    /**
     * Set billing address (delegated to the associated order).
     *
     * @param array $address
     */
    public function set_billing_address( $address ) {
        $order_id = $this->get_order_id();
        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order && method_exists( $order, 'set_billing_address' ) ) {
                $order->set_billing_address( $address );
                $order->save();
            }
        }
    }

    /**
     * Set shipping address (delegated to the associated order).
     *
     * @param array $address
     */
    public function set_shipping_address( $address ) {
        $order_id = $this->get_order_id();
        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order && method_exists( $order, 'set_shipping_address' ) ) {
                $order->set_shipping_address( $address );
                $order->save();
            }
        }
    }

    /**
     * Get formatted line subtotal (delegated to the associated order).
     *
     * @param WC_Order_Item $item
     * @param string        $tax_display
     * @return string
     */
    public function get_formatted_line_subtotal( $item, $tax_display = '' ) {
        $order_id = $this->get_order_id();
        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order && method_exists( $order, 'get_formatted_line_subtotal' ) ) {
                return $order->get_formatted_line_subtotal( $item, $tax_display );
            }
        }
        return '';
    }

    /**
     * Get the parent order.
     *
     * @return WC_Order|false
     */
    public function get_parent() {
        $order_id = $this->get_order_id();
        return $order_id ? wc_get_order( $order_id ) : false;
    }

    /**
     * Get the parent order ID.
     *
     * @return int
     */
    public function get_parent_id() {
        return $this->get_order_id();
    }

    /**
     * Get related orders for the subscription.
     *
     * @param string $output_type 'all' to return WC_Order objects, 'ids' to return IDs.
     * @param string|array $order_type Type of order: 'any', 'parent', 'renewal', 'switch', or array.
     * @return array List of WC_Order objects or IDs.
     */
    public function get_related_orders( $output_type = 'all', $order_type = 'any' ) {
        $order_ids = array();
        
        $types = is_array( $order_type ) ? $order_type : array( $order_type );
        
        // 1. Parent order
        if ( in_array( 'any', $types, true ) || in_array( 'parent', $types, true ) ) {
            $parent_id = $this->get_order_id();
            if ( $parent_id ) {
                $order_ids[] = $parent_id;
            }
        }
        
        // 2. Renewal orders
        if ( in_array( 'any', $types, true ) || in_array( 'renewal', $types, true ) ) {
            $renewal_orders = wc_get_orders( array(
                'limit'      => -1,
                'meta_key'   => '_skyhshoso_renewal_subscription_id',
                'meta_value' => $this->get_id(),
                'return'     => 'ids',
            ) );
            if ( ! empty( $renewal_orders ) ) {
                $order_ids = array_merge( $order_ids, $renewal_orders );
            }
        }
        
        // 3. Switch orders
        if ( in_array( 'any', $types, true ) || in_array( 'switch', $types, true ) ) {
            $switch_orders = wc_get_orders( array(
                'limit'      => -1,
                'meta_key'   => '_skyhshoso_switch_subscription',
                'return'     => 'ids',
            ) );
            if ( ! empty( $switch_orders ) ) {
                foreach ( $switch_orders as $order_id ) {
                    $order_obj = wc_get_order( $order_id );
                    if ( $order_obj ) {
                        $sub_ids = $order_obj->get_meta( '_skyhshoso_switch_subscription' );
                        if ( ! is_array( $sub_ids ) ) {
                            $sub_ids = $sub_ids ? array( $sub_ids ) : array();
                        }
                        if ( in_array( $this->get_id(), $sub_ids ) || in_array( (string) $this->get_id(), $sub_ids ) ) {
                            $order_ids[] = $order_id;
                        }
                    }
                }
            }
        }
        
        $order_ids = array_unique( array_map( 'absint', $order_ids ) );
        
        if ( 'ids' === $output_type ) {
            return $order_ids;
        }
        
        $orders = array();
        foreach ( $order_ids as $id ) {
            $order = wc_get_order( $id );
            if ( $order ) {
                $orders[ $id ] = $order;
            }
        }
        
        return $orders;
    }

    /**
     * Get the last order associated with the subscription.
     *
     * @param string $output_type 'all' to return WC_Order object, 'id' to return ID.
     * @param string|array $order_type Type of order: 'any', 'parent', 'renewal', 'switch', or array.
     * @return WC_Order|int|false Last order matching the criteria or false if none found.
     */
    public function get_last_order( $output_type = 'all', $order_type = 'any' ) {
        $order_ids = $this->get_related_orders( 'ids', $order_type );
        
        if ( empty( $order_ids ) ) {
            return false;
        }
        
        $last_id = max( $order_ids );
        
        if ( ! $last_id ) {
            return false;
        }
        
        if ( 'id' === $output_type ) {
            return $last_id;
        }
        
        return wc_get_order( $last_id );
    }

    /**
     * Get the product ID for this subscription.
     *
     * @return int
     */
    public function get_product_id() {
        return (int) ( $this->data->product_id ?? 0 );
    }

    /**
     * Get the trial period unit (day, week, month).
     * Reads from the product's meta.
     *
     * @return string
     */
    public function get_trial_period() {
        $product_id = $this->get_product_id();
        $period     = get_post_meta( $product_id, '_skyhshoso_trial_period', true );
        return ! empty( $period ) ? $period : 'day';
    }

    /**
     * Get the trial length in the trial period's units.
     * Reads from the product's meta.
     *
     * @return int
     */
    public function get_trial_length() {
        $product_id = $this->get_product_id();
        $length     = get_post_meta( $product_id, '_skyhshoso_trial_length', true );
        return (int) $length;
    }
}
