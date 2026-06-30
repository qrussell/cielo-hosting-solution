<?php
/**
 * SkyHS Hosting Detail Class
 *
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

require_once plugin_dir_path( __FILE__ ) . 'class-whm-integration.php';

/**
 * Class to handle individual hosting details within the dashboard
 */
class SkyHSHOSO_Hosting_Detail {

    /**
     * Initialize the class
     */
    public static function init() {
        add_action('wp_ajax_skyhshoso_add_domain', array(self::class, 'ajax_add_domain'));
    }
    
    
    /**
     * AJAX handler for adding a domain to hosting
     */
    public static function ajax_add_domain() {
        // Check nonce
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nonce'] ) ), 'skyhshoso_add_domain' ) ) {
            wp_send_json_error( array( 'message' => 'Invalid security token' ) );
            return;
        }
        
        // Get hosting ID and domain
        $hosting_id = isset($_POST['hosting_id']) ? intval($_POST['hosting_id']) : 0;
        $domain = isset($_POST['skyhshoso_domain']) ? sanitize_text_field(wp_unslash($_POST['skyhshoso_domain'])) : '';
        
        if (!$hosting_id) {
            wp_send_json_error(array('message' => 'Invalid hosting ID'));
            return;
        }
        
        if (empty($domain)) {
            wp_send_json_error(array('message' => 'Domain is required'));
            return;
        }
        
        // Check if user has permission to access this hosting
        if (!self::user_can_access_hosting($hosting_id)) {
            wp_send_json_error(array('message' => 'You do not have permission to modify this hosting'));
            return;
        }
        
        // Validate domain format
        if (!self::is_valid_domain($domain)) {
            wp_send_json_error(array('message' => 'Invalid domain format'));
            return;
        }
        
        
        // Verify server configuration before running

        // Get server ID from hosting post meta
        $server_id = get_post_meta($hosting_id, 'skyhshoso_server_id', true);
        if (!$server_id) {
            wp_send_json_error(array('message' => 'No server associated with this hosting. Please contact the administrator.'));
            return;
        }

        // Check if this hosting already has a cPanel account
        $existing_username = get_post_meta($hosting_id, 'skyhshoso_hosting_username', true);
        $account_source = get_post_meta($hosting_id, '_skyhshoso_hosting_account_source', true);

        if ( ! empty( $existing_username ) ) {
            
            // If it was manually imported via admin, just update the post meta
            if ( 'existing' === $account_source ) {
                update_post_meta($hosting_id, 'skyhshoso_hosting_domain', $domain);
                wp_send_json_success(array(
                    'message' => 'Domain updated on existing imported cPanel account.',
                    'skyhshoso_domain' => $domain
                ));
                return;
            }

            // For active WooCommerce accounts, add this as an Addon Domain to the existing cPanel
            $server_id = get_post_meta($hosting_id, 'skyhshoso_server_id', true);
            $whm_username = get_post_meta($server_id, '_skyhshoso_whm_user_id', true);
            $whm_token = get_post_meta($server_id, '_skyhshoso_whm_token', true);
            $whm_host = get_post_meta($server_id, '_skyhshoso_whm_host', true);

            $whm_integration = new SkyHSHOSO_WHM_API($whm_username, $whm_token, $whm_host);
            
            // Extract subdomain prefix from the domain (e.g., 'example' from 'example.com')
            $subdomain_prefix = explode('.', $domain)[0];

            $result = $whm_integration->cpanel_uapi_call_v3(
                $existing_username, 
                'AddonDomain', 
                'addaddondomain', 
                array(
                    'dir' => 'public_html/' . $domain,
                    'newdomain' => $domain,
                    'subdomain' => $subdomain_prefix
                )
            );

            if ( $result !== false ) {
                wp_send_json_success(array(
                    'message' => 'Domain successfully added to your existing cPanel account as an Addon Domain.',
                    'skyhshoso_domain' => $domain
                ));
            } else {
                wp_send_json_error(array('message' => 'Failed to add domain to your existing cPanel account. Please contact support.'));
            }
            return;
        }

        // Get WHM settings from server post meta
        $whm_username = get_post_meta($server_id, '_skyhshoso_whm_user_id', true);
        $whm_token = get_post_meta($server_id, '_skyhshoso_whm_token', true);
        $whm_host = get_post_meta($server_id, '_skyhshoso_whm_host', true);

        // Validate WHM settings
        if (empty($whm_username) || empty($whm_token) || empty($whm_host)) {
            wp_send_json_error(array('message' => 'WHM settings are not properly configured on the server. Please contact the administrator.'));
            return;
        }

        // Create WHM account
        $whm_integration = new SkyHSHOSO_WHM_API($whm_username, $whm_token, $whm_host);
        
        try {
            $result = $whm_integration->create_whm_account($hosting_id, $domain);

            if (is_wp_error($result)) {
                wp_send_json_error(array('message' => $result->get_error_message()));
                return;
            }

            // Core API explicitly saves 'skyhshoso_hosting_username'. We persist server and domain link:
            update_post_meta($hosting_id, 'skyhshoso_hosting_domain', $domain);
            update_post_meta($hosting_id, 'skyhshoso_server_id', $server_id);

            // Send provisioning email with cPanel login details.
            $whm_username_stored = get_post_meta($hosting_id, 'skyhshoso_hosting_username', true);
            if ( $whm_username_stored && class_exists( 'SkyHSHOSO_Emails' ) ) {
                SkyHSHOSO_Emails::send_provisioning( $hosting_id, $whm_username_stored );
            }
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
            return;
        }
        
        wp_send_json_success(array(
            'message' => 'Domain added and WHM account created successfully',
            'skyhshoso_domain' => $domain
        ));
    }
    
    /**
     * Check if a domain format is valid
     * 
     * @param string $domain The domain to validate
     * @return bool True if valid, false otherwise
     */
    private static function is_valid_domain($domain) {
        // Basic domain validation using regex
        return (bool) preg_match('/^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/', $domain);
    }
    
    /**
     * Check if current user can access a hosting
     * 
     * @param int $hosting_id The hosting post ID
     * @return bool True if user can access, false otherwise
     */
    private static function user_can_access_hosting($hosting_id) {
        $current_user_id = get_current_user_id();
        $hosting = get_post($hosting_id);
        
        if (!$hosting || $hosting->post_type !== 'skyhshoso_hosting') {
            return false;
        }
        
        $hosting_author_id = $hosting->post_author;
        
        // Get the list of users invited by the hosting author
        $invited_by = get_user_meta($current_user_id, 'skyhshoso_invited_by', true);
        $invited_by = is_array($invited_by) ? $invited_by : array();

        // Check if current user is either:
        // 1. The author of the hosting
        // 2. An admin
        // 3. An invitee of the hosting author
        return ($current_user_id == $hosting_author_id 
            || current_user_can('administrator')
            || in_array($hosting_author_id, $invited_by));
    }
}

// Initialize the class
SkyHSHOSO_Hosting_Detail::init();
