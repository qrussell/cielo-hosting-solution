<?php
/**
 * SkyHS Subscription Handler
 *
 * Listens to custom subscription hooks and manages the WordPress Custom Post Types
 * and Server Provider integrations (WHM, HestiaCP, etc.).
 *
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Utilities\OrderUtil;

class SkyHSHOSO_Subscription_Handler {
    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Declare HPOS compatibility
        add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );

        // SkyHS custom subscription hooks
        add_action( 'skyhshoso_subscription_created', array( $this, 'handle_subscription_creation_or_resubscribe' ), 10, 3 );
        add_action( 'skyhshoso_subscription_status_updated', array( $this, 'update_post_status_on_subscription_change' ), 10, 3 );
        add_action( 'skyhshoso_subscription_renewed', array( $this, 'handle_subscription_renewal' ), 10, 2 );
        add_action( 'skyhshoso_subscription_switch_completed', array( $this, 'handle_subscription_switch' ), 10, 2 );
        
        // --- ASYNC BACKGROUND PROCESS LISTENER ---
        add_action( 'skyhshoso_background_provision_account', array( $this, 'execute_server_provisioning' ), 10, 2 );
    }

    public function declare_hpos_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }

    public function handle_subscription_creation_or_resubscribe( $subscription, $order = null, $item_or_cart = null ) {
        $old_subscription_id = $subscription->get_meta( '_subscription_resubscribe' );

        if ( $old_subscription_id ) {
            $old_subscription = skyhshoso_get_subscription( $old_subscription_id );
            if ( $old_subscription ) {
                $this->handle_subscription_resubscribe( $subscription, $old_subscription );
            }
        } else {
            if ( $order instanceof WC_Order ) {
                $this->create_posts_for_subscription( $subscription, $order, $item_or_cart );
            }
        }
    }
    
    private function create_posts_for_subscription($subscription, $order, $item_or_cart) {
        $items = array();
        if ( $item_or_cart instanceof WC_Order_Item_Product ) {
            $items = array( $item_or_cart );
        } elseif ( $order instanceof WC_Order ) {
            $items = $order->get_items();
        }

        foreach ($items as $item) {
            $product = $item->get_product();
            if ( ! $product ) continue;
            
            $product_id = $product->get_id();
            $parent_id = $product->get_parent_id();
            
            if ($parent_id) {
                $parent_product = wc_get_product($parent_id);
                $product_type = get_post_meta($parent_id, '_skyhshoso_product_type', true);
            } else {
                $product_type = get_post_meta($product_id, '_skyhshoso_product_type', true);
            }
            
            if ($product_type === 'skyhshoso_hosting') {
                $this->create_hosting_post($subscription, $order, $product, $parent_id);
            } elseif ($product_type === 'skyhshoso_domain') {
                $this->create_domain_post($subscription, $order, $product, $parent_id);
            } elseif ($product_type === 'skyhshoso_wp_site') {
                $this->create_wp_site_post($subscription, $order, $product, $parent_id);
            }
        }
    }

    private function create_hosting_post($subscription, $order, $product, $parent_id = 0) {
        $existing_posts = get_posts(array(
            'post_type'      => 'skyhshoso_hosting',
            'meta_key'       => 'skyhshoso_subscription_id',
            'meta_value'     => $subscription->get_id(),
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids'
        ));

        if (!empty($existing_posts)) return;

        $post_title = $product->get_name();
        $post_author = $order->get_customer_id();
        $post_data = array(
            'post_title'    => $post_title,
            'post_author'   => $post_author,
            'post_type'     => 'skyhshoso_hosting',
            'post_status'   => 'publish',
            'post_name'     => '' 
        );
        $post_id = wp_insert_post($post_data);
        
        if (is_wp_error($post_id)) {
            SkyHSHOSO_Logger::error( 'Hosting post creation failed for sub #' . $subscription->get_id() . ': ' . $post_id->get_error_message(), array( 'source' => 'subscription_handler' ) );
            return;
        } 
        
        $post_name = $post_id;
        wp_update_post(array('ID' => $post_id, 'post_name' => $post_name));
        
        $product_id = $parent_id ? $parent_id : $product->get_id();
        $hosting_plan = get_post_meta($product_id, '_skyhshoso_hosting_plan', true);
        $server_id = get_post_meta($product_id, '_skyhshoso_server_id', true);
        
        if ($parent_id) {
            $variation_hosting_plan = get_post_meta($product->get_id(), '_skyhshoso_hosting_plan', true);
            $variation_server_id = get_post_meta($product->get_id(), '_skyhshoso_server_id', true);
            if (!empty($variation_hosting_plan)) $hosting_plan = $variation_hosting_plan;
            if (!empty($variation_server_id)) $server_id = $variation_server_id;
        }
        
        $unique_id = strtolower(wp_generate_password(6, false, false));
        $options = get_option('skyhshoso_settings_group', array());
        $base_domain = isset($options['system_subdomain']) && !empty($options['system_subdomain']) ? $options['system_subdomain'] : 'cielocloud.xyz';
        
        $system_domain = $unique_id . '.' . ltrim($base_domain, '.');
        $username = 'cielo' . $unique_id;
        $temp_password = wp_generate_password(16, true, true);

        update_post_meta($post_id, '_skyhshoso_hosting_product_id', $product_id);
        if ($parent_id) update_post_meta($post_id, '_skyhshoso_variation_id', $product->get_id());

        update_post_meta($post_id, 'skyhshoso_subscription_id', $subscription->get_id());
        update_post_meta($post_id, 'skyhshoso_hosting_domain', $system_domain);
        update_post_meta($post_id, '_skyhshoso_system_domain', $system_domain);
        update_post_meta($post_id, 'skyhshoso_hosting_username', $username);
        update_post_meta($post_id, '_skyhshoso_hosting_temp_password', $temp_password);
        
        update_post_meta($post_id, 'skyhshoso_hosting_plan', $hosting_plan);
        update_post_meta($post_id, 'skyhshoso_server_id', $server_id);

        if ( function_exists( 'as_enqueue_async_action' ) ) {
            update_post_meta($post_id, '_skyhshoso_whm_provision_status', 'provisioning');
            as_enqueue_async_action( 'skyhshoso_background_provision_account', array( 'post_id' => $post_id, 'system_domain' => $system_domain ) );
        } else {
            $this->execute_server_provisioning( $post_id, $system_domain );
        }
    }

    /**
     * UNIVERSAL FACTORY PROVISIONING
     * Automatically deploys to WHM, HestiaCP, or WordOps based on the Server configuration.
     */
    public function execute_server_provisioning( $post_id, $system_domain ) {
        $server_id = get_post_meta($post_id, 'skyhshoso_server_id', true);
        $hosting_plan = get_post_meta($post_id, 'skyhshoso_hosting_plan', true);
        $username = get_post_meta($post_id, 'skyhshoso_hosting_username', true);
        $password = get_post_meta($post_id, '_skyhshoso_hosting_temp_password', true);

        if ($server_id && $hosting_plan) {
            
            // 1. Initialize the correct Driver via Factory
            $driver = SkyHSHOSO_Provider_Factory::get_driver($server_id);
            
            if (is_wp_error($driver)) {
                update_post_meta($post_id, '_skyhshoso_whm_provision_error', 'Connection Error: ' . $driver->get_error_message());
                update_post_meta($post_id, '_skyhshoso_whm_provision_status', 'failed');
                return;
            }

            // 2. Fetch the Customer Email
            $customer_id = get_post_field('post_author', $post_id);
            $user = get_userdata($customer_id);
            $email = $user ? $user->user_email : 'admin@' . $system_domain;

            // 3. Fire the Abstract Account Creation!
            $result = $driver->create_account($system_domain, $username, $password, $email, $hosting_plan);
            
            if (is_wp_error($result)) {
                update_post_meta($post_id, '_skyhshoso_whm_provision_error', 'Server Rejection: ' . $result->get_error_message());
                update_post_meta($post_id, '_skyhshoso_whm_provision_status', 'failed');
            } else {
                update_post_meta($post_id, '_skyhshoso_whm_provision_status', 'success');
                delete_post_meta($post_id, '_skyhshoso_whm_provision_error');

                // Send the welcome email
                if (class_exists('SkyHSHOSO_Emails')) {
                    SkyHSHOSO_Emails::send_provisioning($post_id, $username);
                }
            }

        } else {
            update_post_meta($post_id, '_skyhshoso_whm_provision_error', 'Missing Server ID or Hosting Plan on the WooCommerce Product.');
            update_post_meta($post_id, '_skyhshoso_whm_provision_status', 'failed');
        }
    }

    private function handle_subscription_resubscribe($new_subscription, $old_subscription) {
        $args = array(
            'post_type' => array('skyhshoso_hosting', 'skyhshoso_wp_site'),
            'meta_query' => array(
                array(
                    'key' => 'skyhshoso_subscription_id',
                    'value' => $old_subscription->get_id(),
                    'compare' => '='
                )
            ),
            'posts_per_page' => -1
        );
        $posts = get_posts($args);

        foreach ($posts as $post) {
            $post_id = $post->ID;
            $post_type = $post->post_type;

            update_post_meta($post_id, 'skyhshoso_subscription_id', $new_subscription->get_id());

            if ($post_type === 'skyhshoso_hosting') {
                $hosting_username = get_post_meta($post_id, 'skyhshoso_hosting_username', true);
                $server_id = get_post_meta($post_id, 'skyhshoso_server_id', true);

                $driver = SkyHSHOSO_Provider_Factory::get_driver($server_id);
                if (!is_wp_error($driver)) {
                    $driver->unsuspend_account($hosting_username);
                }
            } elseif ($post_type === 'skyhshoso_wp_site') {
                $this->handle_wp_site_resubscribe($post_id);
            }
        }
    }

    private function handle_wp_site_resubscribe($wp_site_id) {
        $provisioned = get_post_meta($wp_site_id, '_skyhshoso_wp_provisioned', true);
        if (empty($provisioned)) return;

        $doc_root    = get_post_meta($wp_site_id, '_skyhshoso_wp_doc_root', true);
        $server_id   = get_post_meta($wp_site_id, 'skyhshoso_server_id', true);
        $cpanel_user = get_post_meta($wp_site_id, 'skyhshoso_wp_cpanel_user', true);

        if (empty($doc_root) || !$server_id || !$cpanel_user) return;

        $whm_user  = get_post_meta($server_id, '_skyhshoso_whm_user_id', true);
        $whm_token = get_post_meta($server_id, '_skyhshoso_whm_token', true);
        $whm_host  = get_post_meta($server_id, '_skyhshoso_whm_host', true);

        if (empty($whm_user) || empty($whm_token) || empty($whm_host)) return;

        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-wordpress-manager.php';
        $manager = new SkyHSHOSO_WordPress_Manager($whm_user, $whm_token, $whm_host, $cpanel_user);
        $manager->unsuspend_wp_site($doc_root);
    }

    public function update_post_status_on_subscription_change($subscription, $new_status, $old_status) {
        $subscription_id = $subscription->get_id();
        $status_mapping = array(
            'active' => 'active',
            'on-hold' => 'on-hold',
            'cancelled' => 'cancelled',
            'expired' => 'expired',
            'pending' => 'pending',
            'pending-cancel' => 'pending-cancel',
        );
        $new_status = isset($status_mapping[$new_status]) ? $status_mapping[$new_status] : $new_status;
        
        $hosting_posts = get_posts(array(
            'post_type'      => 'skyhshoso_hosting',
            'posts_per_page' => -1,
            'meta_query'     => array(
                array(
                    'key'     => 'skyhshoso_subscription_id',
                    'value'   => $subscription_id,
                    'compare' => '=',
                ),
            ),
        ));

        foreach ($hosting_posts as $hosting_post) {
            $hosting_username = get_post_meta($hosting_post->ID, 'skyhshoso_hosting_username', true);
            $server_id = get_post_meta($hosting_post->ID, 'skyhshoso_server_id', true);
            
            $driver = SkyHSHOSO_Provider_Factory::get_driver($server_id);

            if (!is_wp_error($driver)) {
                if ($new_status === 'on-hold' && $old_status === 'active') {
                    $result = $driver->suspend_account($hosting_username, 'Subscription on-hold');
                    if ($result && !is_wp_error($result)) {
                        SkyHSHOSO_Logger::info( 'Hosting account ' . $hosting_username . ' suspended (on-hold)', array( 'source' => 'subscription_handler' ) );
                    }
                } elseif ($new_status === 'active' && in_array($old_status, ['on-hold', 'expired', 'cancelled'])) {
                    $result = $driver->unsuspend_account($hosting_username);
                    if ($result && !is_wp_error($result)) {
                        SkyHSHOSO_Logger::info( 'Hosting account ' . $hosting_username . ' reactivated', array( 'source' => 'subscription_handler' ) );
                    }
                } elseif ($new_status === 'expired' || $new_status === 'cancelled') {
                    $is_terminated = get_post_meta($hosting_post->ID, 'skyhshoso_hosting_terminated', true);
                    if ($is_terminated !== 'yes') {
                        $result = $driver->suspend_account($hosting_username, 'Subscription cancelled');
                        if ($result && !is_wp_error($result)) {
                            SkyHSHOSO_Logger::info( 'Hosting account ' . $hosting_username . ' suspended (cancelled)', array( 'source' => 'subscription_handler' ) );
                        }
                    }
                }
            } else {
                SkyHSHOSO_Logger::warning( 'Driver missing for server ID ' . $server_id . ' — cannot update hosting account status', array( 'source' => 'subscription_handler' ) );
            }
        }
        $this->update_wp_site_posts_status($subscription_id, $new_status, $old_status);
        $this->update_domain_posts_status($subscription_id, $new_status);
    }

    private function update_domain_posts_status($subscription_id, $new_status) {
        // Domain status handled dynamically via shortcode elsewhere
    }

    private function update_wp_site_posts_status($subscription_id, $new_status, $old_status = '') {
        $wp_site_posts = get_posts(array(
            'post_type'      => 'skyhshoso_wp_site',
            'posts_per_page' => -1,
            'meta_query'     => array(
                array(
                    'key'     => 'skyhshoso_subscription_id',
                    'value'   => $subscription_id,
                    'compare' => '=',
                ),
            ),
        ));

        foreach ( $wp_site_posts as $post ) {
            $wp_site_id = $post->ID;
            $provisioned = get_post_meta( $wp_site_id, '_skyhshoso_wp_provisioned', true );
            if ( empty( $provisioned ) ) continue;

            $doc_root = get_post_meta( $wp_site_id, '_skyhshoso_wp_doc_root', true );
            if ( empty( $doc_root ) ) continue;

            $server_id   = get_post_meta( $wp_site_id, 'skyhshoso_server_id', true );
            $cpanel_user = get_post_meta( $wp_site_id, 'skyhshoso_wp_cpanel_user', true );

            if ( ! $server_id || ! $cpanel_user ) continue;

            $whm_user  = get_post_meta( $server_id, '_skyhshoso_whm_user_id', true );
            $whm_token = get_post_meta( $server_id, '_skyhshoso_whm_token', true );
            $whm_host  = get_post_meta( $server_id, '_skyhshoso_whm_host', true );

            if ( empty( $whm_user ) || empty( $whm_token ) || empty( $whm_host ) ) continue;

            require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-wordpress-manager.php';
            $manager = new SkyHSHOSO_WordPress_Manager( $whm_user, $whm_token, $whm_host, $cpanel_user );

            if ( $new_status === 'on-hold' && $old_status === 'active' ) {
                $manager->suspend_wp_site( $doc_root );
            } elseif ( $new_status === 'active' && ( $old_status === 'on-hold' || $old_status === 'expired' || $old_status === 'cancelled' ) ) {
                $manager->unsuspend_wp_site( $doc_root );
            } elseif ( $new_status === 'expired' || $new_status === 'cancelled' ) {
                $manager->suspend_wp_site( $doc_root );
            }
        }
    }

    public function handle_subscription_renewal( $subscription, $last_order ) {
        $subscription_id = $subscription->get_id();

        $row = SkyHSHOSO_Subscription_DB::get( $subscription_id );
        if ( ! $row ) return;

        $product_id = $row->product_id;
        $product    = wc_get_product( $product_id );
        if ( ! $product ) return;

        $product_type = get_post_meta( $product_id, '_skyhshoso_product_type', true );

        if ( $product_type === 'skyhshoso_domain' ) {
            $result = $this->renew_domain( $subscription_id, $product );
            $this->update_domain_post_after_renewal( $subscription_id, $result['success'], $result['message'] );
        }
    }

    private function renew_domain($subscription_id, $product) {
        $domain = $product->get_name();
        
        try {
            $enom = SkyHSHOSO_Enom_Integration();
            $result = $enom->renew_domain($domain);
            
            if ($result) {
                return array('success' => true, 'message' => "Domain $domain renewed successfully.");
            } else {
                throw new Exception("Domain renewal failed. No specific error returned.");
            }
        } catch (Exception $e) {
            $error_message = $e->getMessage();
            SkyHSHOSO_Logger::error( 'Domain renewal failed for ' . $domain . ': ' . $error_message, array( 'source' => 'subscription_handler' ) );
            return array('success' => false, 'message' => $error_message);
        }
    }

    private function update_domain_post_after_renewal($subscription_id, $success, $error_message = '') {
        $args = array(
            'post_type'      => 'skyhshoso_domain',
            'posts_per_page' => -1,
            'meta_query'     => array(
                array(
                    'key'     => 'skyhshoso_subscription_id',
                    'value'   => $subscription_id,
                    'compare' => '=',
                ),
            ),
        );
        $posts = get_posts($args);
        foreach ($posts as $post) {
            if ($success) {
                update_post_meta($post->ID, 'skyhshoso_domain_renewal_status', 'success');
                update_post_meta($post->ID, 'skyhshoso_last_renewal_date', current_time('mysql'));
                $next_renewal_date = gmdate('Y-m-d H:i:s', strtotime('+1 year'));
                update_post_meta($post->ID, 'skyhshoso_next_renewal_date', $next_renewal_date);
            } else {
                update_post_meta($post->ID, 'skyhshoso_domain_renewal_status', 'failed');
                update_post_meta($post->ID, 'skyhshoso_domain_renewal_error', $error_message);
                update_post_meta($post->ID, 'skyhshoso_last_renewal_attempt', current_time('mysql'));
            }
        }
    }

    public function handle_subscription_switch($subscription, $order = null) {
        $new_subscription_id = $subscription->get_id();
        $old_subscription_id = null;
        $switch_meta = $subscription->get_meta('_subscription_switch_data');
        if ( is_array( $switch_meta ) ) {
            foreach ($switch_meta as $old_sub_id => $switch_data) {
                $old_subscription_id = $old_sub_id;
                break;
            }
        }

        if (!$old_subscription_id) {
            $old_subscription_id = $subscription->get_id();
        }

        $new_product = null;
        if ( $order instanceof WC_Order ) {
            foreach ( $order->get_items() as $item_id => $item ) {
                $new_product = $item->get_product();
                if ( $new_product ) break;
            }
        }

        if ( ! $new_product ) {
            $subscription_items = $subscription->get_items();
            foreach ($subscription_items as $item_id => $item) {
                if ($item->get_type() === 'line_item') {
                    $new_product = $item->get_product();
                    break;
                }
            }
        }

        if (!$new_product) return;

        $product_id = $new_product->get_id();
        $variation_id = $new_product->is_type('variation') ? $product_id : 0;
        $parent_id = $variation_id ? $new_product->get_parent_id() : $product_id;

        $new_hosting_plan = get_post_meta($variation_id ? $variation_id : $parent_id, '_skyhshoso_hosting_plan', true);
        $new_server_id = get_post_meta($variation_id ? $variation_id : $parent_id, '_skyhshoso_server_id', true);
        
        if (empty($new_hosting_plan) && $variation_id) {
            $new_hosting_plan = get_post_meta($parent_id, '_skyhshoso_hosting_plan', true);
        }
        if (empty($new_server_id) && $variation_id) {
            $new_server_id = get_post_meta($parent_id, '_skyhshoso_server_id', true);
        }

        $args = array(
            'post_type'  => 'skyhshoso_hosting',
            'meta_query' => array(
                array(
                    'key'     => 'skyhshoso_subscription_id',
                    'value'   => $old_subscription_id,
                    'compare' => '=',
                ),
            ),
        );
        $query = new WP_Query($args);

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();

                update_post_meta($post_id, 'skyhshoso_subscription_id', $new_subscription_id);

                $hosting_username = get_post_meta($post_id, 'skyhshoso_hosting_username', true);
                $current_hosting_plan = get_post_meta($post_id, 'skyhshoso_hosting_plan', true);
                $current_server_id = get_post_meta($post_id, 'skyhshoso_server_id', true);

                if ($new_hosting_plan && $new_hosting_plan !== $current_hosting_plan) {
                    $driver = SkyHSHOSO_Provider_Factory::get_driver($current_server_id);
                    if (!is_wp_error($driver) && method_exists($driver, 'change_package')) {
                        if ($driver->change_package($hosting_username, $new_hosting_plan)) {
                            update_post_meta($post_id, 'skyhshoso_hosting_plan', $new_hosting_plan);
                        }
                    }
                }

                if ($new_server_id && $new_server_id !== $current_server_id) {
                    update_post_meta($post_id, 'skyhshoso_server_id', $new_server_id);
                }

                $new_title = $new_product->get_name();
                wp_update_post(array(
                    'ID' => $post_id,
                    'post_title' => $new_title
                ));
            }
        }

        wp_reset_postdata();
    }

    private function create_domain_post($subscription, $order, $product, $parent_id = 0) { /* Handled via Checkout */ }
    private function create_wp_site_post($subscription, $order, $product, $parent_id = 0) { /* WP specific abstraction coming later */ }
}

function SkyHSHOSO_Subscription_Handler() {
    return SkyHSHOSO_Subscription_Handler::instance();
}

SkyHSHOSO_Subscription_Handler();