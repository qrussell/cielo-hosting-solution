<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SkyHSHOSO_Admin {
    public static $option_prefix = 'skyhs_hosting_solution';

    public static function insert_setting_after( &$settings, $id, $new_settings, $type = 'single_setting' ) {
        return false;
    }
}

class SkyHSHOSO_Modal {
    public function __construct( $callback_args, $trigger, $action, $title ) {}
    public function add_action( $action ) {}
    public function print_html() {}
}

class SkyHSHOSO_Admin_Notice {
    public function __construct( $type ) {}
    public function set_html_content( $html ) {}
    public function display() {}
}

class SkyHSHOSO_Synchroniser {
    public static function subscription_contains_synced_product( $subscription ) {
        return false;
    }
}

class SkyHSHOSO_Related_Order_Store {
    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function add_relation( $order, $subscription, $relation_type ) {}
}

class SkyHSHOSO_Upgrader {
    public static function repair_subtracted_base_taxes( $item_id ) {}
}

class SkyHSHOSO_Renewal_Cart_Stock_Manager {
    public static function attach_callbacks() {}
}

class SkyHSHOSO_Limiter {
    public static function is_purchasable_renewal( $is_purchasable, $product ) {
        return $is_purchasable;
    }
}

class SkyHSHOSO_Coupon {
    public static function is_renewal_cart_coupon( $coupon_type ) {
        return false;
    }

    public static function map_virtual_coupon( $code ) {
        return new WC_Coupon( $code );
    }

    public static function is_recurring_coupon( $coupon_type ) {
        return false;
    }

    public static function get_coupon_limit( $code ) {
        return 0;
    }
}

class SkyHSHOSO_Product {
    public static function needs_one_time_shipping( $data ) {
        return false;
    }
}

class SkyHSHOSO_Renewal_Order {
    public static function add_order_note( $renewal_order, $subscription ) {}
    public static function maybe_record_subscription_payment( $order_id, $old_status, $new_status = '' ) {}
    public static function prevent_cancelling_renewal_orders() {}
}

class SkyHSHOSO_Cart {
    public static function cart_needs_payment( $cart_needs_payment ) {
        return $cart_needs_payment;
    }
}

class SkyHSHOSO_Order {
    public static function order_needs_payment( $needs_payment, $order = null, $valid_order_statuses = null ) {
        return $needs_payment;
    }
}
