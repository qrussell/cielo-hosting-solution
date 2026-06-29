<?php
/**
 * SkyHS WP Site Handler
 *
 * Frontend AJAX handler for WordPress site provisioning:
 * - Add domain / provision WordPress
 * - Get WP site details
 * - Get WP admin login URL
 *
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

class SkyHSHOSO_WP_Site_Handler {

    public static function init() {
        add_action( 'wp_ajax_skyhshoso_wp_provision',            array( self::class, 'ajax_wp_provision' ) );
        add_action( 'wp_ajax_skyhshoso_wp_get_details',          array( self::class, 'ajax_wp_get_details' ) );
        add_action( 'wp_ajax_skyhshoso_wp_get_admin_login_url',   array( self::class, 'ajax_wp_admin_login_url' ) );
    }

    /**
     * AJAX handler: provision a WordPress site for a WP Site post.
     */
    public static function ajax_wp_provision() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nonce'] ) ), 'skyhshoso_wp_provision' ) ) {
            wp_send_json_error( array( 'message' => 'Invalid security token' ) );
        }

        $wp_site_id = isset( $_POST['wp_site_id'] ) ? intval( $_POST['wp_site_id'] ) : 0;
        $domain     = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';

		if ( ! $wp_site_id || empty( $domain ) ) {
			SkyHSHOSO_Logger::error( 'WP provision failed: invalid WP site ID or domain', array( 'source' => 'wp_site_handler' ) );
			wp_send_json_error( array( 'message' => 'Invalid WP site ID or domain.' ) );
		}

		// Permission check
		if ( ! self::user_can_access( $wp_site_id ) ) {
			SkyHSHOSO_Logger::error( 'WP provision failed for site #' . $wp_site_id . ': permission denied', array( 'source' => 'wp_site_handler' ) );
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		// Validate domain
		if ( ! preg_match( '/^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/', $domain ) ) {
			SkyHSHOSO_Logger::error( 'WP provision failed for site #' . $wp_site_id . ': invalid domain format (' . $domain . ')', array( 'source' => 'wp_site_handler' ) );
			wp_send_json_error( array( 'message' => 'Invalid domain format.' ) );
		}

		// Check if already provisioned
		$existing_domain = get_post_meta( $wp_site_id, 'skyhshoso_wp_domain', true );
		if ( ! empty( $existing_domain ) ) {
			SkyHSHOSO_Logger::error( 'WP provision failed for site #' . $wp_site_id . ': already provisioned with domain ' . $existing_domain, array( 'source' => 'wp_site_handler' ) );
			wp_send_json_error( array( 'message' => 'This WordPress site is already provisioned.' ) );
		}

		// Get server and WHM credentials
		$server_id    = get_post_meta( $wp_site_id, 'skyhshoso_server_id', true );
		$cpanel_user  = get_post_meta( $wp_site_id, 'skyhshoso_wp_cpanel_user', true );

		if ( ! $server_id || ! $cpanel_user ) {
			SkyHSHOSO_Logger::error( 'WP provision failed for site #' . $wp_site_id . ': server or cPanel host not configured', array( 'source' => 'wp_site_handler' ) );
			wp_send_json_error( array( 'message' => 'Server or cPanel host not configured.' ) );
		}

		$whm_user = get_post_meta( $server_id, '_skyhshoso_whm_user_id', true );
		$whm_token = get_post_meta( $server_id, '_skyhshoso_whm_token', true );
		$whm_host = get_post_meta( $server_id, '_skyhshoso_whm_host', true );

		if ( empty( $whm_user ) || empty( $whm_token ) || empty( $whm_host ) ) {
			SkyHSHOSO_Logger::error( 'WP provision failed for site #' . $wp_site_id . ': WHM credentials missing on server #' . $server_id, array( 'source' => 'wp_site_handler' ) );
			wp_send_json_error( array( 'message' => 'WHM credentials missing on server.' ) );
		}

		require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-wordpress-manager.php';
		$manager = new SkyHSHOSO_WordPress_Manager( $whm_user, $whm_token, $whm_host, $cpanel_user );

		// Step 1: Check addon domain doesn't already exist
		if ( $manager->addon_domain_exists( $domain ) ) {
			SkyHSHOSO_Logger::error( 'WP provision failed for site #' . $wp_site_id . ': domain already added to cPanel account', array( 'source' => 'wp_site_handler' ) );
			wp_send_json_error( array( 'message' => 'This domain is already added to the cPanel account.' ) );
		}

		// Step 2: Create addon domain
		$doc_root = $manager->create_addon_domain( $domain );
		if ( is_wp_error( $doc_root ) ) {
			SkyHSHOSO_Logger::error( 'WP provision failed for site #' . $wp_site_id . ': ' . $doc_root->get_error_message(), array( 'source' => 'wp_site_handler' ) );
			wp_send_json_error( array( 'message' => 'Failed to create addon domain: ' . $doc_root->get_error_message(), 'debug_code' => $doc_root->get_error_code() ) );
		}

		// Trigger AutoSSL check to generate/request SSL certificate for the new domain
		$manager->trigger_autossl_check();

		// Step 3: Generate DB credentials and create database
		$db_name = $manager->generate_db_name();
		$db_user = $manager->generate_db_user();
		$db_pass = wp_generate_password( 16, true, true );

		if ( ! $manager->create_database( $db_name ) ) {
			$manager->remove_addon_domain( $domain );
			SkyHSHOSO_Logger::error( 'WP provision failed for site #' . $wp_site_id . ': failed to create MySQL database', array( 'source' => 'wp_site_handler' ) );
			wp_send_json_error( array( 'message' => 'Failed to create MySQL database.' ) );
		}

		if ( ! $manager->create_database_user( $db_user, $db_pass ) ) {
			$manager->drop_database( $db_name );
			$manager->remove_addon_domain( $domain );
			SkyHSHOSO_Logger::error( 'WP provision failed for site #' . $wp_site_id . ': failed to create MySQL user', array( 'source' => 'wp_site_handler' ) );
			wp_send_json_error( array( 'message' => 'Failed to create MySQL user.' ) );
		}

		if ( ! $manager->set_database_privileges( $db_name, $db_user, $db_pass ) ) {
			$manager->delete_database_user( $db_user );
			$manager->drop_database( $db_name );
			$manager->remove_addon_domain( $domain );
			SkyHSHOSO_Logger::error( 'WP provision failed for site #' . $wp_site_id . ': failed to set database privileges', array( 'source' => 'wp_site_handler' ) );
			wp_send_json_error( array( 'message' => 'Failed to set database privileges.' ) );
		}

        // Step 4: WordPress admin credentials (user can override defaults via form fields)
        $current_user  = wp_get_current_user();
        $wp_admin_user = isset( $_POST['admin_user'] ) ? sanitize_text_field( wp_unslash( $_POST['admin_user'] ) ) : '';
        if ( empty( $wp_admin_user ) ) {
            $wp_admin_user = 'admin';
        }
        $wp_admin_pass = isset( $_POST['admin_pass'] ) ? wp_unslash( $_POST['admin_pass'] ) : '';
        if ( empty( $wp_admin_pass ) ) {
            $wp_admin_pass = wp_generate_password( 16, true, true );
        }
        $admin_email = isset( $_POST['admin_email'] ) ? sanitize_email( wp_unslash( $_POST['admin_email'] ) ) : '';
        if ( empty( $admin_email ) ) {
            $admin_email = $current_user ? $current_user->user_email : get_bloginfo( 'admin_email' );
        }
        $site_title    = get_post_meta( $wp_site_id, 'skyhshoso_wp_site_title', true ) ?: get_the_title( $wp_site_id );

        // Read storage and memory limits from product meta
        $product_id  = get_post_meta( $wp_site_id, '_skyhshoso_hosting_product_id', true );
        $storage_mb  = get_post_meta( $product_id, '_skyhshoso_wp_storage', true ) ?: 500;
        $memory      = get_post_meta( $product_id, '_skyhshoso_wp_memory', true ) ?: '64M';

        // Read selected plugins from user input
        $plugins = isset( $_POST['plugins'] ) ? array_map( 'sanitize_text_field', explode( ',', wp_unslash( $_POST['plugins'] ) ) ) : array();
        $plugins = array_filter( $plugins );
        $plugins = array_slice( $plugins, 0, 10 ); // limit to 10 plugins max

        // Step 5: Install WordPress
        $install_result = $manager->install_wordpress(
            $doc_root,
            $db_name,
            $db_user,
            $db_pass,
            $site_title,
            $wp_admin_user,
            $wp_admin_pass,
            $admin_email,
            $storage_mb,
            $memory,
            $plugins
        );

		if ( is_wp_error( $install_result ) ) {
			// Clean up on failure
			$manager->delete_database_user( $db_user );
			$manager->drop_database( $db_name );
			$manager->remove_addon_domain( $domain );
			SkyHSHOSO_Logger::error( 'WP provision failed for site #' . $wp_site_id . ': WordPress installation failed: ' . $install_result->get_error_message(), array( 'source' => 'wp_site_handler' ) );
			wp_send_json_error( array( 'message' => 'WordPress installation failed: ' . $install_result->get_error_message() ) );
		}

        // Step 6: Save metadata
        update_post_meta( $wp_site_id, 'skyhshoso_wp_domain',     $domain );
        update_post_meta( $wp_site_id, 'skyhshoso_wp_db_name',     $db_name );
        update_post_meta( $wp_site_id, 'skyhshoso_wp_db_user',     $db_user );
        update_post_meta( $wp_site_id, '_skyhshoso_wp_db_pass',    $db_pass );
        update_post_meta( $wp_site_id, 'skyhshoso_wp_admin_user',  $wp_admin_user );
        update_post_meta( $wp_site_id, 'skyhshoso_wp_admin_pass',  $wp_admin_pass );
        update_post_meta( $wp_site_id, '_skyhshoso_wp_site_url',   $install_result['site_url'] );
        update_post_meta( $wp_site_id, '_skyhshoso_wp_doc_root',   $doc_root );
        update_post_meta( $wp_site_id, '_skyhshoso_wp_provisioned', '1' );
        delete_post_meta( $wp_site_id, '_skyhshoso_hosting_temp_password' );

        // Save server IP and nameservers details if available
        if ( $server_id ) {
            $server_ip = get_post_meta( $server_id, '_skyhshoso_server_ip', true );
            $server_ns = get_post_meta( $server_id, '_skyhshoso_server_nameservers', true );
            if ( ! empty( $server_ip ) ) {
                update_post_meta( $wp_site_id, '_skyhshoso_server_ip', $server_ip );
            }
            if ( ! empty( $server_ns ) ) {
                update_post_meta( $wp_site_id, '_skyhshoso_server_nameservers', $server_ns );
            }
        }

        // Send provisioning email
        if ( class_exists( 'SkyHSHOSO_Emails' ) ) {
            SkyHSHOSO_Emails::send_wp_provisioning( $wp_site_id, $install_result['admin_url'], $wp_admin_user, $wp_admin_pass );
        }

        wp_send_json_success( array(
            'message'    => 'WordPress site provisioned successfully!',
            'site_url'   => $install_result['site_url'],
            'admin_url'  => $install_result['admin_url'],
            'admin_user' => $wp_admin_user,
            'admin_pass' => $wp_admin_pass,
        ) );
    }

    /**
     * AJAX handler: get WP site details.
     */
    public static function ajax_wp_get_details() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nonce'] ) ), 'skyhshoso_dashboard_nonce' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ) );
        }

        $wp_site_id = isset( $_POST['wp_site_id'] ) ? intval( $_POST['wp_site_id'] ) : 0;
        if ( ! $wp_site_id || ! self::user_can_access( $wp_site_id ) ) {
            wp_send_json_error( array( 'message' => 'Invalid request.' ) );
        }

        $provisioned = get_post_meta( $wp_site_id, '_skyhshoso_wp_provisioned', true );
        $site_url    = get_post_meta( $wp_site_id, '_skyhshoso_wp_site_url', true );
        $admin_url   = get_post_meta( $wp_site_id, 'skyhshoso_wp_admin_user', true )
                       ? rtrim( $site_url, '/' ) . '/wp-admin' : '';
        $domain      = get_post_meta( $wp_site_id, 'skyhshoso_wp_domain', true );

        $subscription_id = get_post_meta( $wp_site_id, 'skyhshoso_subscription_id', true );
        $status = 'pending';
        if ( ! empty( $subscription_id ) ) {
            $subscription = skyhshoso_get_subscription( $subscription_id );
            if ( $subscription ) {
                $status = $subscription->get_status();
            }
        }

        wp_send_json_success( array(
            'id'           => $wp_site_id,
            'title'        => get_the_title( $wp_site_id ),
            'domain'       => $domain ?: '',
            'provisioned'  => ! empty( $provisioned ),
            'site_url'     => $site_url ?: '',
            'admin_url'    => $admin_url ?: '',
            'status'       => $status,
        ) );
    }

    /**
     * AJAX handler: generate a direct WordPress admin login URL.
     */
    public static function ajax_wp_admin_login_url() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nonce'] ) ), 'skyhshoso_dashboard_nonce' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ) );
        }

        $wp_site_id = isset( $_POST['wp_site_id'] ) ? intval( $_POST['wp_site_id'] ) : 0;
        if ( ! $wp_site_id || ! self::user_can_access( $wp_site_id ) ) {
            wp_send_json_error( array( 'message' => 'Invalid request.' ) );
        }

        $site_url = get_post_meta( $wp_site_id, '_skyhshoso_wp_site_url', true );
        if ( empty( $site_url ) ) {
            wp_send_json_error( array( 'message' => 'Site not provisioned yet.' ) );
        }

        wp_send_json_success( array(
            'admin_url' => rtrim( $site_url, '/' ) . '/wp-admin',
        ) );
    }

    /**
     * Check if the current user can access a WP site post.
     */
    private static function user_can_access( $wp_site_id ) {
        $current_user_id = get_current_user_id();
        $post            = get_post( $wp_site_id );

        if ( ! $post || $post->post_type !== 'skyhshoso_wp_site' ) {
            return false;
        }

        $author_id = $post->post_author;
        $invited_by = get_user_meta( $current_user_id, 'skyhshoso_invited_by', true );
        $invited_by = is_array( $invited_by ) ? $invited_by : array();

        return ( $current_user_id == $author_id
            || current_user_can( 'administrator' )
            || in_array( $author_id, $invited_by ) );
    }
}

SkyHSHOSO_WP_Site_Handler::init();
