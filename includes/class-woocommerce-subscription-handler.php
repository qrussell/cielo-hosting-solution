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
        add_action( 'skyhshoso_subscription_created', array( $this, 'handle_subscription_creation_or_resubscribe' ), 10, 3 );
        add_action( 'skyhshoso_subscription_status_updated', array( $this, 'update_post_status_on_subscription_change' ), 10, 3 );
        add_action( 'skyhshoso_subscription_renewed', array( $this, 'handle_subscription_renewal' ), 10, 2 );

        // Plan switch is now a manual admin action
        add_action( 'skyhshoso_subscription_switch_completed', array( $this, 'handle_subscription_switch' ), 10, 2 );
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
            if ( ! $product ) {
                continue;
            }
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

        if (!empty($existing_posts)) {
            return;
        }

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
			SkyHSHOSO_Logger::error( 'Hosting post creation failed for subscription #' . $subscription->get_id() . ': ' . $post_id->get_error_message(), array( 'source' => 'subscription_handler' ) );
		} else {
            
            $post_name = $post_id;
            wp_update_post(array(
                'ID' => $post_id,
                'post_name' => $post_name
            ));
            
            $product_id = $parent_id ? $parent_id : $product->get_id();
            $hosting_plan = get_post_meta($product_id, '_skyhshoso_hosting_plan', true);
            $server_id = get_post_meta($product_id, '_skyhshoso_server_id', true);
            
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
            
            // Generate a unique 6-character identifier
            $unique_id = strtolower(wp_generate_password(6, false, false));
            
            // Fetch configurable base domain from plugin settings (Default: cielocloud.xyz)
            $options = get_option('skyhshoso_settings_group', array());
            $base_domain = isset($options['system_subdomain']) && !empty($options['system_subdomain']) ? $options['system_subdomain'] : 'cielocloud.xyz';
            
            $system_domain = $unique_id . '.' . ltrim($base_domain, '.');
            $username = 'cielo' . $unique_id;
            $temp_password = wp_generate_password(16, true, true);

            // FIX: Connect the WC Product directly to the Hosting Post so it shows up in the admin panel!
            update_post_meta($post_id, '_skyhshoso_hosting_product_id', $product_id);
            if ($parent_id) {
                update_post_meta($post_id, '_skyhshoso_variation_id', $product->get_id());
            }

            update_post_meta($post_id, 'skyhshoso_subscription_id', $subscription->get_id());
            update_post_meta($post_id, 'skyhshoso_hosting_domain', $system_domain);
            update_post_meta($post_id, '_skyhshoso_system_domain', $system_domain);
            update_post_meta($post_id, 'skyhshoso_hosting_username', $username);
            update_post_meta($post_id, '_skyhshoso_hosting_temp_password', $temp_password);
            
            update_post_meta($post_id, 'skyhshoso_hosting_plan', $hosting_plan);
            update_post_meta($post_id, 'skyhshoso_server_id', $server_id);

            // === TRIGGER WHM ACCOUNT CREATION IMMEDIATELY ===
            if ($server_id && $hosting_plan) {
                $whm_username = get_post_meta($server_id, '_skyhshoso_whm_user_id', true);
                $whm_token = get_post_meta($server_id, '_skyhshoso_whm_token', true);
                $whm_host = get_post_meta($server_id, '_skyhshoso_whm_host', true);
                
                if ($whm_username && $whm_token && $whm_host) {
                    if (!class_exists('SkyHSHOSO_WHM_API')) {
                        require_once dirname(__FILE__) . '/class-whm-integration.php';
                    }
                    $whm_api = new SkyHSHOSO_WHM_API($whm_username, $whm_token, $whm_host);
                    
                    $whm_result = $whm_api->create_whm_account($post_id, $system_domain);
                    
                    if (is_wp_error($whm_result)) {
                        update_post_meta($post_id, '_skyhshoso_whm_provision_error', 'WHM Rejection: ' . $whm_result->get_error_message());
                        update_post_meta($post_id, '_skyhshoso_whm_provision_status', 'failed');
                    } else {
                        update_post_meta($post_id, '_skyhshoso_whm_provision_status', 'success');
                        delete_post_meta($post_id, '_skyhshoso_whm_provision_error');

                        if (class_exists('SkyHSHOSO_Emails')) {
                            SkyHSHOSO_Emails::send_provisioning($post_id, $username);
                        }
                    }
                } else {
                    update_post_meta($post_id, '_skyhshoso_whm_provision_error', 'Missing WHM credentials on Server ID ' . $server_id);
                }
            } else {
                update_post_meta($post_id, '_skyhshoso_whm_provision_error', 'Missing Server ID or Hosting Plan on the WooCommerce Product.');
            }
            // ================================================
        }
    }

    private function create_domain_post($subscription, $order, $product, $parent_id = 0) {
        $existing_posts = get_posts(array(
            'post_type'      => 'skyhshoso_domain',
            'meta_key'       => 'skyhshoso_subscription_id',
            'meta_value'     => $subscription->get_id(),
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids'
        ));

        if (!empty($existing_posts)) {
            return;
        }

        $product_id = $product->get_id();
        $is_transfer = get_post_meta($product_id, '_skyhshoso_domain_transfer', true) === 'yes';

        $post_title = $product->get_name();
        if ($is_transfer) {
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

        if ( class_exists( 'SkyHSHOSO_UUID' ) ) {
            SkyHSHOSO_UUID::set_post_uuid( $post_id );
        }
    }

    private function handle_subscription_resubscribe($new_subscription, $old_subscription) {
        $args = array(
            'post_type' => array('skyhshoso_hosting', 'skyhshoso_wp_site'),
            // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
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

            update_post_meta($post_id, 'skyhshoso_subscription_id', $new_subscription->get_id());

            if ($post_type === 'skyhshoso_hosting') {
                $hosting_username = get_post_meta($post_id, 'skyhshoso_hosting_username', true);
                $server_id = get_post_meta($post_id, 'skyhshoso_server_id', true);

                $whm_username = get_post_meta($server_id, '_skyhshoso_whm_user_id', true);
                $whm_token = get_post_meta($server_id, '_skyhshoso_whm_token', true);
                $whm_host = get_post_meta($server_id, '_skyhshoso_whm_host', true);

                if ($whm_username && $whm_token && $whm_host) {
                    $whm_api = new SkyHSHOSO_WHM_API($whm_username, $whm_token, $whm_host);
                    if ($whm_api->reactivate_account($hosting_username)) {
                    }
                }
            } elseif ($post_type === 'skyhshoso_wp_site') {
                $this->handle_wp_site_resubscribe($post_id);
            }
        }
    }

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
        
        // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
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
        $this->update_wp_site_posts_status($subscription_id, $new_status, $old_status);
        $this->update_domain_posts_status($subscription_id, $new_status);
    }

    private function update_domain_posts_status($subscription_id, $new_status) {
        // ...
    }

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
			} elseif ( $new_status === 'active' && ( $old_status === 'on-hold' || $old_status === 'expired' || $old_status === 'cancelled' ) ) {
				$result = $manager->unsuspend_wp_site( $doc_root );
			} elseif ( $new_status === 'expired' || $new_status === 'cancelled' ) {
				$result = $manager->suspend_wp_site( $doc_root );
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

    private function switch_log( $message ) {}

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
                    $server_post = get_post($current_server_id);
                    if ($server_post && $server_post->post_type === 'skyhshoso_server') {
                        $whm_username = get_post_meta($current_server_id, '_skyhshoso_whm_user_id', true);
                        $whm_token = get_post_meta($current_server_id, '_skyhshoso_whm_token', true);
                        $whm_host = get_post_meta($current_server_id, '_skyhshoso_whm_host', true);

                        if ($whm_username && $whm_token && $whm_host) {
                            $whm_api = new SkyHSHOSO_WHM_API($whm_username, $whm_token, $whm_host);
                            if ($whm_api->change_account_plan($hosting_username, $new_hosting_plan)) {
                                update_post_meta($post_id, 'skyhshoso_hosting_plan', $new_hosting_plan);
                            }
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

    private function is_wc_order($post_id) {
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')) {
            return OrderUtil::get_order_type($post_id) === 'shop_order';
        }
        return get_post_type($post_id) === 'shop_order';
    }
}

function SkyHSHOSO_Subscription_Handler() {
    return SkyHSHOSO_Subscription_Handler::instance();
}

SkyHSHOSO_Subscription_Handler();