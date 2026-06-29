<?php
/**
 * SkyHS Subscription Compatibility Functions
 *
 * Provides drop-in replacements for subscription functions
 * and classes that the SkyHS plugin actually calls.
 *
 * Functions provided:
 *   skyhshoso_get_subscription()
 *   skyhshoso_create_subscription()
 *   skyhshoso_get_subscriptions()
 *   skyhshoso_get_subscriptions_for_order()
 *   skyhshoso_get_subscription_status_name()
 *
 * Classes provided:
 *   WC_Subscriptions          (existence-check stub)
 *   SkyHSHOSO_Subscriptions_Product  (static helper methods)
 *
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Global functions — only define if not already present
// ---------------------------------------------------------------------------

    /**
     * Retrieve a SkyHS subscription object by ID.
     *
     * @param int $subscription_id
     * @return SkyHSHOSO_Subscription|false
     */
    function skyhshoso_get_subscription( $subscription_id ) {
        if ( is_object( $subscription_id ) && $subscription_id instanceof SkyHSHOSO_Subscription ) {
            return $subscription_id;
        }
        if ( is_object( $subscription_id ) && is_callable( array( $subscription_id, 'get_id' ) ) ) {
            $subscription_id = $subscription_id->get_id();
        }
        $row = SkyHSHOSO_Subscription_DB::get( (int) $subscription_id );
        if ( ! $row ) {
            return false;
        }
        return new SkyHSHOSO_Subscription( $row );
    }

    /**
     * Create a new SkyHS subscription.
     *
     * Accepted $args keys:
     *   customer_id      int
     *   billing_period   string  'month'|'year'|'week'|'day'
     *   billing_interval int
     *   start_date       string  GMT datetime
     *   created_via      string
     *   order_id         int
     *   product_id       int
     *   variation_id     int
     *   amount           float
     *   status           string  default 'active'
     *
     * @param array $args
     * @return SkyHSHOSO_Subscription|WP_Error
     */
    function skyhshoso_create_subscription( array $args ) {
		// Validate required fields.
		if ( empty( $args['customer_id'] ) || empty( $args['billing_period'] ) ) {
			SkyHSHOSO_Logger::error( 'Subscription creation failed: missing required fields (customer_id and billing_period)', array( 'source' => 'subscription_functions' ) );
			return new WP_Error( 'missing_required_fields', __( 'customer_id and billing_period are required.', 'skyhs-hosting-solution' ) );
		}

		$user = get_userdata( (int) $args['customer_id'] );
		if ( ! $user ) {
			SkyHSHOSO_Logger::error( 'Subscription creation failed: invalid customer ID ' . $args['customer_id'], array( 'source' => 'subscription_functions' ) );
			return new WP_Error( 'invalid_customer', __( 'Invalid customer ID.', 'skyhs-hosting-solution' ) );
		}

        $billing_period   = sanitize_text_field( $args['billing_period'] );
        $billing_interval = isset( $args['billing_interval'] ) ? (int) $args['billing_interval'] : 1;
        $start_date       = ! empty( $args['start_date'] ) ? $args['start_date'] : gmdate( 'Y-m-d H:i:s' );

        // Calculate first next_payment_date from start_date.
        $next = new DateTime( $start_date, new DateTimeZone( 'UTC' ) );
        switch ( $billing_period ) {
            case 'day':
                $next->modify( "+{$billing_interval} day" );
                break;
            case 'week':
                $next->modify( "+{$billing_interval} week" );
                break;
            case 'year':
                $next->modify( "+{$billing_interval} year" );
                break;
            default: // month
                $next->modify( "+{$billing_interval} month" );
                break;
        }

        $data = array(
            'user_id'           => (int) $args['customer_id'],
            'product_id'        => isset( $args['product_id'] ) ? (int) $args['product_id'] : 0,
            'variation_id'      => isset( $args['variation_id'] ) ? (int) $args['variation_id'] : 0,
            'order_id'          => isset( $args['order_id'] ) ? (int) $args['order_id'] : 0,
            'status'            => isset( $args['status'] ) ? sanitize_text_field( $args['status'] ) : 'pending',
            'payment_method'    => isset( $args['payment_method'] ) ? sanitize_text_field( $args['payment_method'] ) : '',
            'billing_period'    => $billing_period,
            'billing_interval'  => $billing_interval,
            'amount'            => isset( $args['amount'] ) ? (float) $args['amount'] : 0.00,
            'currency'          => isset( $args['currency'] ) ? strtoupper( sanitize_text_field( $args['currency'] ) ) : get_woocommerce_currency(),
            'start_date'        => $start_date,
            'next_payment_date' => $next->format( 'Y-m-d H:i:s' ),
            'created_via'       => isset( $args['created_via'] ) ? sanitize_text_field( $args['created_via'] ) : '',
        );

		$id = SkyHSHOSO_Subscription_DB::insert( $data );

		if ( ! $id ) {
			SkyHSHOSO_Logger::error( 'Subscription creation failed: database insert error', array( 'source' => 'subscription_functions' ) );
			return new WP_Error( 'db_error', __( 'Failed to create subscription record.', 'skyhs-hosting-solution' ) );
		}

        $subscription = skyhshoso_get_subscription( $id );

        /**
         * Fires immediately after a SkyHS subscription is created.
         *
         * @param SkyHSHOSO_Subscription $subscription
         */
        do_action( 'skyhshoso_subscription_created', $subscription );

        return $subscription;
    }

    /**
     * Query subscriptions with flexible filters.
     *
     * @param array $args  See SkyHSHOSO_Subscription_DB::query() for accepted keys.
     * @return SkyHSHOSO_Subscription[]  Keyed by subscription ID.
     */
    function skyhshoso_get_subscriptions( array $args = array() ) {
        $rows   = SkyHSHOSO_Subscription_DB::query( $args );
        $result = array();
        foreach ( $rows as $row ) {
            $result[ (int) $row->id ] = new SkyHSHOSO_Subscription( $row );
        }
        return $result;
    }

    /**
     * Get all subscriptions attached to a WooCommerce order.
     *
     * @param WC_Order|int $order
     * @return SkyHSHOSO_Subscription[]  Keyed by subscription ID.
     */
    function skyhshoso_get_subscriptions_for_order( $order ) {
        $order_id = is_object( $order ) ? $order->get_id() : (int) $order;
        $rows     = SkyHSHOSO_Subscription_DB::get_by_order( $order_id );
        $result   = array();
        foreach ( $rows as $row ) {
            $result[ (int) $row->id ] = new SkyHSHOSO_Subscription( $row );
        }

        // Also check if the order has _skyhshoso_switch_subscription meta (switch order)
        $order_obj = is_object( $order ) ? $order : wc_get_order( $order_id );
        if ( $order_obj ) {
            $switch_sub_ids = $order_obj->get_meta( '_skyhshoso_switch_subscription' );
            if ( ! is_array( $switch_sub_ids ) ) {
                $switch_sub_ids = $switch_sub_ids ? array( $switch_sub_ids ) : array();
            }
            foreach ( $switch_sub_ids as $sub_id ) {
                $sub_id = (int) $sub_id;
                if ( $sub_id && ! isset( $result[ $sub_id ] ) ) {
                    $sub = skyhshoso_get_subscription( $sub_id );
                    if ( $sub ) {
                        $result[ $sub_id ] = $sub;
                    }
                }
            }
        }
        return $result;
    }

    /**
     * Return a human-readable label for a subscription status.
     *
     * @param string $status  e.g. 'on-hold', 'pending-cancel'
     * @return string
     */
    function skyhshoso_get_subscription_status_name( $status ) {
        $labels = array(
            'active'    => __( 'Active', 'skyhs-hosting-solution' ),
            'on-hold'   => __( 'On Hold', 'skyhs-hosting-solution' ),
            'cancelled' => __( 'Cancelled', 'skyhs-hosting-solution' ),
            'expired'   => __( 'Expired', 'skyhs-hosting-solution' ),
        );
        return isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( str_replace( '-', ' ', $status ) );
    }

    /**
     * Check if any subscriptions exist in the database.
     *
     * @return bool
     */
    function skyhshoso_do_subscriptions_exist() {
        global $wpdb;
        $table = $wpdb->prefix . 'skyhshoso_subscriptions';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $count > 0;
    }

    if ( ! function_exists( 'skyhshoso_allow_protected_products_to_renew' ) ) {
        /**
         * Stub callback for password-protected products renewal.
         */
        function skyhshoso_allow_protected_products_to_renew() {
            // Stub compatibility function.
        }
    }

    if ( ! function_exists( 'skyhshoso_disallow_protected_product_add_to_cart_validation' ) ) {
        /**
         * Stub callback for password-protected products renewal validation.
         */
        function skyhshoso_disallow_protected_product_add_to_cart_validation() {
            // Stub compatibility function.
        }
    }

    if ( ! function_exists( 'skyhshoso_get_edit_post_link' ) ) {
        /**
         * Get the edit link for a post or order.
         *
         * @param int $post_id
         * @return string
         */
        function skyhshoso_get_edit_post_link( $post_id ) {
            $order = wc_get_order( $post_id );
            if ( $order ) {
                return method_exists( $order, 'get_edit_order_url' ) ? $order->get_edit_order_url() : admin_url( 'post.php?post=' . $post_id . '&action=edit' );
            }
            return get_edit_post_link( $post_id );
        }
    }

// ---------------------------------------------------------------------------
// Class stubs
// ---------------------------------------------------------------------------


    /**
     * Static helper providing the subset of SkyHSHOSO_Subscriptions_Product methods
     * used by the SkyHS plugin.
     *
     * Meta key convention (stored on the WC product):
     *   _skyhshoso_billing_period    → 'month' | 'year' | 'week' | 'day'
     *   _skyhshoso_billing_interval  → 1, 2, 3 …
     *   _skyhshoso_trial_length      → 0, 7, 14 …
     *   _skyhshoso_trial_period      → 'day' | 'week' | 'month'
     */
    class SkyHSHOSO_Subscriptions_Product {

        /**
         * Get billing period for a product.
         *
         * @param WC_Product|int $product
         * @return string  e.g. 'month'
         */
        public static function get_period( $product ) {
            $product_id = is_object( $product ) ? $product->get_id() : (int) $product;
            $value      = get_post_meta( $product_id, '_skyhshoso_billing_period', true );
            if ( empty( $value ) ) {
                $parent_id = wp_get_post_parent_id( $product_id );
                if ( $parent_id ) {
                    $value = get_post_meta( $parent_id, '_skyhshoso_billing_period', true );
                }
            }
            return ! empty( $value ) ? $value : 'month';
        }

        /**
         * Get billing interval for a product.
         *
         * @param WC_Product|int $product
         * @return int  e.g. 1
         */
        public static function get_interval( $product ) {
            $product_id = is_object( $product ) ? $product->get_id() : (int) $product;
            $value      = get_post_meta( $product_id, '_skyhshoso_billing_interval', true );
            if ( empty( $value ) ) {
                $parent_id = wp_get_post_parent_id( $product_id );
                if ( $parent_id ) {
                    $value = get_post_meta( $parent_id, '_skyhshoso_billing_interval', true );
                }
            }
            return ! empty( $value ) ? (int) $value : 1;
        }

        /**
         * Get trial length for a product.
         *
         * @param WC_Product|int $product
         * @return int  Number of trial period units (0 = no trial).
         */
        public static function get_trial_length( $product ) {
            $product_id = is_object( $product ) ? $product->get_id() : (int) $product;
            $value      = get_post_meta( $product_id, '_skyhshoso_trial_length', true );
            if ( '' === $value || null === $value ) {
                $parent_id = wp_get_post_parent_id( $product_id );
                if ( $parent_id ) {
                    $value = get_post_meta( $parent_id, '_skyhshoso_trial_length', true );
                }
            }
            return (int) $value;
        }

        /**
         * Get trial period unit for a product.
         *
         * @param WC_Product|int $product
         * @return string  e.g. 'day', 'week', 'month'
         */
        public static function get_trial_period( $product ) {
            $product_id = is_object( $product ) ? $product->get_id() : (int) $product;
            $value      = get_post_meta( $product_id, '_skyhshoso_trial_period', true );
            if ( empty( $value ) ) {
                $parent_id = wp_get_post_parent_id( $product_id );
                if ( $parent_id ) {
                    $value = get_post_meta( $parent_id, '_skyhshoso_trial_period', true );
                }
            }
            return ! empty( $value ) ? $value : 'day';
        }

        /**
         * Determine whether a product is a SkyHS subscription product.
         * Checks the _skyhshoso_is_subscription checkbox AND billing period.
         *
         * @param WC_Product|int $product
         * @return bool
         */
        public static function is_subscription( $product ) {
            $product_id = is_object( $product ) ? $product->get_id() : (int) $product;
            $parent_id  = wp_get_post_parent_id( $product_id );

            // Check the checkbox on the product or its parent (for variations).
            $check_id = $parent_id ? $parent_id : $product_id;
            $is_sub   = get_post_meta( $check_id, '_skyhshoso_is_subscription', true );

            return 'yes' === $is_sub;
        }

        /**
         * Get subscription length (number of billing periods).
         *
         * @param WC_Product|int $product
         * @return int  0 = infinite / no limit.
         */
        public static function get_length( $product ) {
            $product_id = is_object( $product ) ? $product->get_id() : (int) $product;
            $value      = get_post_meta( $product_id, '_skyhshoso_length', true );
            return (int) $value;
        }

        /**
         * Get sign-up fee for a subscription product.
         *
         * @param WC_Product|int $product
         * @return float
         */
        public static function get_sign_up_fee( $product ) {
            $product_id = is_object( $product ) ? $product->get_id() : (int) $product;
            $value      = get_post_meta( $product_id, '_skyhshoso_sign_up_fee', true );
            return (float) $value;
        }

        /**
         * Get the expiration date for a subscription product.
         *
         * @param WC_Product|int $product
         * @param string         $from_date  Optional. Calculate from this date.
         * @return string GMT datetime or '0' if no limit.
         */
        public static function get_expiration_date( $product, $from_date = '' ) {
            $length = self::get_length( $product );
            if ( 0 === $length ) {
                return '0';
            }
            $period   = self::get_period( $product );
            $interval = self::get_interval( $product );
            $from     = ! empty( $from_date ) ? strtotime( $from_date ) : time();
            $total    = $length * $interval;
            return gmdate( 'Y-m-d H:i:s', strtotime( "+{$total} {$period}s", $from ) );
        }

        /**
         * Get the first renewal payment time for a subscription product.
         *
         * @param WC_Product|int $product
         * @param string         $from_date  Optional. Defaults to current time.
         * @return int Timestamp.
         */
        public static function get_first_renewal_payment_time( $product, $from_date = '' ) {
            $trial_length = self::get_trial_length( $product );
            if ( $trial_length > 0 ) {
                $trial_period = self::get_trial_period( $product );
                $from         = ! empty( $from_date ) ? strtotime( $from_date ) : time();
                return strtotime( "+{$trial_length} {$trial_period}s", $from );
            }
            // No trial — first payment is now (or the specified from_date).
            return ! empty( $from_date ) ? strtotime( $from_date ) : time();
        }

        /**
         * Get the parent product IDs for a product (for grouped products).
         *
         * @param WC_Product|int $product
         * @return array
         */
        public static function get_parent_ids( $product ) {
            $product_id = is_object( $product ) ? $product->get_id() : (int) $product;
            $parent_id  = wp_get_post_parent_id( $product_id );
            if ( $parent_id ) {
                return array( $parent_id );
            }

            // Fallback: search for parent grouped products containing this child product ID
            $parent_ids = array();
            $grouped_posts = get_posts( array(
                'post_type'   => 'product',
                'post_status' => 'publish',
                'numberposts' => -1,
                'tax_query'   => array(
                    array(
                        'taxonomy' => 'product_type',
                        'field'    => 'slug',
                        'terms'    => 'grouped',
                    ),
                ),
            ) );

            foreach ( $grouped_posts as $gp ) {
                $children = get_post_meta( $gp->ID, '_children', true );
                if ( is_array( $children ) && in_array( $product_id, $children, true ) ) {
                    $parent_ids[] = (int) $gp->ID;
                }
            }

            return $parent_ids;
        }

        /**
         * Get visible grouped parent product IDs.
         *
         * @param WC_Product|int $product
         * @return array
         */
        public static function get_visible_grouped_parent_product_ids( $product ) {
            return self::get_parent_ids( $product );
        }

        /**
         * Get the price of a subscription product.
         *
         * @param WC_Product|int $product
         * @return float
         */
        public static function get_price( $product ) {
            if ( is_object( $product ) && is_callable( array( $product, 'get_price' ) ) ) {
                return (float) $product->get_price();
            }
            $product_id = is_object( $product ) ? $product->get_id() : (int) $product;
            $product    = wc_get_product( $product_id );
            return $product ? (float) $product->get_price() : 0;
        }
    }
