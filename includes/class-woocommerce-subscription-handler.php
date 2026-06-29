<?php

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

        // SkyHS custom subscription hooks — fired by class-skyhshoso-subscription-checkout.php
        // and class-skyhshoso-subscription-cron.php.
        add_action( 'skyhshoso_subscription_created', array( $this, 'handle_subscription_creation_or_resubscribe' ), 10, 3 );
        add_action( 'skyhshoso_subscription_status_updated', array( $this, 'update_post_status_on_subscription_change' ), 10, 3 );
        add_action( 'skyhshoso_subscription_renewed', array( $this, 'handle_subscription_renewal' ), 10, 2 );

        // Plan switch is now a manual admin action — hook kept for forward compatibility.
        add_action( 'skyhshoso_subscription_switch_completed', array( $this, 'handle_subscription_switch' ), 10, 2 );
    }

    /**
     * Declare compatibility with HPOS
     */
    public function declare_hpos_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }

    /**
     * Handle new subscription created at checkout.
     * $order may be a WC_Order or WC_Order_Item_Product depending on caller.
     */
    public function handle_subscription_creation_or_resubscribe( $subscription, $order = null, $item_or_cart = null ) {

        $old_subscription_id = $subscription->get_meta( '_subscription_resubscribe' );

        if ( $old_subscription_id ) {
            $old_subscription = skyhshoso_get_subscription( $old_subscription_id );
            if ( $old_subscription ) {
                $this->handle_subscription_resubscribe( $subscription, $old_subscription );
            }
        } else {
            // $order may be a WC_Order (from our checkout handler) or null (from admin manual creation).
            if ( $order instanceof WC_Order ) {
                $this->create_posts_for_subscription( $subscription, $order, $item_or_cart );
            }
            // If $order is null (admin-side creation), the hosting post is already
            // being created by the Hosting Manager — no need to duplicate it here.
        }
    }
    
    private function create_posts_for_subscription($subscription, $order, $item_or_cart) {
        // Build the list of items to process.
        // Our checkout handler passes a single WC_Order_Item_Product;
        // may also get a cart object or null — fall back to order items.
        $items = array();
        if ( $item_or_cart instanceof WC_Order_Item_Product ) {
            $items = array( $item_or_cart );
        } elseif ( $order instanceof WC_Order ) {
            $items = $order->get_items();
        }

        foreach ($items as $item) {
            $product = $item->get_product();
            if ( ! $product ) {
                continue;
            }
            $product_id = $product->get_id();
            $parent_id = $product->get_parent_id();
            
            // Get product type from parent if it's a variation
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
            } else {
            }
        }
    }

    private function create_hosting_post($subscription, $order, $product, $parent_id = 0) {
        
        // Check if a hosting post already exists for this subscription
        $existing_posts = get_posts(array(
            'post_type'      => 'skyhshoso_hosting',
            'meta_key'       => 'skyhshoso_subscription_id',
            'meta_value'     => $subscription->get_id(),
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids'
        ));

        if (!empty($existing_posts)) {
            return; // A hosting post already exists, don't duplicate.
        }

        $post_title = $product->get_name();
        $post_author = $order->get_customer_id();
        $post_data = array(
            'post_title'    => $post_title,
            'post_author'   => $post_author,
            'post_type'     => 'skyhshoso_hosting',
            'post_status'   => 'publish',
            'post_name'     => '' // This will be set after post creation
        );
		$post_id = wp_insert_post($post_data);
		
		if (is_wp_error($post_id)) {
			SkyHSHOSO_Logger::error( 'Hosting post creation failed for subscription #' . $subscription->get_id() . ': ' . $post_id->get_error_message(), array( 'source' => 'subscription_handler' ) );
		} else {
            
            // Set the post_name (slug) to be the post ID
            $post_name = $post_id;
            wp_update_post(array(
                'ID' => $post_id,
                'post_name' => $post_name
            ));
            
            // Get hosting plan and server ID from the correct product (parent or variation)
            $product_id = $parent_id ? $parent_id : $product->get_id();
            $hosting_plan = get_post_meta($product_id, '_skyhshoso_hosting_plan', true);
            $server_id = get_post_meta($product_id, '_skyhshoso_server_id', true);
            
            // If it's a variation, check if it has its own hosting plan or server ID
            if ($parent_id) {
                $variation_hosting_plan = get_post_meta($product->get_id(), '_skyhshoso_hosting_plan', true);
                $variation_server_id = get_post_meta($product->get_id(), '_skyhshoso_server_id', true);
                
                if (!empty($variation_hosting_plan)) {
                    $hosting_plan = $variation_hosting_plan;
                }
                if (!empty($variation_server_id)) {
                    $server_id = $variation_server_id;
                }
            }
            
            
            // Add custom fields to the post
            update_post_meta($post_id, 'skyhshoso_subscription_id', $subscription->get_id());
            update_post_meta($post_id, 'skyhshoso_hosting_domain', '');
            update_post_meta($post_id, 'skyhshoso_hosting_plan', $hosting_plan);
            update_post_meta($post_id, 'skyhshoso_server_id', $server_id);
            
        }
    }

    private function create_domain_post($subscription, $order, $product, $parent_id = 0) {
        
        // Check if a domain post already exists for this subscription
        $existing_posts = get_posts(array(
            'post_type'      => 'skyhshoso_domain',
            'meta_key'       => 'skyhshoso_subscription_id',
            'meta_value'     => $subscription->get_id(),
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids'
        ));

        if (!empty($existing_posts)) {
            return; // A domain post already exists, don't duplicate.
        }

        $product_id = $product->get_id();
        $is_transfer = get_post_meta($product_id, '_skyhshoso_domain_transfer', true) === 'yes';

        $post_title = $product->get_name();
        if ($is_transfer) {
            // Strip " (Transfer)" suffix to get the actual domain name
            $post_title = str_replace(' (Transfer)', '', $post_title);
        }
        $post_author = $order->get_customer_id();
        $post_data = array(
            'post_title'    => $post_title,
            'post_author'   => $post_author,
            'post_type'     => 'skyhshoso_domain',
            'post_status'   => 'publish'
        );
        
		$post_id = wp_insert_post($post_data);
		
		if (is_wp_error($post_id)) {
			SkyHSHOSO_Logger::error( 'Domain post creation failed for subscription #' . $subscription->get_id() . ': ' . $post_id->get_error_message(), array( 'source' => 'subscription_handler' ) );
			return;
		}
		
		update_post_meta($post_id, 'skyhshoso_subscription_id', $subscription->get_id());
		update_post_meta($post_id, 'skyhshoso_domain_name', $post_title);
		update_post_meta($post_id, '_skyhshoso_domain_product_id', $product_id);
		
		try {
            $enom = SkyHSHOSO_Enom_Integration();
            $customer = new WC_Customer($order->get_customer_id());
            
            $phone_number = $customer->get_billing_phone();
            if (empty($phone_number)) {
                throw new Exception("Customer phone number is missing. Please update billing details.");
            }

            $client_details = array(
                'first_name' => $customer->get_billing_first_name(),
                'last_name' => $customer->get_billing_last_name(),
                'organization' => $customer->get_billing_company(),
                'address1' => $customer->get_billing_address_1(),
                'city' => $customer->get_billing_city(),
                'state_province' => $customer->get_billing_state(),
                'postal_code' => $customer->get_billing_postcode(),
                'country' => $customer->get_billing_country(),
                'email' => $customer->get_billing_email(),
                'phone' => $phone_number
            );
            
            $domain = $post_title;
            $domain_parts = explode('.', $domain, 2);
            
            if (count($domain_parts) !== 2) {
                throw new Exception("Invalid domain format: $domain");
            }

            if ($is_transfer) {
                $auth_code = get_post_meta($product_id, '_skyhshoso_domain_auth_code', true);
                if (empty($auth_code)) {
                    throw new Exception("EPP authorization code is missing for domain transfer.");
                }

                update_post_meta($post_id, 'skyhshoso_domain_transfer_status', 'pending');
                update_post_meta($post_id, 'skyhshoso_domain_auth_code', $auth_code);

                $result = $enom->initiate_transfer_domain($domain_parts[0], $domain_parts[1], $auth_code, 1, $client_details);

                if ($result && !empty($result['TransferOrderID'])) {
                    update_post_meta($post_id, 'skyhshoso_domain_transfer_status', 'processing');
                    update_post_meta($post_id, 'skyhshoso_domain_transfer_order_id', $result['TransferOrderID']);
                    update_post_meta($post_id, 'skyhshoso_domain_transfer_amount', $result['TotalCharged']);
                    update_post_meta($post_id, 'skyhshoso_domain_purchase_status', 'success');
                    update_post_meta($post_id, 'skyhshoso_domain_transfer_detail', $result['Status']);
                } else {
                    throw new Exception("Domain transfer initiation failed. No specific error returned.");
                }
            } else {
                $result = $enom->purchase_domain($domain_parts[0], $domain_parts[1], 1, $client_details);
                
                if ($result) {
                    update_post_meta($post_id, 'skyhshoso_domain_purchase_status', 'success');
                    $enom->disable_auto_renew($domain_parts[0], $domain_parts[1]);
                    
                    $nameservers = get_option( 'skyhshoso_enom_default_nameservers', array() );
                    if ( ! empty( array_filter( (array) $nameservers ) ) ) {
                        $enom->update_nameservers_after_purchase($domain, $nameservers);
                    }
                } else {
                    throw new Exception("Domain purchase failed. No specific error returned.");
                }
            }
            
		} catch (Exception $e) {
			update_post_meta($post_id, 'skyhshoso_domain_purchase_status', 'failed');
			update_post_meta($post_id, 'skyhshoso_domain_purchase_error', $e->getMessage());
			if ($is_transfer) {
				update_post_meta($post_id, 'skyhshoso_domain_transfer_status', 'failed');
			}
			SkyHSHOSO_Logger::error( 'Domain ' . ( $is_transfer ? 'transfer' : 'purchase' ) . ' failed for ' . $post_title . ' (order #' . $order->get_id() . '): ' . $e->getMessage(), array( 'source' => 'subscription_handler' ) );
		}
    }

    private function create_wp_site_post($subscription, $order, $product, $parent_id = 0) {
        $existing_posts = get_posts(array(
            'post_type'      => 'skyhshoso_wp_site',
            'meta_key'       => 'skyhshoso_subscription_id',
            'meta_value'     => $subscription->get_id(),
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids'
        ));

        if ( ! empty( $existing_posts ) ) {
            return;
        }

        $post_title  = $product->get_name();
        $post_author = $order->get_customer_id();
        $post_id     = wp_insert_post( array(
            'post_title'  => $post_title,
            'post_author' => $post_author,
            'post_type'   => 'skyhshoso_wp_site',
            'post_status' => 'publish',
        ) );

		if ( is_wp_error( $post_id ) ) {
			SkyHSHOSO_Logger::error( 'WP site post creation failed for subscription #' . $subscription->get_id() . ': ' . $post_id->get_error_message(), array( 'source' => 'subscription_handler' ) );
			return;
		}

		$product_id = $parent_id ? $parent_id : $product->get_id();
        $server_id  = get_post_meta( $product_id, '_skyhshoso_server_id', true );
        $wp_host_user = get_post_meta( $product_id, '_skyhshoso_wp_host_user', true );
        $wp_storage   = get_post_meta( $product_id, '_skyhshoso_wp_storage', true ) ?: 500;
        $wp_memory    = get_post_meta( $product_id, '_skyhshoso_wp_memory', true ) ?: '64M';

        // Check variation for overrides
        if ( $parent_id ) {
            $var_wp_host_user = get_post_meta( $product->get_id(), '_skyhshoso_wp_host_user', true );
            if ( ! empty( $var_wp_host_user ) ) {
                $wp_host_user = $var_wp_host_user;
            }
            $var_server_id = get_post_meta( $product->get_id(), '_skyhshoso_server_id', true );
            if ( ! empty( $var_server_id ) ) {
                $server_id = $var_server_id;
            }
            $var_storage = get_post_meta( $product->get_id(), '_skyhshoso_wp_storage', true );
            if ( ! empty( $var_storage ) ) {
                $wp_storage = $var_storage;
            }
            $var_memory = get_post_meta( $product->get_id(), '_skyhshoso_wp_memory', true );
            if ( ! empty( $var_memory ) ) {
                $wp_memory = $var_memory;
            }
        }

        update_post_meta( $post_id, 'skyhshoso_subscription_id',     $subscription->get_id() );
        update_post_meta( $post_id, 'skyhshoso_server_id',          $server_id );
        update_post_meta( $post_id, 'skyhshoso_wp_cpanel_user',      $wp_host_user );
        update_post_meta( $post_id, 'skyhshoso_wp_domain',           '' );
        update_post_meta( $post_id, 'skyhshoso_wp_db_name',          '' );
        update_post_meta( $post_id, 'skyhshoso_wp_db_user',          '' );
        update_post_meta( $post_id, 'skyhshoso_wp_admin_user',       '' );
        update_post_meta( $post_id, 'skyhshoso_wp_admin_pass',       '' );
        update_post_meta( $post_id, '_skyhshoso_wp_site_url',        '' );
        update_post_meta( $post_id, '_skyhshoso_hosting_product_id', $product_id );
        update_post_meta( $post_id, '_skyhshoso_wp_storage',         $wp_storage );
        update_post_meta( $post_id, '_skyhshoso_wp_memory',          $wp_memory );

        // Auto-generate UUID
        if ( class_exists( 'SkyHSHOSO_UUID' ) ) {
            SkyHSHOSO_UUID::set_post_uuid( $post_id );
        }
    }

    private function handle_subscription_resubscribe($new_subscription, $old_subscription) {

        // Get all posts associated with the old subscription
        $args = array(
            'post_type' => array('skyhshoso_hosting', 'skyhshoso_wp_site'),
            // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin-only resubscribe lookup.
            'meta_query' => array(
                array(
                    'key' => 'skyhshoso_subscription_id',
                    'value' => $old_subscription->get_id(),
                    'compare' => '='
                )
            ),
            'posts_per_page' => -1
        );
        // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
        $posts = get_posts($args);

        foreach ($posts as $post) {
            $post_id = $post->ID;
            $post_type = $post->post_type;


            // Update the subscription_id field with the new subscription ID
            update_post_meta($post_id, 'skyhshoso_subscription_id', $new_subscription->get_id());

            if ($post_type === 'skyhshoso_hosting') {
                $hosting_username = get_post_meta($post_id, 'skyhshoso_hosting_username', true);
                $server_id = get_post_meta($post_id, 'skyhshoso_server_id', true);

                // Get WHM credentials from the server post type
                $whm_username = get_post_meta($server_id, '_skyhshoso_whm_user_id', true);
                $whm_token = get_post_meta($server_id, '_skyhshoso_whm_token', true);
                $whm_host = get_post_meta($server_id, '_skyhshoso_whm_host', true);

                if ($whm_username && $whm_token && $whm_host) {
                    $whm_api = new SkyHSHOSO_WHM_API($whm_username, $whm_token, $whm_host);


                    if ($whm_api->reactivate_account($hosting_username)) {
                    
                    } else {
                        // You might want to add some error handling here
                    }
                } else {
                    // You might want to add some error handling here
                }
            } elseif ($post_type === 'skyhshoso_wp_site') {
                $this->handle_wp_site_resubscribe($post_id);
            }

        }

    }

    /**
     * Handle resubscribe for a WP site — reactivate it.
     */
    private function handle_wp_site_resubscribe($wp_site_id) {
        $provisioned = get_post_meta($wp_site_id, '_skyhshoso_wp_provisioned', true);
        if (empty($provisioned)) {
            return;
        }

        $doc_root    = get_post_meta($wp_site_id, '_skyhshoso_wp_doc_root', true);
        $server_id   = get_post_meta($wp_site_id, 'skyhshoso_server_id', true);
        $cpanel_user = get_post_meta($wp_site_id, 'skyhshoso_wp_cpanel_user', true);

        if (empty($doc_root) || !$server_id || !$cpanel_user) {
            return;
        }

        $whm_user  = get_post_meta($server_id, '_skyhshoso_whm_user_id', true);
        $whm_token = get_post_meta($server_id, '_skyhshoso_whm_token', true);
        $whm_host  = get_post_meta($server_id, '_skyhshoso_whm_host', true);

        if (empty($whm_user) || empty($whm_token) || empty($whm_host)) {
            return;
        }

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
        
        // Get all hosting posts associated with this subscription
        // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin-only lookup limited to specific subscription.
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
        // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query

        foreach ($hosting_posts as $hosting_post) {
            $hosting_username = get_post_meta($hosting_post->ID, 'skyhshoso_hosting_username', true);
            $server_id = get_post_meta($hosting_post->ID, 'skyhshoso_server_id', true);
            
            // Get WHM credentials from the server post type
            $whm_username = get_post_meta($server_id, '_skyhshoso_whm_user_id', true);
            $whm_token = get_post_meta($server_id, '_skyhshoso_whm_token', true);
            $whm_host = get_post_meta($server_id, '_skyhshoso_whm_host', true);

			if ($whm_username && $whm_token && $whm_host) {
				$whm_api = new SkyHSHOSO_WHM_API($whm_username, $whm_token, $whm_host);

				if ($new_status === 'on-hold' && $old_status === 'active') {
					$result = $whm_api->suspend_account($hosting_username);
					if ($result) {
						SkyHSHOSO_Logger::info( 'Hosting account ' . $hosting_username . ' suspended via WHM (subscription status change to on-hold)', array( 'source' => 'subscription_handler' ) );
					} else {
						SkyHSHOSO_Logger::error( 'Failed to suspend hosting account ' . $hosting_username . ' via WHM', array( 'source' => 'subscription_handler' ) );
					}
				} elseif ($new_status === 'active' && ($old_status === 'on-hold' || $old_status === 'expired' || $old_status === 'cancelled')) {
					$result = $whm_api->reactivate_account($hosting_username);
					if ($result) {
						SkyHSHOSO_Logger::info( 'Hosting account ' . $hosting_username . ' reactivated via WHM (subscription status change to active)', array( 'source' => 'subscription_handler' ) );
					} else {
						SkyHSHOSO_Logger::error( 'Failed to reactivate hosting account ' . $hosting_username . ' via WHM', array( 'source' => 'subscription_handler' ) );
					}
				} elseif ($new_status === 'expired' || $new_status === 'cancelled') {
					$is_terminated = get_post_meta($hosting_post->ID, 'skyhshoso_hosting_terminated', true);
					if ($is_terminated !== 'yes') {
						$result = $whm_api->suspend_account($hosting_username);
						if ($result) {
							SkyHSHOSO_Logger::info( 'Hosting account ' . $hosting_username . ' suspended via WHM (subscription ' . $new_status . ')', array( 'source' => 'subscription_handler' ) );
						} else {
							SkyHSHOSO_Logger::error( 'Failed to suspend hosting account ' . $hosting_username . ' via WHM on ' . $new_status, array( 'source' => 'subscription_handler' ) );
						}
					}
				}
			} else {
				SkyHSHOSO_Logger::warning( 'WHM credentials missing for server ID ' . $server_id . ' — cannot update hosting account for subscription status change', array( 'source' => 'subscription_handler' ) );
			}
            
        }
        // Process WP site posts for this subscription
        $this->update_wp_site_posts_status($subscription_id, $new_status, $old_status);

        // Process domain posts
    $this->update_domain_posts_status($subscription_id, $new_status);
    }

    private function update_domain_posts_status($subscription_id, $new_status) {
        // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin-only domain lookup.
        $domain_posts = get_posts(array(
            'post_type'      => 'skyhshoso_domain',
            'posts_per_page' => -1,
            'meta_query'     => array(
                array(
                    'key'     => 'skyhshoso_subscription_id',
                    'value'   => $subscription_id,
                    'compare' => '=',
                ),
            ),
        ));
        // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
        
        foreach ($domain_posts as $domain_post) {
          
        }
    }

    /**
     * Update WP site posts status based on subscription status changes.
     * Suspends/reactivates WordPress installations via WHM/cPanel API.
     */
    private function update_wp_site_posts_status($subscription_id, $new_status, $old_status = '') {
        // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
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
        // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query

        foreach ( $wp_site_posts as $post ) {
            $wp_site_id = $post->ID;
            $provisioned = get_post_meta( $wp_site_id, '_skyhshoso_wp_provisioned', true );
            if ( empty( $provisioned ) ) {
                continue;
            }

            $doc_root = get_post_meta( $wp_site_id, '_skyhshoso_wp_doc_root', true );
            if ( empty( $doc_root ) ) {
                continue;
            }

            $server_id   = get_post_meta( $wp_site_id, 'skyhshoso_server_id', true );
            $cpanel_user = get_post_meta( $wp_site_id, 'skyhshoso_wp_cpanel_user', true );

            if ( ! $server_id || ! $cpanel_user ) {
                continue;
            }

            $whm_user  = get_post_meta( $server_id, '_skyhshoso_whm_user_id', true );
            $whm_token = get_post_meta( $server_id, '_skyhshoso_whm_token', true );
            $whm_host  = get_post_meta( $server_id, '_skyhshoso_whm_host', true );

            if ( empty( $whm_user ) || empty( $whm_token ) || empty( $whm_host ) ) {
                continue;
            }

            require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-wordpress-manager.php';
            $manager = new SkyHSHOSO_WordPress_Manager( $whm_user, $whm_token, $whm_host, $cpanel_user );

			if ( $new_status === 'on-hold' && $old_status === 'active' ) {
				$result = $manager->suspend_wp_site( $doc_root );
				if ( ! $result ) {
					SkyHSHOSO_Logger::error( 'Failed to suspend WP site #' . $wp_site_id . ' (subscription status change to on-hold)', array( 'source' => 'subscription_handler' ) );
				}
			} elseif ( $new_status === 'active' && ( $old_status === 'on-hold' || $old_status === 'expired' || $old_status === 'cancelled' ) ) {
				$result = $manager->unsuspend_wp_site( $doc_root );
				if ( ! $result ) {
					SkyHSHOSO_Logger::error( 'Failed to unsuspend WP site #' . $wp_site_id . ' (subscription status change to active)', array( 'source' => 'subscription_handler' ) );
				}
			} elseif ( $new_status === 'expired' || $new_status === 'cancelled' ) {
				$result = $manager->suspend_wp_site( $doc_root );
				if ( ! $result ) {
					SkyHSHOSO_Logger::error( 'Failed to suspend WP site #' . $wp_site_id . ' (subscription ' . $new_status . ')', array( 'source' => 'subscription_handler' ) );
				}
			}
        }
    }

    /**
     * Handle subscription renewal payment — renew domain via eNom if applicable.
     *
     * @param SkyHSHOSO_Subscription $subscription
     * @param WC_Order|null          $last_order  The renewal WC order (may be null for free subs).
     */
    public function handle_subscription_renewal( $subscription, $last_order ) {
        $subscription_id = $subscription->get_id();

        // Determine product IDs to check from the subscription DB row.
        $row = SkyHSHOSO_Subscription_DB::get( $subscription_id );
        if ( ! $row ) {
            return;
        }

        $product_id = $row->product_id;
        $product    = wc_get_product( $product_id );
        if ( ! $product ) {
            return;
        }

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
                return array(
                    'success' => true,
                    'message' => "Domain $domain renewed successfully."
                );
            } else {
                throw new Exception("Domain renewal failed. No specific error returned.");
            }
		} catch (Exception $e) {
			$error_message = $e->getMessage();
			SkyHSHOSO_Logger::error( 'Domain renewal failed for ' . $domain . ' (subscription #' . $subscription_id . '): ' . $error_message, array( 'source' => 'subscription_handler' ) );
			return array(
				'success' => false,
				'message' => $error_message
			);
		}
    }

    private function update_domain_post_after_renewal($subscription_id, $success, $error_message = '') {
        // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Renewal update limited dataset.
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
        // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
        
        $posts = get_posts($args);
        
        foreach ($posts as $post) {
            if ($success) {
                update_post_meta($post->ID, 'skyhshoso_domain_renewal_status', 'success');
                update_post_meta($post->ID, 'skyhshoso_last_renewal_date', current_time('mysql'));
                
                // Calculate and store next renewal date (1 year from now)
                $next_renewal_date = gmdate('Y-m-d H:i:s', strtotime('+1 year'));
                update_post_meta($post->ID, 'skyhshoso_next_renewal_date', $next_renewal_date);
            } else {
                update_post_meta($post->ID, 'skyhshoso_domain_renewal_status', 'failed');
                update_post_meta($post->ID, 'skyhshoso_domain_renewal_error', $error_message);
                update_post_meta($post->ID, 'skyhshoso_last_renewal_attempt', current_time('mysql'));
            }
        }
    }

    private function switch_log( $message ) {
    }

    public function handle_subscription_switch($subscription, $order = null) {
        $this->switch_log( '=== handle_subscription_switch START ===' );
        $this->switch_log( 'Subscription class: ' . get_class( $subscription ) . ', ID: ' . $subscription->get_id() );
        $this->switch_log( 'Order: ' . ( $order instanceof WC_Order ? 'WC_Order #' . $order->get_id() : ( $order ? get_class( $order ) . ' #' . $order->get_id() : 'null' ) ) );

        $new_subscription_id = $subscription->get_id();

        // Get the old subscription ID from the switch data
        $old_subscription_id = null;
        $switch_meta = $subscription->get_meta('_subscription_switch_data');
        $this->switch_log( 'Switch meta: ' . ( $switch_meta ? wp_json_encode( $switch_meta ) : 'none' ) );
        if ( is_array( $switch_meta ) ) {
            foreach ($switch_meta as $old_sub_id => $switch_data) {
                $old_subscription_id = $old_sub_id;
                break;
            }
        }

        if (!$old_subscription_id) {
            $this->switch_log( 'No old sub ID in meta, using current sub ID: ' . $subscription->get_id() );
            $old_subscription_id = $subscription->get_id();
        }
        $this->switch_log( 'old_subscription_id=' . $old_subscription_id . ', new_subscription_id=' . $new_subscription_id );


        // Get the new product from the switch order if provided, otherwise from the subscription
        $new_product = null;
        if ( $order instanceof WC_Order ) {
            $this->switch_log( 'Looking for product in order items' );
            foreach ( $order->get_items() as $item_id => $item ) {
                $new_product = $item->get_product();
                $this->switch_log( '  Order item #' . $item_id . ': ' . ( $new_product ? 'product_id=' . $new_product->get_id() : 'null' ) );
                if ( $new_product ) {
                    break;
                }
            }
        }

        if ( ! $new_product ) {
            $this->switch_log( 'No product in order, checking subscription items' );
            $subscription_items = $subscription->get_items();
            foreach ($subscription_items as $item_id => $item) {
                $this->switch_log( '  Sub item #' . $item_id . ': type=' . $item->get_type() );
                if ($item->get_type() === 'line_item') {
                    $new_product = $item->get_product();
                    if ( $new_product ) {
                        $this->switch_log( '  Found product: ' . $new_product->get_id() );
                    }
                    break;
                }
            }
        }

        if (!$new_product) {
            $this->switch_log( 'FAIL: No new product found, exiting' );
            return;
        }

        $product_id = $new_product->get_id();
        $variation_id = $new_product->is_type('variation') ? $product_id : 0;
        $parent_id = $variation_id ? $new_product->get_parent_id() : $product_id;
        $this->switch_log( 'New product: product_id=' . $product_id . ', variation_id=' . $variation_id . ', parent_id=' . $parent_id );

        // Get new hosting plan and server ID
        $new_hosting_plan = get_post_meta($variation_id ? $variation_id : $parent_id, '_skyhshoso_hosting_plan', true);
        $new_server_id = get_post_meta($variation_id ? $variation_id : $parent_id, '_skyhshoso_server_id', true);
        if (empty($new_hosting_plan) && $variation_id) {
            $new_hosting_plan = get_post_meta($parent_id, '_skyhshoso_hosting_plan', true);
        }
        if (empty($new_server_id) && $variation_id) {
            $new_server_id = get_post_meta($parent_id, '_skyhshoso_server_id', true);
        }
        $this->switch_log( 'New hosting plan: ' . ( $new_hosting_plan ?: 'none' ) . ', server_id: ' . ( $new_server_id ?: 'none' ) );

        // Query for 'skyhshoso_hosting' post type with the old subscription ID
        // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Switch operation limited dataset.
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
        // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
        $query = new WP_Query($args);
        $this->switch_log( 'Hosting posts found: ' . $query->post_count );


        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                $this->switch_log( 'Processing hosting post #' . $post_id );

                // Update the subscription_id meta to the new subscription ID
                update_post_meta($post_id, 'skyhshoso_subscription_id', $new_subscription_id);

                $hosting_username = get_post_meta($post_id, 'skyhshoso_hosting_username', true);
                $current_hosting_plan = get_post_meta($post_id, 'skyhshoso_hosting_plan', true);
                $current_server_id = get_post_meta($post_id, 'skyhshoso_server_id', true);
                $this->switch_log( '  username=' . ( $hosting_username ?: 'none' ) . ', plan=' . ( $current_hosting_plan ?: 'none' ) );


                // Update hosting plan if changed
                if ($new_hosting_plan && $new_hosting_plan !== $current_hosting_plan) {
                    $this->switch_log( '  Updating hosting plan from ' . $current_hosting_plan . ' to ' . $new_hosting_plan );
                    
                    // Get WHM credentials from the server post type
                    $server_post = get_post($current_server_id);
                    if ($server_post && $server_post->post_type === 'skyhshoso_server') {
                        $whm_username = get_post_meta($current_server_id, '_skyhshoso_whm_user_id', true);
                        $whm_token = get_post_meta($current_server_id, '_skyhshoso_whm_token', true);
                        $whm_host = get_post_meta($current_server_id, '_skyhshoso_whm_host', true);

			if ($whm_username && $whm_token && $whm_host) {
							$whm_api = new SkyHSHOSO_WHM_API($whm_username, $whm_token, $whm_host);
							if ($whm_api->change_account_plan($hosting_username, $new_hosting_plan)) {
								update_post_meta($post_id, 'skyhshoso_hosting_plan', $new_hosting_plan);
								$this->switch_log( '  Hosting plan updated via WHM' );
							} else {
								$this->switch_log( '  FAIL: WHM plan change failed' );
								SkyHSHOSO_Logger::error( 'Subscription switch: WHM plan change failed for hosting ' . $hosting_username . ' to plan ' . $new_hosting_plan, array( 'source' => 'subscription_handler' ) );
							}
						} else {
							$this->switch_log( '  FAIL: Missing WHM credentials' );
							SkyHSHOSO_Logger::error( 'Subscription switch: missing WHM credentials for server ' . $current_server_id, array( 'source' => 'subscription_handler' ) );
						}
					} else {
						$this->switch_log( '  FAIL: Server post not found for ID=' . $current_server_id );
						SkyHSHOSO_Logger::error( 'Subscription switch: server post not found for ID ' . $current_server_id, array( 'source' => 'subscription_handler' ) );
                    }
                } else {
                    $this->switch_log( '  No hosting plan change needed' );
                }

                // Update server ID if changed
                if ($new_server_id && $new_server_id !== $current_server_id) {
                    update_post_meta($post_id, 'skyhshoso_server_id', $new_server_id);
                    $this->switch_log( '  Server ID updated to ' . $new_server_id );
                }

                // Update hosting post title
                $new_title = $new_product->get_name();
                $update_result = wp_update_post(array(
                    'ID' => $post_id,
                    'post_title' => $new_title
                ));
                $this->switch_log( '  Title updated to: ' . $new_title );
            }
        }

        // Query for 'skyhshoso_wp_site' post type with the old subscription ID
        // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Switch operation limited dataset.
        $wp_site_args = array(
            'post_type'  => 'skyhshoso_wp_site',
            'meta_query' => array(
                array(
                    'key'     => 'skyhshoso_subscription_id',
                    'value'   => $old_subscription_id,
                    'compare' => '=',
                ),
            ),
        );
        // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
        $wp_site_query = new WP_Query($wp_site_args);
        $this->switch_log( 'WP site posts found: ' . $wp_site_query->post_count );

        if ($wp_site_query->have_posts()) {
            while ($wp_site_query->have_posts()) {
                $wp_site_query->the_post();
                $wp_site_id = get_the_ID();
                $this->switch_log( 'Processing WP site post #' . $wp_site_id );

                // Update the subscription_id meta to the new subscription ID
                update_post_meta($wp_site_id, 'skyhshoso_subscription_id', $new_subscription_id);

                $provisioned = get_post_meta($wp_site_id, '_skyhshoso_wp_provisioned', true);
                if (empty($provisioned)) {
                    $this->switch_log( '  Not provisioned yet, just updating product ref' );
                    update_post_meta($wp_site_id, '_skyhshoso_hosting_product_id', $parent_id);
                    continue;
                }

                $doc_root    = get_post_meta($wp_site_id, '_skyhshoso_wp_doc_root', true);
                $cpanel_user = get_post_meta($wp_site_id, 'skyhshoso_wp_cpanel_user', true);
                $server_id   = get_post_meta($wp_site_id, 'skyhshoso_server_id', true);
                $this->switch_log( '  doc_root=' . ( $doc_root ?: 'none' ) . ', cpanel_user=' . ( $cpanel_user ?: 'none' ) . ', server_id=' . ( $server_id ?: 'none' ) );

                if (empty($doc_root) || !$server_id || !$cpanel_user) {
                    $this->switch_log( '  FAIL: Missing doc_root/server/cpanel info, skipping' );
                    continue;
                }

                // Get new storage and memory from the new product
                $new_storage = get_post_meta($variation_id ? $variation_id : $parent_id, '_skyhshoso_wp_storage', true);
                $new_memory  = get_post_meta($variation_id ? $variation_id : $parent_id, '_skyhshoso_wp_memory', true);
                if ((empty($new_storage) || empty($new_memory)) && $variation_id) {
                    if (empty($new_storage)) {
                        $new_storage = get_post_meta($parent_id, '_skyhshoso_wp_storage', true);
                    }
                    if (empty($new_memory)) {
                        $new_memory = get_post_meta($parent_id, '_skyhshoso_wp_memory', true);
                    }
                }
                $new_storage = $new_storage ?: 500;
                $new_memory  = $new_memory ?: '64M';
                $this->switch_log( "  New storage={$new_storage}MB, memory={$new_memory} (from product_id=" . ($variation_id ?: $parent_id) . ')' );

                // Update post meta on the WP site
                update_post_meta($wp_site_id, '_skyhshoso_wp_storage', $new_storage);
                update_post_meta($wp_site_id, '_skyhshoso_wp_memory', $new_memory);
                update_post_meta($wp_site_id, '_skyhshoso_hosting_product_id', $parent_id);
                $this->switch_log( '  Updated WP site post meta: storage=' . $new_storage . ', memory=' . $new_memory );

                // Get WHM credentials
                $whm_user  = get_post_meta($server_id, '_skyhshoso_whm_user_id', true);
                $whm_token = get_post_meta($server_id, '_skyhshoso_whm_token', true);
                $whm_host  = get_post_meta($server_id, '_skyhshoso_whm_host', true);

                if (empty($whm_user) || empty($whm_token) || empty($whm_host)) {
                    $this->switch_log( '  FAIL: Missing WHM credentials, skipping server update' );
                    continue;
                }
                $this->switch_log( '  WHM credentials found, connecting to ' . $whm_host );

                require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-whm-integration.php';
                $whm_api = new SkyHSHOSO_WHM_API($whm_user, $whm_token, $whm_host);

                // Re-deploy MU plugin with new storage limit
                $mu_template_file = SKYHSHOSO_PLUGIN_DIR . 'includes/wp-mu-plugin-template.php';
                if (file_exists($mu_template_file)) {
                    $mu_content = file_get_contents($mu_template_file);
                    $mu_content = str_replace('STORAGE_MB_VALUE', (string) $new_storage, $mu_content);

                    $mu_dir = rtrim($doc_root, '/') . '/wp-content/mu-plugins';
                    $this->switch_log( '  Deploying MU plugin to ' . $mu_dir . '/skyhs-resource-enforcer.php with storage=' . $new_storage . 'MB' );
                    $mu_result = $whm_api->cpanel_uapi_call_v3_raw($cpanel_user, 'Fileman', 'save_file_content', array(
                        'dir'     => $mu_dir,
                        'file'    => 'skyhs-resource-enforcer.php',
                        'content' => $mu_content,
                    ));
                    $this->switch_log( '  MU plugin deploy result: ' . ( $mu_result ? 'sent' : 'FAILED' ) );
                } else {
                    $this->switch_log( '  MU plugin template not found at ' . $mu_template_file );
                }

                // Update wp-config.php with new memory limit
                $this->switch_log( '  Fetching wp-config.php from ' . rtrim($doc_root, '/') );
                $config_result = $whm_api->cpanel_uapi_call_v3($cpanel_user, 'Fileman', 'get_file_content', array(
                    'dir'  => rtrim($doc_root, '/'),
                    'file' => 'wp-config.php',
                ));

                if (!empty($config_result['content'])) {
                    $this->switch_log( '  wp-config.php fetched, updating memory limit' );
                    $wp_config = $config_result['content'];
                    $max_memory = max(128, (int) $new_memory) . 'M';
                    $wp_config = preg_replace(
                        "/define\(\s*'WP_MEMORY_LIMIT'\s*,\s*'[^']+'\s*\);/",
                        "define('WP_MEMORY_LIMIT', '{$new_memory}');",
                        $wp_config
                    );
                    $wp_config = preg_replace(
                        "/define\(\s*'WP_MAX_MEMORY_LIMIT'\s*,\s*'[^']+'\s*\);/",
                        "define('WP_MAX_MEMORY_LIMIT', '{$max_memory}');",
                        $wp_config
                    );

                    $save_result = $whm_api->cpanel_uapi_call_v3_raw($cpanel_user, 'Fileman', 'save_file_content', array(
                        'dir'     => rtrim($doc_root, '/'),
                        'file'    => 'wp-config.php',
                        'content' => $wp_config,
                    ));
                    $this->switch_log( '  wp-config.php save result: ' . ( $save_result ? 'sent' : 'FAILED' ) );
                } else {
                    $this->switch_log( '  wp-config.php fetch failed: ' . ( is_array( $config_result ) ? wp_json_encode( $config_result ) : 'empty response' ) );
                }

                // Update wp_site post title
                $new_title = $new_product->get_name();
                wp_update_post(array(
                    'ID' => $wp_site_id,
                    'post_title' => $new_title
                ));
                $this->switch_log( '  WP site title updated to: ' . $new_title );
            }
        }

        $this->switch_log( '=== handle_subscription_switch END ===' );

        // Reset post data
        wp_reset_postdata();
    }

    /**
     * Helper function to check if a post is a WooCommerce order
     * 
     * @param int $post_id Post ID
     * @return bool
     */
    private function is_wc_order($post_id) {
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')) {
            return OrderUtil::get_order_type($post_id) === 'shop_order';
        }
        return get_post_type($post_id) === 'shop_order';
    }

    // update_domain_subscription_prices() is now handled by
    // SkyHSHOSO_Subscription_Checkout::update_domain_subscription_prices()
    // which hooks into woocommerce_payment_complete / woocommerce_order_status_completed.
}

function SkyHSHOSO_Subscription_Handler() {
    return SkyHSHOSO_Subscription_Handler::instance();
}

SkyHSHOSO_Subscription_Handler();
