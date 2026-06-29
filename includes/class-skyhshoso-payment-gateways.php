<?php
/**
 * SkyHS Payment Gateways
 *
 * Filters WooCommerce payment gateways at checkout when a subscription
 * product is in the cart. Only shows gateways that declare subscription
 * support — prevents customers from purchasing subscriptions with gateways
 * that can't handle recurring payments.
 *
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

class SkyHSHOSO_Payment_Gateways {

    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_filter( 'woocommerce_available_payment_gateways', array( $this, 'get_available_payment_gateways' ), 10, 1 );
    }

    /**
     * Filter available gateways — only show those that support subscriptions
     * when the cart contains a SkyHS subscription product.
     *
     * @param array $available_gateways
     * @return array
     */
    public function get_available_payment_gateways( $available_gateways ) {
        // Don't filter on order-pay screen.
        if ( is_wc_endpoint_url( 'order-pay' ) ) {
            return $available_gateways;
        }

        // Check if cart contains a SkyHS subscription product.
        if ( ! $this->cart_contains_subscription() ) {
            return $available_gateways;
        }

        // Filter out gateways that don't support subscriptions.
        foreach ( $available_gateways as $gateway_id => $gateway ) {
            if ( ! apply_filters( 'skyhshoso_available_payment_gateways', $gateway->supports( 'subscriptions' ), $gateway_id, $gateway ) ) {
                unset( $available_gateways[ $gateway_id ] );
            }
        }

        return $available_gateways;
    }

    /**
     * Check if the current cart contains a SkyHS subscription product.
     *
     * @return bool
     */
    public function cart_contains_subscription() {
        if ( ! isset( WC()->cart ) || ! WC()->cart ) {
            return false;
        }

        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $product_id = isset( $cart_item['product_id'] ) ? $cart_item['product_id'] : 0;
            if ( ! $product_id ) {
                continue;
            }

            // Check the variation product first, then fall back to parent.
            $check_id = $cart_item['variation_id'] ?? $product_id;
            $is_sub   = get_post_meta( $check_id, '_skyhshoso_is_subscription', true );
            if ( 'yes' === $is_sub ) {
                return true;
            }

            // Also check parent product (variations inherit).
            $parent_is_sub = get_post_meta( $product_id, '_skyhshoso_is_subscription', true );
            if ( 'yes' === $parent_is_sub ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get a payment gateway object by gateway ID.
     *
     * @param string $gateway_id
     * @return WC_Payment_Gateway|false
     */
    public static function get_payment_gateway( $gateway_id ) {
        $gateways = WC()->payment_gateways->payment_gateways();
        return isset( $gateways[ $gateway_id ] ) ? $gateways[ $gateway_id ] : false;
    }

    /**
     * Check whether any available gateway supports a feature.
     *
     * @since 1.0.0
     *
     * @param string $feature The feature to check for.
     * @return bool
     */
    public static function one_gateway_supports( $feature ) {
        $available_gateways = WC()->payment_gateways->get_available_payment_gateways();
        foreach ( $available_gateways as $gateway ) {
            if ( $gateway->supports( $feature ) ) {
                return true;
            }
        }
        return false;
    }
}

SkyHSHOSO_Payment_Gateways::instance();
