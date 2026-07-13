<?php
/**
 * Cielo Hosting Solution: PayPal Standard Cart Manager
 * Prevents mixed carts for hosting subscriptions to avoid PayPal errors.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class Cielo_PayPal_Cart_Manager {

    public function __construct() {
        // Hook into WooCommerce Settings
        add_filter( 'woocommerce_get_settings_products', array( $this, 'add_paypal_reference_setting' ), 10, 2 );
        
        // Hook into Cart Validation and Behavior
        add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'smart_cart_validation' ), 10, 3 );
        add_filter( 'woocommerce_is_sold_individually', array( $this, 'force_sold_individually' ), 10, 2 );
        add_filter( 'woocommerce_add_to_cart_redirect', array( $this, 'conditional_skip_cart_redirect' ), 10, 1 );
    }

    /**
     * 1. Inject the toggle into WooCommerce > Settings > Products
     */
    public function add_paypal_reference_setting( $settings, $current_section ) {
        if ( '' === $current_section ) { 
            $settings[] = array(
                'name' => __( 'Cielo Hosting: PayPal Cart Strict Mode', 'cielo-hosting' ),
                'type' => 'title',
                'desc' => __( 'Enable this if your connected PayPal account does NOT support Reference Transactions. This restricts carts to a single subscription item to prevent checkout errors.', 'cielo-hosting' ),
                'id'   => 'cielo_paypal_strict_mode_title'
            );

            $settings[] = array(
                'name'    => __( 'Enable Single-Item Cart Restriction', 'cielo-hosting' ),
                'type'    => 'checkbox',
                'desc'    => __( 'Force users to buy hosting subscriptions one at a time (Recommended for PayPal Standard).', 'cielo-hosting' ),
                'id'      => 'cielo_enable_paypal_strict_mode',
                'default' => 'yes' 
            );

            $settings[] = array(
                'type' => 'sectionend',
                'id'   => 'cielo_paypal_strict_mode_title'
            );
        }
        return $settings;
    }

    /**
     * 2. Prevent mixing standard items with hosting subscriptions
     */
    public function smart_cart_validation( $passed, $product_id, $quantity ) {
        // If Strict Mode is disabled, allow normal WooCommerce behavior
        if ( get_option( 'cielo_enable_paypal_strict_mode', 'yes' ) !== 'yes' ) {
            return $passed; 
        }

        $product = wc_get_product( $product_id );
        // Check for the meta key instead of the product type
		$skyhs_type = get_post_meta( $product_id, '_skyhshoso_product_type', true );
		$is_adding_hosting = ( $skyhs_type === 'skyhshoso_hosting' || $skyhs_type === 'skyhshoso_wp_site' ) || $product->is_type( 'subscription' );

        if ( ! WC()->cart->is_empty() ) {
            foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
                $cart_product = $cart_item['data'];
                $cart_has_hosting = $cart_product->is_type( 'skyhshoso' ) || $cart_product->is_type( 'subscription' );
                
                // If adding a hosting plan to a non-empty cart, clear the cart first
                if ( $is_adding_hosting ) {
                    WC()->cart->empty_cart();
                    break; 
                }
                
                // If cart already has hosting and they try to add a standard product, block it
                if ( ! $is_adding_hosting && $cart_has_hosting ) {
                    wc_add_notice( __( 'You cannot add other products to a cart that contains a hosting plan. Please complete your hosting purchase first.', 'cielo-hosting' ), 'error' );
                    return false; 
                }
            }
        }
        return $passed;
    }

    /**
     * 3. Force hosting items to be "Sold Individually" (Qty max 1)
     */
    public function force_sold_individually( $is_sold_individually, $product ) {
        if ( get_option( 'cielo_enable_paypal_strict_mode', 'yes' ) !== 'yes' ) {
            return $is_sold_individually;
        }

        if ( $product->is_type( 'skyhshoso' ) || $product->is_type( 'subscription' ) ) {
            return true; 
        }

        return $is_sold_individually;
    }

    /**
     * 4. Skip cart and go straight to checkout when buying a hosting plan
     */
    public function conditional_skip_cart_redirect( $url ) {
        if ( get_option( 'cielo_enable_paypal_strict_mode', 'yes' ) !== 'yes' ) {
            return $url;
        }

        if ( isset( $_REQUEST['add-to-cart'] ) && is_numeric( $_REQUEST['add-to-cart'] ) ) {
            $product_id = apply_filters( 'woocommerce_add_to_cart_product_id', absint( $_REQUEST['add-to-cart'] ) );
            $product = wc_get_product( $product_id );
            
            $skyhs_type = get_post_meta( $product_id, '_skyhshoso_product_type', true );
			if ( $product && ( $product->is_type( 'subscription' ) || $skyhs_type === 'skyhshoso_hosting' || $skyhs_type === 'skyhshoso_wp_site' ) ) {
				return wc_get_checkout_url();
			}
        }
        return $url;
    }
}