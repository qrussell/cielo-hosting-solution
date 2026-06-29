<?php

defined( 'ABSPATH' ) || exit;

class SkyHSHOSO_Domain_Cart {
    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action('wp_ajax_skyhshoso_add_domain_to_cart', array($this, 'add_domain_to_cart'));
        add_action('wp_ajax_nopriv_skyhshoso_add_domain_to_cart', array($this, 'add_domain_to_cart'));
        add_action('wp_ajax_skyhshoso_add_transfer_to_cart', array($this, 'add_transfer_to_cart'));
        add_action('wp_ajax_nopriv_skyhshoso_add_transfer_to_cart', array($this, 'add_transfer_to_cart'));
        add_action('skyhshoso_delete_temporary_domain_product', array($this, 'delete_temporary_domain_product'));
        add_action('woocommerce_order_status_completed', array($this, 'make_domain_product_permanent'));
        
        // Add filter to ensure domain products remain hidden in catalog
        add_filter('woocommerce_product_is_visible', array($this, 'filter_domain_product_visibility'), 10, 2);
    }

    public function add_domain_to_cart() {
        // Verify nonce
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'skyhshoso_add_domain_to_cart_nonce' ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Security check failed. Please refresh the page and try again.', 'skyhs-hosting-solution' ) ) );
            wp_die();
        }
        
        if ( SkyHSHOSO_Settings::is_domain_registration_disabled() ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Domain registration is currently disabled.', 'skyhs-hosting-solution' ) ) );
            wp_die();
        }
        
        if ( ! isset( $_POST['skyhshoso_domain'] ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Invalid request.', 'skyhs-hosting-solution' ) ) );
            wp_die();
        }

        $domain = isset( $_POST['skyhshoso_domain'] ) ? sanitize_text_field( wp_unslash( $_POST['skyhshoso_domain'] ) ) : '';
        $product_id = $this->create_domain_product($domain);

        if (!$product_id) {
            wp_send_json_error('Failed to create product');
        }

        WC()->cart->add_to_cart($product_id);

        wp_send_json_success('Domain added to cart');
    }

    public function add_transfer_to_cart() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'skyhshoso_add_domain_to_cart_nonce')) {
            wp_send_json_error(array('message' => esc_html__('Security check failed.', 'skyhs-hosting-solution')));
            wp_die();
        }

        if (SkyHSHOSO_Settings::is_domain_registration_disabled()) {
            wp_send_json_error(array('message' => esc_html__('Domain transfers are currently disabled.', 'skyhs-hosting-solution')));
            wp_die();
        }

        $domain = isset($_POST['skyhshoso_domain']) ? sanitize_text_field(wp_unslash($_POST['skyhshoso_domain'])) : '';
        $auth_code = isset($_POST['skyhshoso_auth_code']) ? sanitize_text_field(wp_unslash($_POST['skyhshoso_auth_code'])) : '';

        if (empty($auth_code)) {
            wp_send_json_error(array('message' => esc_html__('Please provide the EPP authorization code.', 'skyhs-hosting-solution')));
            wp_die();
        }

        $product_id = $this->create_transfer_product($domain, $auth_code);

        if (!$product_id) {
            wp_send_json_error(array('message' => esc_html__('Failed to create transfer product.', 'skyhs-hosting-solution')));
            wp_die();
        }

        WC()->cart->add_to_cart($product_id);

        wp_send_json_success(array('message' => 'Domain transfer added to cart'));
    }

    public function create_transfer_product($domain, $auth_code, $is_temporary = true, $transfer_price = null, $renewal_price = null) {
        if ($transfer_price === null || $renewal_price === null) {
            $parts = explode('.', $domain, 2);
            if (count($parts) != 2) {
                return false;
            }
            $domain_info = SkyHSHOSO_Enom_Integration()->check_transfer_domain($parts[0], $parts[1]);
            if (isset($domain_info['error']) || !$domain_info['transferable']) {
                return false;
            }
            $transfer_price = $domain_info['transfer_price'];
            $renewal_price = $domain_info['renewal_price'];
        }

        if (!function_exists('wc_get_product_object')) {
            return false;
        }

        $product = wc_get_product_object('simple');

        if (!$product) {
            return false;
        }

        $product->set_name(sprintf(__('%s (Transfer)', 'skyhs-hosting-solution'), $domain));
        $product->set_status('publish');
        $product->set_catalog_visibility('hidden');
        $product->set_regular_price($transfer_price);
        $product->set_sold_individually(true);
        $product->set_virtual(true);

        $product->update_meta_data('_skyhshoso_billing_period', 'year');
        $product->update_meta_data('_skyhshoso_billing_interval', 1);
        $product->update_meta_data('_skyhshoso_trial_length', 0);
        $product->update_meta_data('_skyhshoso_trial_period', 'day');

        $product->update_meta_data('_skyhshoso_domain_renewal_price', $renewal_price);
        $product->update_meta_data('_skyhshoso_product_type', 'skyhshoso_domain');
        $product->update_meta_data('_skyhshoso_domain_transfer', 'yes');
        $product->update_meta_data('_skyhshoso_domain_auth_code', $auth_code);
        $product->update_meta_data('_skyhshoso_is_temporary', $is_temporary);
        $product->update_meta_data('_skyhshoso_purchase_limit', 1);
        $product->update_meta_data('_skyhshoso_is_subscription', 'yes');

        $product_id = $product->save();

        if (!$product_id) {
            return false;
        }

        if ($is_temporary) {
            wp_schedule_single_event(time() + 3600, 'skyhshoso_delete_temporary_domain_product', array($product_id));
        }

        return $product_id;
    }

    public function create_domain_product($domain, $is_temporary = true, $registration_price = null, $renewal_price = null) {
        if ($registration_price === null || $renewal_price === null) {
            $parts = explode('.', $domain, 2);
            if (count($parts) != 2) {
                return false;
            }
            $sld = $parts[0];
            $tld = $parts[1];
            $domain_info = $this->check_domain($sld, $tld);
            if (isset($domain_info['error']) || !$domain_info['available']) {
                return false;
            }
            $registration_price = $domain_info['registration_price'];
            $renewal_price = $domain_info['renewal_price'];
        }

        // Check if WooCommerce is active
        if (!function_exists('wc_get_product_object')) {
            return false;
        }

        // Create a new product object (simple — billing handled by SkyHS subscription system)
        $product = wc_get_product_object( 'simple' );

        if ( ! $product ) {
            return false;
        }

        $product->set_name( $domain );
        $product->set_status( 'publish' );
        $product->set_catalog_visibility( 'hidden' );
        $product->set_regular_price( $registration_price );
        $product->set_sold_individually( true );
        $product->set_virtual( true );

        // SkyHS billing meta — read by our subscription checkout handler.
        $product->update_meta_data( '_skyhshoso_billing_period',   'year' );
        $product->update_meta_data( '_skyhshoso_billing_interval', 1 );
        $product->update_meta_data( '_skyhshoso_trial_length',     0 );
        $product->update_meta_data( '_skyhshoso_trial_period',     'day' );

        // Store the renewal price for later use
        $product->update_meta_data('_skyhshoso_domain_renewal_price', $renewal_price);

        // Set custom field for product type
        $product->update_meta_data('_skyhshoso_product_type', 'skyhshoso_domain');
        $product->update_meta_data('_skyhshoso_is_temporary', $is_temporary);
        $product->update_meta_data('_skyhshoso_purchase_limit', 1);
        $product->update_meta_data('_skyhshoso_is_subscription', 'yes');

        $product_id = $product->save();

        if (!$product_id) {
            return false;
        }

        if ($is_temporary) {
            wp_schedule_single_event(time() + 3600, 'skyhshoso_delete_temporary_domain_product', array($product_id));
        }

        return $product_id;
    }

    public function delete_temporary_domain_product($product_id) {
        $product = wc_get_product($product_id);
        if ($product && $product->get_meta('_skyhshoso_is_temporary') == true && $product->get_meta('_skyhshoso_product_type') == 'skyhshoso_domain') {
            wp_delete_post($product_id, true);
        }
    }

    public function make_domain_product_permanent($order_id) {
        $order = wc_get_order($order_id);
        
        foreach ($order->get_items() as $item_id => $item) {
            $product_id = $item->get_product_id();
            $product = wc_get_product($product_id);
            
            if ($product && $product->get_meta('_skyhshoso_is_temporary') == true && $product->get_meta('_skyhshoso_product_type') == 'skyhshoso_domain') {
                // Update product meta to make it permanent
                $product->update_meta_data('_skyhshoso_is_temporary', false);
                
                // Ensure the product remains hidden in shop and search
                $product->set_catalog_visibility('hidden');
                
                // Save the product
                $product->save();
                
                // Clear scheduled deletion
                wp_clear_scheduled_hook('skyhshoso_delete_temporary_domain_product', array($product_id));
            }
        }
    }

    private function check_domain($sld, $tld) {
        return SkyHSHOSO_Enom_Integration()->check_domain($sld, $tld);
    }

    /**
     * Filter domain product visibility to ensure they remain hidden
     * 
     * @param bool $visible Current visibility state
     * @param int $product_id Product ID
     * @return bool Modified visibility
     */
    public function filter_domain_product_visibility($visible, $product_id) {
        $product = wc_get_product($product_id);
        
        // If this is a domain product, enforce hidden state
        if ($product && $product->get_meta('_skyhshoso_product_type') === 'skyhshoso_domain') {
            return false;
        }
        
        return $visible;
    }
}

// Initialize the SkyHSHOSO_Domain_Cart class
function SkyHSHOSO_Domain_Cart() {
    return SkyHSHOSO_Domain_Cart::instance();
}

SkyHSHOSO_Domain_Cart();
