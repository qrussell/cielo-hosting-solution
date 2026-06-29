<?php

defined( 'ABSPATH' ) || exit;

class SkyHSHOSO_Extend_Store_Endpoint {

    const IDENTIFIER = 'skyhs';

    public static function init() {
        if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
            return;
        }

        woocommerce_store_api_register_endpoint_data(
            array(
                'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema::IDENTIFIER,
                'namespace'       => self::IDENTIFIER,
                'data_callback'   => array( __CLASS__, 'extend_cart_item_data' ),
                'schema_callback' => array( __CLASS__, 'extend_cart_item_schema' ),
                'schema_type'     => ARRAY_A,
            )
        );
    }

    public static function extend_cart_item_data( $cart_item ) {
        $product = $cart_item['data'];

        if ( ! class_exists( 'SkyHSHOSO_Subscriptions_Product' ) ) {
            return array(
                'billing_period'   => null,
                'billing_interval' => null,
            );
        }

        if ( ! SkyHSHOSO_Subscriptions_Product::is_subscription( $product ) ) {
            return array(
                'billing_period'   => null,
                'billing_interval' => null,
            );
        }

        $period   = SkyHSHOSO_Subscriptions_Product::get_period( $product );
        $interval = (int) SkyHSHOSO_Subscriptions_Product::get_interval( $product );

        return array(
            'billing_period'   => $period,
            'billing_interval' => $interval,
        );
    }

    public static function extend_cart_item_schema() {
        return array(
            'billing_period'   => array(
                'description' => __( 'Billing period', 'skyhs-hosting-solution' ),
                'type'        => 'string',
                'readonly'    => true,
            ),
            'billing_interval' => array(
                'description' => __( 'Billing interval', 'skyhs-hosting-solution' ),
                'type'        => 'integer',
                'readonly'    => true,
            ),
        );
    }
}
