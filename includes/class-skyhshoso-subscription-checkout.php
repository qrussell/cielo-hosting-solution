<?php
/**
 * SkyHS Subscription Checkout Handler
 *
 * Hooks into WooCommerce order completion to create subscription records
 * in the custom DB table when a customer purchases a hosting or domain product.
 *
 * Also fires the same WordPress hooks that the existing
 * SkyHSHOSO_Subscription_Handler listens to, so all downstream logic
 * (WHM provisioning, eNom domain registration, etc.) continues to work.
 *
 * Hooks fired (used in class-woocommerce-subscription-handler.php):
 *   skyhshoso_subscription_created         — on new purchase (replaces woocommerce_checkout_subscription_created)
 *   skyhshoso_subscription_status_updated  — on status change (replaces woocommerce_subscription_status_updated)
 *   skyhshoso_subscription_renewed         — on renewal payment (replaces woocommerce_subscription_renewal_payment_complete)
 *
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

class SkyHSHOSO_Subscription_Checkout {

    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Create subscription record when an order is marked paid/completed.
        add_action( 'woocommerce_payment_complete', array( $this, 'handle_order_paid' ), 10, 1 );
        add_action( 'woocommerce_order_status_completed', array( $this, 'handle_order_paid' ), 10, 1 );
        add_action( 'woocommerce_order_status_processing', array( $this, 'handle_order_paid' ), 10, 1 );

        // Keep domain subscription prices updated after purchase.
        add_action( 'woocommerce_payment_complete', array( $this, 'update_domain_subscription_prices' ), 20, 1 );
        add_action( 'woocommerce_order_status_completed', array( $this, 'update_domain_subscription_prices' ), 20, 1 );
    }

    // -------------------------------------------------------------------------
    // Order → Subscription creation
    // -------------------------------------------------------------------------

    /**
     * When a WooCommerce order is paid, create SkyHS subscription records for
     * any hosting or domain products in that order.
     *
     * @param int $order_id
     */
    public function handle_order_paid( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Skip if this is a renewal or early renewal order.
        if ( skyhshoso_order_contains_renewal( $order ) || skyhshoso_order_contains_early_renewal( $order ) ) {
            return;
        }

        // Skip if we already processed this order.
        if ( $order->get_meta( '_skyhshoso_subscriptions_created' ) ) {
            return;
        }

        $subscription_created = false;

        foreach ( $order->get_items() as $item ) {
            /** @var WC_Order_Item_Product $item */
            $product_id   = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            $product      = wc_get_product( $variation_id ?: $product_id );

            if ( ! $product ) {
                continue;
            }

            // Skip switch items because they update an existing subscription instead of creating a new one.
            if ( $item->meta_exists( '_switched_subscription_item_id' ) || $item->meta_exists( '_switched_subscription_sign_up_fee_prorated' ) || $item->meta_exists( '_switched_subscription_price_prorated' ) || $order->meta_exists( '_skyhshoso_switch_subscription' ) ) {
                continue;
            }

            // Check if this product has subscriptions enabled (via the checkbox).
            $check_id     = $product_id; // parent product holds the checkbox
            $is_sub       = get_post_meta( $check_id, '_skyhshoso_is_subscription', true );
            if ( 'yes' !== $is_sub ) {
                continue;
            }

            // Read billing period from variation first, fall back to parent.
            $billing_period = get_post_meta( $variation_id ?: $product_id, '_skyhshoso_billing_period', true )
                              ?: get_post_meta( $product_id, '_skyhshoso_billing_period', true );
            if ( empty( $billing_period ) ) {
                $billing_period = 'month'; // safe default
            }

            $billing_interval = (int) ( get_post_meta( $variation_id ?: $product_id, '_skyhshoso_billing_interval', true )
                                         ?: get_post_meta( $product_id, '_skyhshoso_billing_interval', true ) );
            if ( $billing_interval < 1 ) {
                $billing_interval = 1;
            }

            // Determine trial end date (if any).
            $trial_length = get_post_meta( $variation_id ?: $product_id, '_skyhshoso_trial_length', true );
            if ( '' === $trial_length || null === $trial_length ) {
                $trial_length = $variation_id ? get_post_meta( $product_id, '_skyhshoso_trial_length', true ) : 0;
            }
            $trial_length = (int) $trial_length;

            $trial_period = get_post_meta( $variation_id ?: $product_id, '_skyhshoso_trial_period', true )
                            ?: ( $variation_id ? get_post_meta( $product_id, '_skyhshoso_trial_period', true ) : 'day' );
            if ( empty( $trial_period ) ) {
                $trial_period = 'day';
            }
            $trial_end    = null;
            if ( $trial_length > 0 ) {
                $td = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
                $td->modify( "+{$trial_length} {$trial_period}" );
                $trial_end = $td->format( 'Y-m-d H:i:s' );
            }

            // Build next payment date.
            $start        = gmdate( 'Y-m-d H:i:s' );
            $next_payment = new DateTime( $trial_end ?: $start, new DateTimeZone( 'UTC' ) );
            switch ( $billing_period ) {
                case 'day':  $next_payment->modify( "+{$billing_interval} day" );   break;
                case 'week': $next_payment->modify( "+{$billing_interval} week" );  break;
                case 'year': $next_payment->modify( "+{$billing_interval} year" );  break;
                default:     $next_payment->modify( "+{$billing_interval} month" ); break;
            }

            // -----------------------------------------------------------------
            // Check for an existing cancelled/expired subscription to reactivate
            // instead of creating a duplicate.
            // -----------------------------------------------------------------
            $existing_sub = null;
            $existing_rows = SkyHSHOSO_Subscription_DB::query( array(
                'customer_id' => (int) $order->get_customer_id(),
                'product_id'  => (int) $product_id,
            ) );

            // Also match by variation_id if applicable.
            foreach ( $existing_rows as $erow ) {
                if ( (int) $erow->variation_id !== (int) $variation_id ) {
                    continue;
                }
                if ( in_array( $erow->status, array( 'on-hold', 'cancelled', 'expired' ), true ) ) {
                    $existing_sub = $erow;
                    break;
                }
            }

            if ( $existing_sub ) {
                // Reactivate the old subscription — update dates, order, payment.
                SkyHSHOSO_Subscription_DB::update( (int) $existing_sub->id, array(
                    'order_id'          => (int) $order_id,
                    'payment_method'    => $order->get_payment_method(),
                    'billing_period'    => $billing_period,
                    'billing_interval'  => $billing_interval,
                    'amount'            => (float) $order->get_total(),
                    'currency'          => $order->get_currency(),
                    'start_date'        => $start,
                    'trial_end_date'    => $trial_end,
                    'next_payment_date' => $next_payment->format( 'Y-m-d H:i:s' ),
                    'last_payment_date' => $start,
                    'failed_payment_count' => 0,
                ) );

                $sub_id = (int) $existing_sub->id;
            } else {
                // Create a brand-new subscription record.
                $sub_id = SkyHSHOSO_Subscription_DB::insert( array(
                    'user_id'           => (int) $order->get_customer_id(),
                    'product_id'        => (int) $product_id,
                    'variation_id'      => (int) $variation_id,
                    'order_id'          => (int) $order_id,
                    'status'            => 'active',
                    'payment_method'    => $order->get_payment_method(),
                    'billing_period'    => $billing_period,
                    'billing_interval'  => $billing_interval,
                    'amount'            => (float) $order->get_total(),
                    'currency'          => $order->get_currency(),
                    'start_date'        => $start,
                    'trial_end_date'    => $trial_end,
                    'next_payment_date' => $next_payment->format( 'Y-m-d H:i:s' ),
                    'created_via'       => 'checkout',
                ) );
            }

            if ( ! $sub_id ) {
                continue;
            }

            $subscription = skyhshoso_get_subscription( $sub_id );
            if ( ! $subscription ) {
                continue;
            }

            // If reactivating an existing subscription, trigger proper status transition hooks.
            if ( isset( $existing_sub ) ) {
                $subscription->update_status( 'active', __( 'Subscription reactivated upon renewal payment.', 'skyhs-hosting-solution' ) );
            }

            // Ensure the subscription has its line item copied/created in woocommerce_order_items table
            $sub_items = $subscription->get_items();
            if ( empty( $sub_items ) ) {
                $sub_item = new WC_Order_Item_Product();
                $sub_item->set_product_id( $item->get_product_id() );
                $sub_item->set_variation_id( $item->get_variation_id() );
                $sub_item->set_quantity( $item->get_quantity() );
                $sub_item->set_subtotal( $item->get_subtotal() );
                $sub_item->set_total( $item->get_total() );
                $sub_item->set_name( $item->get_name() );
                foreach ( $item->get_meta_data() as $meta ) {
                    $sub_item->update_meta_data( $meta->key, $meta->value );
                }
                $sub_item->set_order_id( $sub_id );
                $sub_item->save();
            }

            $subscription_created = true;

            /**
             * Fires when a SkyHS subscription is created or renewed at checkout.
             * Mirrors: woocommerce_checkout_subscription_created
             *
             * @param SkyHSHOSO_Subscription $subscription
             * @param WC_Order               $order
             * @param WC_Order_Item_Product  $item
             */
            do_action( 'skyhshoso_subscription_created', $subscription, $order, $item );
        }

        if ( $subscription_created ) {
            // Mark order so we don't process it again.
            $order->update_meta_data( '_skyhshoso_subscriptions_created', true );
            $order->save();

            // Auto-complete the order — subscription products are virtual/digital.
            if ( $order->get_status() !== 'completed' ) {
                $order->update_status( 'completed', __( 'Order auto-completed: subscription activated.', 'skyhs-hosting-solution' ) );
            }
        }
    }

    // -------------------------------------------------------------------------
    // Domain renewal price correction (was in class-woocommerce-subscription-handler.php)
    // -------------------------------------------------------------------------

    /**
     * After a domain order is completed, update the subscription amount to use
     * the renewal price instead of the registration price.
     *
     * @param int $order_id
     */
    public function update_domain_subscription_prices( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Find subscriptions for this order.
        $rows = SkyHSHOSO_Subscription_DB::get_by_order( $order_id );
        foreach ( $rows as $row ) {
            $renewal_price = get_post_meta( $row->product_id, '_skyhshoso_domain_renewal_price', true );
            if ( $renewal_price ) {
                SkyHSHOSO_Subscription_DB::update( (int) $row->id, array( 'amount' => (float) $renewal_price ) );
            }
        }
    }
}

// Init.
SkyHSHOSO_Subscription_Checkout::instance();
