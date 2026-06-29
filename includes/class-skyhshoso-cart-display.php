<?php

defined( 'ABSPATH' ) || exit;

class SkyHSHOSO_Cart_Display {

    public static function init() {
        add_filter( 'woocommerce_cart_product_subtotal', array( __CLASS__, 'cart_product_subtotal' ), 10, 4 );
        add_filter( 'woocommerce_cart_product_price', array( __CLASS__, 'cart_product_price' ), 10, 2 );
        add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'cart_item_data' ), 10, 2 );
        add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'product_price_html' ), 10, 2 );
    }

    public static function cart_product_subtotal( $product_subtotal, $product, $quantity, $cart ) {
        if ( ! self::should_display_period( $product ) ) {
            return $product_subtotal;
        }
        $period_string = self::get_period_string( $product );
        if ( '' === $period_string ) {
            return $product_subtotal;
        }
        return '<span class="subscription-price">' . $product_subtotal . esc_html( $period_string ) . '</span>';
    }

    public static function cart_product_price( $price, $product ) {
        if ( ! self::should_display_period( $product ) ) {
            return $price;
        }
        $period_string = self::get_period_string( $product );
        if ( '' === $period_string ) {
            return $price;
        }
        return '<span class="subscription-price">' . $price . esc_html( $period_string ) . '</span>';
    }

    public static function cart_item_data( $item_data, $cart_item ) {
        $product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
        if ( ! $product ) {
            return $item_data;
        }
        if ( ! class_exists( 'SkyHSHOSO_Subscriptions_Product' ) ) {
            return $item_data;
        }
        if ( ! SkyHSHOSO_Subscriptions_Product::is_subscription( $product ) ) {
            return $item_data;
        }

        $period   = SkyHSHOSO_Subscriptions_Product::get_period( $product );
        $interval = (int) SkyHSHOSO_Subscriptions_Product::get_interval( $product );
        if ( empty( $period ) ) {
            return $item_data;
        }

        $label = ( 1 === $interval ) ? $period : $interval . ' ' . $period . 's';

        $item_data[] = array(
            'name'                                     => __( 'Billing', 'skyhs-hosting-solution' ),
            'value'                                    => $label,
            'hidden'                                   => false,
            '__experimental_woocommerce_blocks_hidden' => false,
        );
        return $item_data;
    }

    public static function product_price_html( $price_html, $product ) {
        if ( ! class_exists( 'SkyHSHOSO_Subscriptions_Product' ) ) {
            return $price_html;
        }
        if ( ! SkyHSHOSO_Subscriptions_Product::is_subscription( $product ) ) {
            return $price_html;
        }

        if ( $product->is_type( 'variable' ) ) {
            $children  = $product->get_children();
            $periods   = array();
            $intervals = array();
            foreach ( $children as $child_id ) {
                $child = wc_get_product( $child_id );
                if ( $child && $child->is_purchasable() ) {
                    $periods[]   = SkyHSHOSO_Subscriptions_Product::get_period( $child );
                    $intervals[] = SkyHSHOSO_Subscriptions_Product::get_interval( $child );
                }
            }
            $periods   = array_unique( $periods );
            $intervals = array_unique( $intervals );
            if ( count( $periods ) === 1 && count( $intervals ) === 1 ) {
                $period        = reset( $periods );
                $interval      = (int) reset( $intervals );
                $period_string = ( 1 === $interval ) ? ' / ' . $period : ' / ' . $interval . ' ' . $period . 's';
                return '<span class="subscription-price">' . $price_html . esc_html( $period_string ) . '</span>';
            }
            return $price_html;
        }

        $period_string = self::get_period_string( $product );
        if ( '' === $period_string ) {
            return $price_html;
        }
        return '<span class="subscription-price">' . $price_html . esc_html( $period_string ) . '</span>';
    }

    private static function should_display_period( $product ) {
        if ( ! class_exists( 'SkyHSHOSO_Subscriptions_Product' ) ) {
            return false;
        }
        if ( function_exists( 'skyhshoso_cart_contains_renewal' ) ) {
            if ( skyhshoso_cart_contains_renewal() ) {
                return false;
            }
        }
        return SkyHSHOSO_Subscriptions_Product::is_subscription( $product );
    }

    private static function get_period_string( $product ) {
        $period   = SkyHSHOSO_Subscriptions_Product::get_period( $product );
        $interval = (int) SkyHSHOSO_Subscriptions_Product::get_interval( $product );
        if ( empty( $period ) ) {
            return '';
        }
        return ( 1 === $interval ) ? ' / ' . $period : ' / ' . $interval . ' ' . $period . 's';
    }
}
