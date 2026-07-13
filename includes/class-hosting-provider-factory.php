<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SkyHSHOSO_Provider_Factory {

    /**
     * Initializes the correct server driver based on the server's meta data.
     * * @param int $server_id The post ID of the skyhshoso_server
     * @return SkyHSHOSO_Hosting_Driver_Interface|WP_Error
     */
    public static function get_driver($server_id) {
        // 1. Determine the Server Type (Default to WHM for backwards compatibility)
        $server_type = get_post_meta($server_id, '_skyhshoso_server_type', true);
        if (empty($server_type)) {
            $server_type = 'whm'; 
        }

        // 2. Fetch the credentials for this server
        $host  = get_post_meta($server_id, '_skyhshoso_whm_host', true);
        $user  = get_post_meta($server_id, '_skyhshoso_whm_user_id', true);
        $token = get_post_meta($server_id, '_skyhshoso_whm_token', true);

        if (empty($host) || empty($token)) {
            return new WP_Error('missing_credentials', 'Server API credentials are not fully configured.');
        }

        // 3. Route to the correct Driver Class
        switch (strtolower($server_type)) {
            case 'hestiacp':
                if (!class_exists('SkyHSHOSO_HestiaCP_Driver')) {
                    require_once dirname(__FILE__) . '/drivers/class-hestiacp-driver.php';
                }
                return new SkyHSHOSO_HestiaCP_Driver($host, $user, $token);

            case 'wordops':
                // return new SkyHSHOSO_WordOps_Driver($host, $user, $token);
                return new WP_Error('not_implemented', 'WordOps driver is currently under development.');

            case 'whm':
            default:
                if (!class_exists('SkyHSHOSO_WHM_Driver')) {
                    require_once dirname(__FILE__) . '/drivers/class-whm-driver.php';
                }
                return new SkyHSHOSO_WHM_Driver($host, $user, $token);
        }
    }
}