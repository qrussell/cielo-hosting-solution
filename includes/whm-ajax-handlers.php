<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_skyhshoso_generate_cpanel_login_url', 'skyhshoso_generate_cpanel_login_url');
add_action('wp_ajax_skyhshoso_get_cpanel_stats', 'skyhshoso_get_cpanel_stats');
add_action('wp_ajax_skyhshoso_refresh_cpanel_stats', 'skyhshoso_refresh_cpanel_stats');
add_action('wp_ajax_skyhshoso_get_cpanel_section_url', 'skyhshoso_get_cpanel_section_url');
add_action('wp_ajax_skyhshoso_toggle_ssh', 'skyhshoso_toggle_ssh');
add_action('wp_ajax_skyhshoso_reset_password', 'skyhshoso_reset_password');
add_action('wp_ajax_skyhshoso_scan_wp_sites', 'skyhshoso_scan_wp_sites');
add_action('wp_ajax_skyhshoso_assign_custom_domain', 'skyhshoso_assign_custom_domain');

function skyhshoso_generate_cpanel_login_url() {
    // Check multiple valid nonces (client-specific, client-dashboard, and admin-dashboard)
    $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'skyhshoso_generate_cpanel_login_url_nonce' ) && 
         ! wp_verify_nonce( $nonce, 'skyhshoso_dashboard_nonce' ) &&
         ! wp_verify_nonce( $nonce, 'skyhshoso_get_cpanel_accounts' ) ) {
        wp_send_json_error( array( 'message' => esc_html__( 'Security check failed. Please refresh the page and try again.', 'skyhs-hosting-solution' ) ) );
        wp_die();
    }
    
    // Check if user is logged in
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in to access this feature.', 'skyhs-hosting-solution' ) ) );
        wp_die();
    }

    // Verify capabilities
    if ( ! current_user_can( 'read' ) ) {
        wp_send_json_error( array( 'message' => esc_html__( 'You do not have permission to perform this action.', 'skyhs-hosting-solution' ) ) );
        wp_die();
    }

    $hosting_id = isset($_POST['hosting_id']) ? absint( $_POST['hosting_id'] ) : 0;
    $server_id = get_post_meta($hosting_id, 'skyhshoso_server_id', true);
    if (empty($hosting_id) || empty($server_id)) {
        wp_send_json_error( array( 'message' => esc_html__( 'Hosting ID and Server ID are required', 'skyhs-hosting-solution' ) ) );
        wp_die();
    }
    
    // Check if current user is the author, admin, or invitee
    $current_user_id = get_current_user_id();
    $current_user = get_user_by('id', $current_user_id);
    $post_author_id = absint( get_post_field('post_author', $hosting_id) );
    
    // Get invitations from user meta
    $invited_by = get_user_meta($current_user_id, 'skyhshoso_invited_by', true);
    $invited_by = is_array($invited_by) ? array_map('absint', $invited_by) : array();

    if ( $current_user_id !== $post_author_id 
        && ! current_user_can( 'administrator' )
        && ! in_array( $post_author_id, $invited_by, true ) ) {
        wp_send_json_error( array( 'message' => esc_html__( 'You do not have permission to access this cPanel.', 'skyhs-hosting-solution' ) ) );
        wp_die();
    }

    // Get the hosting username from post meta
    $username = get_post_meta($hosting_id, 'skyhshoso_hosting_username', true);
    
    if ( empty( $username ) ) {
        wp_send_json_error( array( 'message' => esc_html__( 'Username is required.', 'skyhs-hosting-solution' ) ) );
        wp_die();
    }

    // Get WHM API credentials from server post meta
    $whm_username = get_post_meta( $server_id, '_skyhshoso_whm_user_id', true );
    $whm_token = get_post_meta( $server_id, '_skyhshoso_whm_token', true );
    $whm_host = get_post_meta( $server_id, '_skyhshoso_whm_host', true );

    if ( empty( $whm_username ) || empty( $whm_token ) || empty( $whm_host ) ) {
        wp_send_json_error( array( 'message' => esc_html__( 'WHM credentials are missing.', 'skyhs-hosting-solution' ) ) );
        wp_die();
    }

    // Initialize our API class to ensure URL cleaning and proper SSL handling
    if (!class_exists('SkyHSHOSO_WHM_API')) {
        require_once dirname(__FILE__) . '/class-whm-integration.php';
    }
    $whm_api = new SkyHSHOSO_WHM_API($whm_username, $whm_token, $whm_host);

    $params = [
        'api.version' => 1,
        'user' => $username,
        'service' => 'cpaneld'
    ];

    $result = $whm_api->call('create_user_session', $params);

    if ($result && isset($result['data']['url'])) {
        wp_send_json_success(['login_url' => $result['data']['url']]);
    } else {
        $error_message = isset($result['metadata']['reason']) ? $result['metadata']['reason'] : "Failed to generate login URL.";
        wp_send_json_error(['message' => $error_message]);
    }
    wp_die();
}

function skyhshoso_get_cpanel_stats() {
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'skyhshoso_dashboard_nonce' ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed.' ) );
        wp_die();
    }

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'You must be logged in.' ) );
        wp_die();
    }

    $hosting_id = isset( $_POST['hosting_id'] ) ? absint( $_POST['hosting_id'] ) : 0;
    if ( ! $hosting_id ) {
        wp_send_json_error( array( 'message' => 'Invalid hosting ID.' ) );
        wp_die();
    }

    $current_user_id = get_current_user_id();
    $post_author_id  = absint( get_post_field( 'post_author', $hosting_id ) );
    $invited_by      = get_user_meta( $current_user_id, 'skyhshoso_invited_by', true );
    $invited_by      = is_array( $invited_by ) ? array_map( 'absint', $invited_by ) : array();

    if ( $current_user_id !== $post_author_id && ! current_user_can( 'administrator' ) && ! in_array( $post_author_id, $invited_by, true ) ) {
        wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        wp_die();
    }

    $server_id   = get_post_meta( $hosting_id, 'skyhshoso_server_id', true );
    $whm_user    = get_post_meta( $hosting_id, 'skyhshoso_hosting_username', true );
    $domain      = get_post_meta( $hosting_id, 'skyhshoso_hosting_domain', true );

    $whm_api_user  = get_post_meta( $server_id, '_skyhshoso_whm_user_id', true );
    $whm_api_token = get_post_meta( $server_id, '_skyhshoso_whm_token', true );
    $whm_api_host  = get_post_meta( $server_id, '_skyhshoso_whm_host', true );

    if ( empty( $whm_user ) || empty( $whm_api_user ) || empty( $whm_api_token ) || empty( $whm_api_host ) ) {
        wp_send_json_error( array( 'message' => 'WHM credentials missing.' ) );
        wp_die();
    }

    $cache_stats_key = 'skyhshoso_cpanel_stats_' . $hosting_id;
    $cache_usage_key = 'skyhshoso_usage_' . $hosting_id;

    $cached_stats = get_transient( $cache_stats_key );
    $cached_usage = get_transient( $cache_usage_key );

    if ( false !== $cached_stats && false !== $cached_usage ) {
        $hosting_plan = get_post_meta( $hosting_id, 'skyhshoso_hosting_plan', true );
        wp_send_json_success( array(
            'usage'         => $cached_usage,
            'stats'         => $cached_stats,
            'whm_user'      => $whm_user,
            'hosting_plan'  => $hosting_plan,
            'domain'        => $domain,
        ) );
        wp_die();
    }

    if (!class_exists('SkyHSHOSO_WHM_API')) {
        require_once dirname(__FILE__) . '/class-whm-integration.php';
    }
    $whm_api = new SkyHSHOSO_WHM_API( $whm_api_user, $whm_api_token, $whm_api_host );

    if ( false !== $cached_usage ) {
        $usage = $cached_usage;
    } else {
        $usage = $whm_api->get_account_summary( $whm_user );
        if ( $usage ) {
            set_transient( $cache_usage_key, $usage, DAY_IN_SECONDS );
        }
    }

    if ( false !== $cached_stats ) {
        $stats = $cached_stats;
    } else {
        $stats = $whm_api->get_all_account_stats( $hosting_id, $whm_user, $domain );
    }

    $hosting_plan = get_post_meta( $hosting_id, 'skyhshoso_hosting_plan', true );

    wp_send_json_success( array(
        'usage'         => $usage,
        'stats'         => $stats,
        'whm_user'      => $whm_user,
        'hosting_plan'  => $hosting_plan,
        'domain'        => $domain,
    ) );
    wp_die();
}

function skyhshoso_refresh_cpanel_stats() {
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'skyhshoso_dashboard_nonce' ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed.' ) );
        wp_die();
    }

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'You must be logged in.' ) );
        wp_die();
    }

    $hosting_id = isset( $_POST['hosting_id'] ) ? absint( $_POST['hosting_id'] ) : 0;
    if ( ! $hosting_id ) {
        wp_send_json_error( array( 'message' => 'Invalid hosting ID.' ) );
        wp_die();
    }

    $current_user_id = get_current_user_id();
    $post_author_id  = absint( get_post_field( 'post_author', $hosting_id ) );
    $invited_by      = get_user_meta( $current_user_id, 'skyhshoso_invited_by', true );
    $invited_by      = is_array( $invited_by ) ? array_map( 'absint', $invited_by ) : array();

    if ( $current_user_id !== $post_author_id && ! current_user_can( 'administrator' ) && ! in_array( $post_author_id, $invited_by, true ) ) {
        wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        wp_die();
    }

    $server_id   = get_post_meta( $hosting_id, 'skyhshoso_server_id', true );
    $whm_user    = get_post_meta( $hosting_id, 'skyhshoso_hosting_username', true );
    $domain      = get_post_meta( $hosting_id, 'skyhshoso_hosting_domain', true );

    $whm_api_user  = get_post_meta( $server_id, '_skyhshoso_whm_user_id', true );
    $whm_api_token = get_post_meta( $server_id, '_skyhshoso_whm_token', true );
    $whm_api_host  = get_post_meta( $server_id, '_skyhshoso_whm_host', true );

    if ( empty( $whm_user ) || empty( $whm_api_user ) || empty( $whm_api_token ) || empty( $whm_api_host ) ) {
        wp_send_json_error( array( 'message' => 'WHM credentials missing.' ) );
        wp_die();
    }

    if (!class_exists('SkyHSHOSO_WHM_API')) {
        require_once dirname(__FILE__) . '/class-whm-integration.php';
    }
    
    SkyHSHOSO_WHM_API::clear_stats_cache( $hosting_id );

    $whm_api = new SkyHSHOSO_WHM_API( $whm_api_user, $whm_api_token, $whm_api_host );
    $usage   = $whm_api->get_account_summary( $whm_user );
    $stats   = $whm_api->get_all_account_stats( $hosting_id, $whm_user, $domain );

    if ( $usage ) {
        set_transient( 'skyhshoso_usage_' . $hosting_id, $usage, DAY_IN_SECONDS );
    }

    wp_send_json_success( array(
        'usage' => $usage,
        'stats' => $stats,
    ) );
    wp_die();
}

function skyhshoso_get_cpanel_section_url() {
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'skyhshoso_dashboard_nonce' ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed.' ) );
        wp_die();
    }
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'You must be logged in.' ) );
        wp_die();
    }

    $hosting_id = isset( $_POST['hosting_id'] ) ? absint( $_POST['hosting_id'] ) : 0;
    $section    = isset( $_POST['section'] ) ? sanitize_text_field( wp_unslash( $_POST['section'] ) ) : '';
    $insid      = isset( $_POST['insid'] ) ? sanitize_text_field( wp_unslash( $_POST['insid'] ) ) : '';

    $sections = [
        'email'       => '/frontend/jupiter/email_accounts/index.html#/list',
        'wordpress'   => '/frontend/jupiter/softaculous/index.live.php?act=wp',
        'filemanager' => '/frontend/jupiter/filemanager/index.html',
        'databases'   => '/frontend/jupiter/sql/index.html',
        'ssl'         => '/frontend/jupiter/ssl/index.html',
        'domains'     => '/frontend/jupiter/domains/index.html',
        'dns'         => '/frontend/jupiter/zone_editor/index.html',
        'ftp'         => '/frontend/jupiter/ftp/accounts.html',
        'php'         => '/frontend/jupiter/php/ini/index.html',
    ];

    $path = $sections[ $section ] ?? '/';
    if ( $section === 'wordpress' && $insid ) {
        $path = '/frontend/jupiter/softaculous/index.live.php?act=wordpress&insid=' . $insid;
    }

    $current_user_id = get_current_user_id();
    $post_author_id  = absint( get_post_field( 'post_author', $hosting_id ) );
    $invited_by      = get_user_meta( $current_user_id, 'skyhshoso_invited_by', true );
    $invited_by      = is_array( $invited_by ) ? array_map( 'absint', $invited_by ) : array();

    if ( $current_user_id !== $post_author_id && ! current_user_can( 'administrator' ) && ! in_array( $post_author_id, $invited_by, true ) ) {
        wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        wp_die();
    }

    $server_id  = get_post_meta( $hosting_id, 'skyhshoso_server_id', true );
    $username   = get_post_meta( $hosting_id, 'skyhshoso_hosting_username', true );
    $whm_user   = get_post_meta( $server_id, '_skyhshoso_whm_user_id', true );
    $whm_token  = get_post_meta( $server_id, '_skyhshoso_whm_token', true );
    $whm_host   = get_post_meta( $server_id, '_skyhshoso_whm_host', true );

    if (!class_exists('SkyHSHOSO_WHM_API')) {
        require_once dirname(__FILE__) . '/class-whm-integration.php';
    }
    $whm_api = new SkyHSHOSO_WHM_API($whm_user, $whm_token, $whm_host);

    $params = [
        'api.version' => 1,
        'user' => $username,
        'service' => 'cpaneld'
    ];

    $result = $whm_api->call('create_user_session', $params);

    if ($result && isset($result['data']['url'])) {
        $base_url = $result['data']['url'];
        $url = add_query_arg( 'goto_uri', $path, $base_url );
        wp_send_json_success( array( 'url' => $url ) );
    } else {
        $error_message = isset($result['metadata']['reason']) ? $result['metadata']['reason'] : 'Failed to generate session.';
        wp_send_json_error( array( 'message' => $error_message ) );
    }
    wp_die();
}

/**
 * Toggle SSH Access via WHM API
 */
function skyhshoso_toggle_ssh() {
    check_ajax_referer('skyhshoso_dashboard_nonce', 'nonce');
    
    $hosting_id = absint($_POST['hosting_id']);
    $action = sanitize_text_field($_POST['action_state']); // 'enable' or 'disable'
    
    // 1. Get Server Details
    $server_id = get_post_meta($hosting_id, 'skyhshoso_server_id', true);
    $username  = get_post_meta($hosting_id, 'skyhshoso_hosting_username', true);
    $whm_api_user = get_post_meta($server_id, '_skyhshoso_whm_user_id', true);
    $whm_api_token = get_post_meta($server_id, '_skyhshoso_whm_token', true);
    $whm_api_host  = get_post_meta($server_id, '_skyhshoso_whm_host', true);

    if (!class_exists('SkyHSHOSO_WHM_API')) {
        require_once dirname(__FILE__) . '/class-whm-integration.php';
    }
    $whm_api = new SkyHSHOSO_WHM_API($whm_api_user, $whm_api_token, $whm_api_host);
    
    // WHM API Call to toggle SSH
    $function = ($action === 'enable') ? 'ssh_enable' : 'ssh_disable';
    $result = $whm_api->cpanel_uapi_call_v3($username, 'SSH', $function, array());

    if ($result) {
        wp_send_json_success(['message' => 'SSH access updated.']);
    } else {
        wp_send_json_error(['message' => 'Failed to update SSH access.']);
    }
}

/**
 * Reset cPanel Password
 */
function skyhshoso_reset_password() {
    check_ajax_referer('skyhshoso_dashboard_nonce', 'nonce');
    
    $hosting_id = absint($_POST['hosting_id']);
    $new_pass = sanitize_text_field($_POST['new_password']);
    
    $server_id = get_post_meta($hosting_id, 'skyhshoso_server_id', true);
    $username  = get_post_meta($hosting_id, 'skyhshoso_hosting_username', true);
    $whm_api_user = get_post_meta($server_id, '_skyhshoso_whm_user_id', true);
    $whm_api_token = get_post_meta($server_id, '_skyhshoso_whm_token', true);
    $whm_api_host  = get_post_meta($server_id, '_skyhshoso_whm_host', true);

    if (!class_exists('SkyHSHOSO_WHM_API')) {
        require_once dirname(__FILE__) . '/class-whm-integration.php';
    }
    $whm_api = new SkyHSHOSO_WHM_API($whm_api_user, $whm_api_token, $whm_api_host);
    
    // WHM API 'passwd'
    $result = $whm_api->cpanel_uapi_call_v3($username, 'Passwd', 'set_password', array('password' => $new_pass));

    if ($result) {
        wp_send_json_success(['message' => 'Password reset successfully.']);
    } else {
        wp_send_json_error(['message' => 'Failed to reset password.']);
    }
}

/**
 * Scan account for WP sites
 */
function skyhshoso_scan_wp_sites() {
    check_ajax_referer('skyhshoso_dashboard_nonce', 'nonce');
    
    $hosting_id = absint($_POST['hosting_id']);
    $username  = get_post_meta($hosting_id, 'skyhshoso_hosting_username', true);
    
    // Strict validation
    if (empty($username) || !preg_match('/^[a-z0-9_-]+$/', $username)) {
        wp_send_json_error(['message' => 'Invalid username']);
    }

    $base_dir = "/home/" . $username . "/public_html";
    
    // Ensure base directory exists before running find
    if (!is_dir($base_dir)) {
        wp_send_json_error(['message' => 'Public directory not found']);
    }

    // Use escapeshellarg for security
    $cmd = "find " . escapeshellarg($base_dir) . " -name wp-config.php -not -path '*/wp-content/*' 2>&1";
    $output = shell_exec($cmd);
    
    $sites = [];
    
    if (!empty($output)) {
        $lines = explode("\n", trim($output));
        $domain = get_post_meta($hosting_id, 'skyhshoso_hosting_domain', true);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Get directory containing wp-config.php
            $dir = dirname($line);
            
            // Calculate relative path for URL
            $relative_path = str_replace($base_dir, '', $dir);
            $relative_path = ltrim($relative_path, '/');
            
            $url = 'https://' . $domain . ($relative_path ? '/' . $relative_path : '');
            
            $sites[] = [
                'path' => $line,
                'site_url' => $url,
                'admin_url' => $url . '/wp-admin/'
            ];
        }
    }
    
    wp_send_json_success(['sites' => $sites]);
}

/**
 * Assign Custom Domain and Search/Replace
 */
function skyhshoso_assign_custom_domain() {
    check_ajax_referer('skyhshoso_dashboard_nonce', 'nonce');
    
    $hosting_id = absint($_POST['hosting_id']);
    $new_domain = sanitize_text_field($_POST['new_domain']);
    
    // Security: Verify Ownership
    $current_user_id = get_current_user_id();
    $post_author_id = absint(get_post_field('post_author', $hosting_id));
    if ($current_user_id !== $post_author_id && !current_user_can('administrator')) {
        wp_send_json_error(['message' => 'Permission denied.']);
    }

    $server_id = get_post_meta($hosting_id, 'skyhshoso_server_id', true);
    $username  = get_post_meta($hosting_id, 'skyhshoso_hosting_username', true);
    $system_domain = get_post_meta($hosting_id, '_skyhshoso_system_domain', true);
    
    // Fallback if system domain wasn't recorded
    if (empty($system_domain)) {
        $system_domain = get_post_meta($hosting_id, 'skyhshoso_hosting_domain', true);
    }
    
    $whm_api_user = get_post_meta($server_id, '_skyhshoso_whm_user_id', true);
    $whm_api_token = get_post_meta($server_id, '_skyhshoso_whm_token', true);
    $whm_api_host  = get_post_meta($server_id, '_skyhshoso_whm_host', true);
    
    if (!class_exists('SkyHSHOSO_WHM_API')) {
        require_once dirname(__FILE__) . '/class-whm-integration.php';
    }
    $whm_api = new SkyHSHOSO_WHM_API($whm_api_user, $whm_api_token, $whm_api_host);

    // --- PHASE 1: SERVER INFRASTRUCTURE ---

    // 1. Add Addon Domain via WHM API (Mapping it to public_html)
    $addon_args = [
        'domain' => $new_domain,
        'dir' => 'public_html',
        'subdomain' => str_replace('.', '_', $new_domain)
    ];
    $whm_api->cpanel_uapi_call_v3($username, 'DomainInfo', 'create_addon_domain', $addon_args);
    
    // 2. Trigger AutoSSL for the new domain
    $whm_api->cpanel_uapi_call_v3($username, 'SSL', 'start_autossl_check', []);


    // --- PHASE 2: WORDPRESS MIGRATION ---

    $public_html = "/home/" . $username . "/public_html";
    
    // Verify if WordPress actually exists before running WP-CLI
    if (file_exists($public_html . '/wp-config.php')) {
        
        // 3. Maintenance Mode ON
        shell_exec("wp maintenance-mode activate --path=" . escapeshellarg($public_html) . " 2>&1");
        
        // 4. Perform Search & Replace (HTTP and HTTPS)
        $old_url_https = "https://" . rtrim($system_domain, '/');
        $new_url_https = "https://" . rtrim($new_domain, '/');
        $old_url_http = "http://" . rtrim($system_domain, '/');
        $new_url_http = "http://" . rtrim($new_domain, '/');

        $sr_cmd_https = "wp search-replace " . escapeshellarg($old_url_https) . " " . escapeshellarg($new_url_https) . " --path=" . escapeshellarg($public_html) . " --skip-columns=guid --all-tables 2>&1";
        $sr_cmd_http = "wp search-replace " . escapeshellarg($old_url_http) . " " . escapeshellarg($new_url_http) . " --path=" . escapeshellarg($public_html) . " --skip-columns=guid --all-tables 2>&1";
        
        shell_exec($sr_cmd_https);
        shell_exec($sr_cmd_http);
        
        // 5. Flush Caches
        shell_exec("wp cache flush --path=" . escapeshellarg($public_html) . " 2>&1");
        
        // 6. Maintenance Mode OFF
        shell_exec("wp maintenance-mode deactivate --path=" . escapeshellarg($public_html) . " 2>&1");
    }

    // --- PHASE 3: DATABASE UPDATE ---

    // Update the WordPress meta so the dashboard reflects the new domain
    update_post_meta($hosting_id, 'skyhshoso_hosting_domain', $new_domain);
    
    wp_send_json_success(['message' => 'Custom domain assigned and WordPress URLs updated securely.']);
}