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

// Aggregated Dashboard View & Native SSO
add_action('wp_ajax_skyhshoso_get_wp_site_page', 'skyhshoso_get_wp_site_page');
add_action('wp_ajax_skyhshoso_generate_wp_sso', 'skyhshoso_generate_wp_sso');
add_action('wp_ajax_skyhshoso_assign_custom_domain', 'skyhshoso_assign_custom_domain');

// Endpoints mapped for the individual cPanel WP Management Block
add_action('wp_ajax_skyhshoso_scan_wp_sites', 'skyhshoso_scan_wp_sites');
add_action('wp_ajax_skyhshoso_wp_sso_login', 'skyhshoso_generate_wp_sso'); // Aliased to use the existing SSO script
add_action('wp_ajax_skyhshoso_change_wp_domain', 'skyhshoso_assign_custom_domain'); // Aliased to use the robust payload injector
/**
 * =========================================================================
 * INSTALL NEW WORDPRESS INSTANCE (WP-CLI PAYLOAD INJECTION)
 * =========================================================================
 */
add_action('wp_ajax_skyhshoso_wp_provision', 'skyhshoso_wp_provision_callback');
function skyhshoso_wp_provision_callback() {
    $nonce = $_POST['nonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'skyhshoso_wp_provision') && !wp_verify_nonce($nonce, 'skyhshoso_dashboard_nonce')) {
        wp_send_json_error(['message' => 'Security check failed.']);
    }

    $hosting_id = isset($_POST['wp_site_id']) ? absint($_POST['wp_site_id']) : 0;
    $domain = sanitize_text_field($_POST['domain']);
    $admin_user = !empty($_POST['admin_user']) ? sanitize_user($_POST['admin_user']) : 'admin';
    $admin_email = !empty($_POST['admin_email']) ? sanitize_email($_POST['admin_email']) : 'admin@' . $domain;
    $admin_pass = !empty($_POST['admin_pass']) ? sanitize_text_field($_POST['admin_pass']) : wp_generate_password(16, true);

    $current_user_id = get_current_user_id();
    $post_author_id = absint(get_post_field('post_author', $hosting_id));
    if ($current_user_id !== $post_author_id && !current_user_can('administrator')) {
        wp_send_json_error(['message' => 'Permission denied.']);
    }

    $server_id = get_post_meta($hosting_id, 'skyhshoso_server_id', true);
    $username  = get_post_meta($hosting_id, 'skyhshoso_hosting_username', true);
    $primary_domain = get_post_meta($hosting_id, 'skyhshoso_hosting_domain', true);
    
    $whm_api_user  = get_post_meta($server_id, '_skyhshoso_whm_user_id', true);
    $whm_api_token = get_post_meta($server_id, '_skyhshoso_whm_token', true);
    $whm_api_host  = get_post_meta($server_id, '_skyhshoso_whm_host', true);
    
    if (!class_exists('SkyHSHOSO_WHM_API')) require_once dirname(__FILE__) . '/class-whm-integration.php';
    $whm_api = new SkyHSHOSO_WHM_API($whm_api_user, $whm_api_token, $whm_api_host);

    $clean_domain = str_replace(['www.', 'https://', 'http://'], '', rtrim($domain, '/'));
    $clean_primary = str_replace(['www.', 'https://', 'http://'], '', rtrim($primary_domain, '/'));

    // 1. Determine Document Root & Smart Routing (Subdomain vs Addon Domain)
    $doc_root = 'public_html';
    $relative_trigger_path = '';

    if ($clean_domain !== $clean_primary) {
        $doc_root = 'public_html/' . $clean_domain;
        $relative_trigger_path = '/' . $clean_domain;
        
        // Detect if the requested domain is a Subdomain of the Primary Domain
        $primary_tld_pattern = '/\.' . preg_quote($clean_primary, '/') . '$/i';
        
        if (preg_match($primary_tld_pattern, $clean_domain)) {
            // It is a SUBDOMAIN (e.g. wp732.primary.com)
            $sub_prefix = preg_replace($primary_tld_pattern, '', $clean_domain);
            
            $whm_api->cpanel_uapi_call_v3_raw($username, 'SubDomain', 'addsubdomain', [
                'domain'     => $sub_prefix,
                'rootdomain' => $clean_primary,
                'dir'        => $doc_root
            ]);
        } else {
            // It is a completely different ADDON DOMAIN (e.g. anotherwebsite.com)
            $addon_args = [
                'newdomain' => $clean_domain,
                'dir'       => $doc_root,
                'subdomain' => substr(str_replace(['.', '-'], '_', $clean_domain), 0, 8) . rand(10,99)
            ];
            $whm_api->cpanel_uapi_call_v3_raw($username, 'AddonDomain', 'addaddondomain', $addon_args);
        }
    }

    // 2. Create MySQL Database and User via UAPI
    $db_suffix = substr(md5(time()), 0, 4);
    $db_name = substr($username, 0, 7) . '_wp' . $db_suffix;
    $db_user = substr($username, 0, 7) . '_usr' . $db_suffix;
    $db_pass = wp_generate_password(16, false);

    $whm_api->cpanel_uapi_call_v3_raw($username, 'Mysql', 'create_database', ['name' => $db_name]);
    $whm_api->cpanel_uapi_call_v3_raw($username, 'Mysql', 'create_user', ['name' => $db_user, 'password' => $db_pass]);
    $whm_api->cpanel_uapi_call_v3_raw($username, 'Mysql', 'set_privileges_on_database', [
        'user' => $db_user, 
        'database' => $db_name, 
        'privileges' => 'ALL PRIVILEGES'
    ]);

    // 3. Build WP-CLI Payload Installer
    $token = wp_generate_password(20, false);
    $filename = "skyhs_install_{$token}.php";

    $payload = "<?php
    set_time_limit(300);
    chdir(__DIR__);
    \$log = [];
    
    if (!file_exists('wp-cli.phar')) {
        copy('https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar', 'wp-cli.phar');
    }
    
    exec('php wp-cli.phar core download --allow-root 2>&1', \$out1);
    \$log[] = implode(\"\\n\", \$out1);
    
    exec('php wp-cli.phar config create --dbname=\"{$db_name}\" --dbuser=\"{$db_user}\" --dbpass=\"{$db_pass}\" --allow-root 2>&1', \$out2);
    \$log[] = implode(\"\\n\", \$out2);
    
    exec('php wp-cli.phar core install --url=\"https://{$clean_domain}\" --title=\"My WordPress Site\" --admin_user=\"{$admin_user}\" --admin_password=\"{$admin_pass}\" --admin_email=\"{$admin_email}\" --allow-root 2>&1', \$out3);
    \$log[] = implode(\"\\n\", \$out3);
    
    @unlink('wp-cli.phar');
    @unlink(__FILE__);
    
    echo 'SUCCESS|||' . print_r(\$log, true);
    ";

    // 4. Inject Payload via Fileman
    $whm_api->cpanel_uapi_call_v3_raw($username, 'Fileman', 'save_file_content', [
        'dir' => $doc_root,
        'file' => $filename,
        'content' => $payload
    ]);

    // 5. Trigger the Payload via the PRIMARY DOMAIN
    $trigger_url = "http://{$clean_primary}{$relative_trigger_path}/{$filename}";
    $response = wp_remote_get($trigger_url, ['timeout' => 60, 'sslverify' => false]);

    // Failsafe cleanup if HTTP failed completely
    $whm_api->cpanel_uapi_call_v3_raw($username, 'Fileman', 'file_op', [
        'op' => 'unlink',
        'sourcefiles' => $doc_root . '/' . $filename
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error(['message' => 'Failed to trigger installation. The primary domain (' . $clean_primary . ') is not resolving.']);
    }

    $body = wp_remote_retrieve_body($response);
    
    if (strpos($body, 'SUCCESS') !== false) {
        SkyHSHOSO_WHM_API::clear_stats_cache($hosting_id);
        wp_send_json_success(['message' => 'WordPress Installed Successfully!']);
    } else {
        $clean_error = strip_tags($body);
        wp_send_json_error(['message' => 'Installation script executed but WP-CLI failed. Log: ' . substr($clean_error, 0, 500)]);
    }
}

function skyhshoso_generate_cpanel_login_url() {
    $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'skyhshoso_generate_cpanel_login_url_nonce' ) && 
         ! wp_verify_nonce( $nonce, 'skyhshoso_dashboard_nonce' ) &&
         ! wp_verify_nonce( $nonce, 'skyhshoso_get_cpanel_accounts' ) ) {
        wp_send_json_error( array( 'message' => esc_html__( 'Security check failed.', 'skyhs-hosting-solution' ) ) );
        wp_die();
    }
    
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in to access this feature.', 'skyhs-hosting-solution' ) ) );
        wp_die();
    }

    $hosting_id = isset($_POST['hosting_id']) ? absint( $_POST['hosting_id'] ) : 0;
    $server_id = get_post_meta($hosting_id, 'skyhshoso_server_id', true);
    if (empty($hosting_id) || empty($server_id)) {
        wp_send_json_error( array( 'message' => esc_html__( 'Hosting ID and Server ID are required', 'skyhs-hosting-solution' ) ) );
        wp_die();
    }
    
    $current_user_id = get_current_user_id();
    $post_author_id = absint( get_post_field('post_author', $hosting_id) );
    $invited_by = get_user_meta($current_user_id, 'skyhshoso_invited_by', true);
    $invited_by = is_array($invited_by) ? array_map('absint', $invited_by) : array();

    if ( $current_user_id !== $post_author_id && ! current_user_can( 'administrator' ) && ! in_array( $post_author_id, $invited_by, true ) ) {
        wp_send_json_error( array( 'message' => esc_html__( 'You do not have permission to access this cPanel.', 'skyhs-hosting-solution' ) ) );
        wp_die();
    }

    $username = get_post_meta($hosting_id, 'skyhshoso_hosting_username', true);
    if ( empty( $username ) ) {
        wp_send_json_error( array( 'message' => esc_html__( 'Username is required.', 'skyhs-hosting-solution' ) ) );
        wp_die();
    }

    $whm_username = get_post_meta( $server_id, '_skyhshoso_whm_user_id', true );
    $whm_token = get_post_meta( $server_id, '_skyhshoso_whm_token', true );
    $whm_host = get_post_meta( $server_id, '_skyhshoso_whm_host', true );

    if ( empty( $whm_username ) || empty( $whm_token ) || empty( $whm_host ) ) {
        wp_send_json_error( array( 'message' => esc_html__( 'WHM credentials are missing.', 'skyhs-hosting-solution' ) ) );
        wp_die();
    }

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

    if (!class_exists('SkyHSHOSO_WHM_API')) {
        require_once dirname(__FILE__) . '/class-whm-integration.php';
    }
    $whm_api = new SkyHSHOSO_WHM_API( $whm_api_user, $whm_api_token, $whm_api_host );
    $usage = $whm_api->get_account_summary( $whm_user );
    $stats = $whm_api->get_all_account_stats( $hosting_id, $whm_user, $domain );
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
    $hosting_id = isset( $_POST['hosting_id'] ) ? absint( $_POST['hosting_id'] ) : 0;
    
    $_POST['hosting_id'] = $hosting_id;
    skyhshoso_get_cpanel_stats();
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

function skyhshoso_toggle_ssh() { }
function skyhshoso_reset_password() { }

/**
 * =========================================================================
 * SCANNER: Dropdown Populator for specific cPanel accounts
 * =========================================================================
 */
function skyhshoso_scan_wp_sites() {
    $nonce = $_POST['nonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'skyhshoso_wp_nonce') && !wp_verify_nonce($nonce, 'skyhshoso_dashboard_nonce')) {
        wp_send_json_error(['message' => 'Security check failed.']);
    }
    
    $hosting_id = isset($_POST['hosting_id']) ? absint($_POST['hosting_id']) : 0;
    if (!$hosting_id) {
        wp_send_json_error(['message' => 'Missing hosting ID.']);
    }

    $current_user_id = get_current_user_id();
    $post_author_id = absint(get_post_field('post_author', $hosting_id));
    $invited_by = get_user_meta($current_user_id, 'skyhshoso_invited_by', true);
    $invited_by = is_array($invited_by) ? array_map('absint', $invited_by) : [];

    if ($current_user_id !== $post_author_id && !current_user_can('administrator') && !in_array($post_author_id, $invited_by, true)) {
        wp_send_json_error(['message' => 'Permission denied.']);
    }

    $server_id = get_post_meta($hosting_id, 'skyhshoso_server_id', true);
    $username  = get_post_meta($hosting_id, 'skyhshoso_hosting_username', true);
    $domain    = get_post_meta($hosting_id, 'skyhshoso_hosting_domain', true);

    if (empty($username) || empty($server_id)) {
        wp_send_json_error(['message' => 'Server or username missing.']);
    }

    $whm_api_user  = get_post_meta($server_id, '_skyhshoso_whm_user_id', true);
    $whm_api_token = get_post_meta($server_id, '_skyhshoso_whm_token', true);
    $whm_api_host  = get_post_meta($server_id, '_skyhshoso_whm_host', true);

    if (empty($whm_api_user) || empty($whm_api_token) || empty($whm_api_host)) {
        wp_send_json_error(['message' => 'WHM credentials missing.']);
    }

    if (!class_exists('SkyHSHOSO_WHM_API')) {
        require_once dirname(__FILE__) . '/class-whm-integration.php';
    }
    
    $whm_api = new SkyHSHOSO_WHM_API($whm_api_user, $whm_api_token, $whm_api_host);
    $debug_data = [];
    $discovered_sites = $whm_api->check_wordpress($username, $domain, $debug_data);

    $sites = [];
    if (!empty($discovered_sites)) {
        foreach ($discovered_sites as $site) {
            $sites[] = [
                'url'      => rtrim($site['site_url'], '/'),
                'path'     => $site['doc_root'] ?? '',
                'doc_root' => rtrim($site['doc_root'] ?? '', '/'),
                'insid'    => $site['insid'] ?? ''
            ];
        }
    }
    
    if (!empty($sites)) {
        wp_send_json_success(['sites' => $sites]);
    } else {
        wp_send_json_error(['message' => 'No WordPress installations found on this account.']);
    }
}


/**
 * =========================================================================
 * UNIVERSAL SSO INJECTOR (NATIVE AUTO-LOGIN WITHOUT SOFTACULOUS)
 * =========================================================================
 */
function skyhshoso_generate_wp_sso() {
    $nonce = $_POST['nonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'skyhshoso_wp_nonce') && !wp_verify_nonce($nonce, 'skyhshoso_dashboard_nonce')) {
        wp_send_json_error(['message' => 'Security check failed.']);
    }
    
    $current_user_id = get_current_user_id();
    if (!$current_user_id) wp_send_json_error(['message' => 'Not logged in']);

    $hosting_id = isset($_POST['hosting_id']) ? absint($_POST['hosting_id']) : 0;
    $site_url = isset($_POST['site_url']) ? esc_url_raw($_POST['site_url']) : (isset($_POST['wp_path']) ? esc_url_raw($_POST['wp_path']) : '');

    if (!$hosting_id || !$site_url) wp_send_json_error(['message' => 'Missing data']);

    $post_author_id = absint(get_post_field('post_author', $hosting_id));
    $invited_by = get_user_meta($current_user_id, 'skyhshoso_invited_by', true);
    $invited_by = is_array($invited_by) ? array_map('absint', $invited_by) : [];

    if ($current_user_id !== $post_author_id && !current_user_can('administrator') && !in_array($post_author_id, $invited_by, true)) {
        wp_send_json_error(['message' => 'Permission denied.']);
    }

    $server_id  = get_post_meta($hosting_id, 'skyhshoso_server_id', true);
    $username   = get_post_meta($hosting_id, 'skyhshoso_hosting_username', true);
    $admin_user = get_post_meta($hosting_id, 'skyhshoso_wp_admin_user', true) ?: 'admin';

    $whm_api_user  = get_post_meta($server_id, '_skyhshoso_whm_user_id', true);
    $whm_api_token = get_post_meta($server_id, '_skyhshoso_whm_token', true);
    $whm_api_host  = get_post_meta($server_id, '_skyhshoso_whm_host', true);

    if (!class_exists('SkyHSHOSO_WHM_API')) {
        require_once dirname(__FILE__) . '/class-whm-integration.php';
    }

    $whm_api = new SkyHSHOSO_WHM_API($whm_api_user, $whm_api_token, $whm_api_host);

    $sso_link = $whm_api->inject_wp_sso_script($username, $site_url, $admin_user);

    if ($sso_link) {
        wp_send_json_success(['url' => $sso_link]);
    } else {
        wp_send_json_error(['message' => 'Could not establish secure remote token injection.']);
    }
}

/**
 * =========================================================================
 * ASSIGN CUSTOM DOMAIN & WP DATABASE MIGRATOR
 * Uses a Self-Destructing Payload to update wp_options remotely!
 * =========================================================================
 */
function skyhshoso_assign_custom_domain() {
    $nonce = $_POST['nonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'skyhshoso_wp_nonce') && !wp_verify_nonce($nonce, 'skyhshoso_dashboard_nonce')) {
        wp_send_json_error(['message' => 'Security check failed.']);
    }
    
    $hosting_id = absint($_POST['hosting_id']);
    $new_domain = sanitize_text_field($_POST['new_domain']);
    $old_url    = esc_url_raw($_POST['old_url'] ?? $_POST['wp_path'] ?? '');
    $doc_root   = sanitize_text_field($_POST['doc_root'] ?? ''); 
    
    // Clean inputs
    $new_domain = preg_replace('#^https?://#i', '', $new_domain);
    $new_domain = preg_replace('#^www\.#i', '', $new_domain);
    $new_domain = rtrim($new_domain, '/');
    $new_url_https = "https://" . $new_domain;

    $old_url_clean = rtrim($old_url, '/');
    $old_domain = preg_replace('#^https?://#i', '', $old_url_clean);
    $old_domain = preg_replace('#^www\.#i', '', $old_domain);

    // Security: Verify Ownership
    $current_user_id = get_current_user_id();
    $post_author_id = absint(get_post_field('post_author', $hosting_id));
    if ($current_user_id !== $post_author_id && !current_user_can('administrator')) {
        wp_send_json_error(['message' => 'Permission denied.']);
    }

    $server_id = get_post_meta($hosting_id, 'skyhshoso_server_id', true);
    $username  = get_post_meta($hosting_id, 'skyhshoso_hosting_username', true);
    $current_primary = get_post_meta($hosting_id, 'skyhshoso_hosting_domain', true);
    $current_primary_clean = preg_replace('#^www\.#i', '', $current_primary);
    
    $whm_api_user = get_post_meta($server_id, '_skyhshoso_whm_user_id', true);
    $whm_api_token = get_post_meta($server_id, '_skyhshoso_whm_token', true);
    $whm_api_host  = get_post_meta($server_id, '_skyhshoso_whm_host', true);
    
    if (!class_exists('SkyHSHOSO_WHM_API')) {
        require_once dirname(__FILE__) . '/class-whm-integration.php';
    }
    $whm_api = new SkyHSHOSO_WHM_API($whm_api_user, $whm_api_token, $whm_api_host);

    // Calculate Relative Directory for Fileman
    $relative_dir = preg_replace('#^/?home/' . preg_quote($username, '#') . '/#', '', $doc_root);
    if (empty($relative_dir)) $relative_dir = 'public_html';

    // Is this the main domain being swapped?
    $is_primary = ($relative_dir === 'public_html' && $old_domain === $current_primary_clean);

    // ---------------------------------------------------------
    // PHASE 1: Server Infrastructure Domain Update
    // ---------------------------------------------------------
    $domain_msg = "";
    if ($is_primary) {
        // Change the main WHM account domain
        $whm_result = $whm_api->call('modifyacct', [
            'api.version' => 1,
            'user' => $username,
            'domain' => $new_domain
        ]);
        
        // Strict numerical check. Result 1 = Success
        if (isset($whm_result['metadata']['result']) && $whm_result['metadata']['result'] == 1) {
            update_post_meta($hosting_id, 'skyhshoso_hosting_domain', $new_domain);
            $domain_msg = "Primary domain updated in WHM.";
        } else {
            // Give exact API error out to screen
            $err_reason = $whm_result['metadata']['reason'] ?? json_encode($whm_result);
            wp_send_json_error(['message' => 'WHM Error: ' . $err_reason]);
        }
    } else {
        // Create an Addon Domain pointing to the sub-folder
        $addon_args = [
            'newdomain' => $new_domain,
            'dir' => $relative_dir,
            'subdomain' => substr(str_replace(['.', '-'], '_', $new_domain), 0, 8) . rand(100,999)
        ];
        $uapi_result = $whm_api->cpanel_uapi_call_v3_raw($username, 'AddonDomain', 'addaddondomain', $addon_args);
        
        if (isset($uapi_result['result']['status']) && $uapi_result['result']['status']) {
            $domain_msg = "Addon domain created.";
        } else {
            $err = $uapi_result['result']['errors'][0] ?? 'Unknown API error';
            wp_send_json_error(['message' => 'Failed to create addon domain: ' . $err]);
        }
    }

    // Trigger AutoSSL for the new domain
    $whm_api->cpanel_uapi_call_v3_raw($username, 'SSL', 'start_autossl_check', []);

    // ---------------------------------------------------------
    // PHASE 2: WordPress Database Migration (via Payload Injection)
    // ---------------------------------------------------------
    $token = wp_generate_password(24, false);
    $filename = "skyhs_migrate_{$token}.php";

    $php_code = "<?php\n";
    $php_code .= "define('WP_USE_THEMES', false);\n";
    $php_code .= "require('./wp-load.php');\n";
    $php_code .= "update_option('siteurl', '{$new_url_https}');\n";
    $php_code .= "update_option('home', '{$new_url_https}');\n";
    $php_code .= "global \$wpdb;\n";
    $php_code .= "\$wpdb->query(\$wpdb->prepare(\"UPDATE {\$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s)\", '{$old_url_clean}', '{$new_url_https}'));\n";
    $php_code .= "\$wpdb->query(\$wpdb->prepare(\"UPDATE {\$wpdb->postmeta} SET meta_value = REPLACE(meta_value, %s, %s)\", '{$old_url_clean}', '{$new_url_https}'));\n";
    $php_code .= "echo 'MIGRATION_SUCCESS';\n";
    $php_code .= "@unlink(__FILE__);\n";

    // Inject the migration script remotely
    $whm_api->cpanel_uapi_call_v3_raw($username, 'Fileman', 'save_file_content', [
        'dir' => trim($relative_dir, '/'),
        'file' => $filename,
        'content' => $php_code
    ]);

    // Trigger the script via HTTP. We use the NEW URL because the domain change was just successfully completed via WHM.
    // Ensure you have pointed your DNS for the new domain to this server before triggering this!
    $trigger_url_new = $new_url_https . '/' . $filename;
    wp_remote_get($trigger_url_new, ['timeout' => 5, 'sslverify' => false]);

    // Safety fallback: Clean up the file forcefully if HTTP failed to trigger it
    $whm_api->cpanel_uapi_call_v3_raw($username, 'Fileman', 'file_op', [
        'op' => 'unlink',
        'sourcefiles' => trim($relative_dir, '/') . '/' . $filename
    ]);

    // Clear caches so the dashboard refreshes correctly
    SkyHSHOSO_WHM_API::clear_stats_cache($hosting_id);

    wp_send_json_success(['message' => 'Domain changed successfully! Database URLs have been migrated.']);
}


/**
 * =========================================================================
 * AGGREGATED WORDPRESS SITES FLEET SCANNER
 * =========================================================================
 */
function skyhshoso_get_wp_site_page() {
    check_ajax_referer('skyhshoso_dashboard_nonce', 'nonce');
    
    $current_user_id = get_current_user_id();
    if (!$current_user_id) {
        wp_send_json_error(['message' => 'Not logged in']);
    }

    $all_sites = [];
    $global_debug_log = [];

    // 1. Fetch WP Sites explicitly provisioned via the Plugin (skyhshoso_wp_site CPT)
    $wp_posts = get_posts([
        'post_type'      => 'skyhshoso_wp_site',
        'author'         => $current_user_id,
        'posts_per_page' => -1,
        'post_status'    => 'publish'
    ]);

    foreach ($wp_posts as $post) {
        $site_url = get_post_meta($post->ID, '_skyhshoso_wp_site_url', true);
        $hosting_id = get_post_meta($post->ID, 'skyhshoso_server_id', true); 
        
        if (!$hosting_id) {
            $sub_id = get_post_meta($post->ID, 'skyhshoso_subscription_id', true);
            if ($sub_id) {
                $linked_hosting = get_posts([
                    'post_type' => 'skyhshoso_hosting',
                    'meta_query' => [
                        ['key' => 'skyhshoso_subscription_id', 'value' => $sub_id, 'compare' => '=']
                    ],
                    'posts_per_page' => 1
                ]);
                if (!empty($linked_hosting)) {
                    $hosting_id = $linked_hosting[0]->ID;
                }
            }
        }

        if ($site_url) {
            $username = get_post_meta($hosting_id, 'skyhshoso_hosting_username', true);
            $all_sites[rtrim($site_url, '/')] = [
                'site_url'   => $site_url,
                'admin_url'  => rtrim($site_url, '/') . '/wp-admin/',
                'source'     => 'Auto-Provisioned',
                'hosting_id' => $hosting_id,
                'insid'      => '',
                'doc_root'   => "/home/{$username}/public_html" 
            ];
        }
    }

    // 2. Scan WHM Hosting Accounts for manual/WP Toolkit installs
    $hosting_posts = get_posts([
        'post_type'      => 'skyhshoso_hosting',
        'author'         => $current_user_id,
        'posts_per_page' => -1,
        'post_status'    => 'publish'
    ]);

    if (!class_exists('SkyHSHOSO_WHM_API')) {
        require_once dirname(__FILE__) . '/class-whm-integration.php';
    }

    foreach ($hosting_posts as $hosting) {
        $hosting_id = $hosting->ID;
        $server_id = get_post_meta($hosting_id, 'skyhshoso_server_id', true);
        $username = get_post_meta($hosting_id, 'skyhshoso_hosting_username', true);
        $domain = get_post_meta($hosting_id, 'skyhshoso_hosting_domain', true);

        if (empty($username) || empty($server_id)) continue;

        $whm_api_user  = get_post_meta($server_id, '_skyhshoso_whm_user_id', true);
        $whm_api_token = get_post_meta($server_id, '_skyhshoso_whm_token', true);
        $whm_api_host  = get_post_meta($server_id, '_skyhshoso_whm_host', true);

        if (empty($whm_api_user) || empty($whm_api_token) || empty($whm_api_host)) {
            $global_debug_log[$username] = ["Missing WHM credentials on server post $server_id"];
            continue;
        }

        $whm_api = new SkyHSHOSO_WHM_API($whm_api_user, $whm_api_token, $whm_api_host);
        
        $debug_data = [];
        $discovered_sites = $whm_api->check_wordpress($username, $domain, $debug_data);
        $global_debug_log[$username] = $debug_data;

        if (!empty($discovered_sites)) {
            foreach ($discovered_sites as $site) {
                $url_key = rtrim($site['site_url'], '/');
                if (!isset($all_sites[$url_key])) {
                    $all_sites[$url_key] = [
                        'site_url'   => $site['site_url'],
                        'admin_url'  => rtrim($site['site_url'], '/') . '/wp-admin/',
                        'source'     => 'cPanel Discovered',
                        'hosting_id' => $hosting_id,
                        'insid'      => $site['insid'] ?? '',
                        'doc_root'   => $site['doc_root'] ?? ''
                    ];
                }
            }
        }
    }

    // 3. Build HTML Output for the Frontend Table
    $html = '';
    if (empty($all_sites)) {
        $debug_string = esc_html(json_encode($global_debug_log, JSON_PRETTY_PRINT));
        $html = '<tr><td colspan="3" style="padding:32px;text-align:center;color:#6b7280;background:#f8fafc;border-bottom:1px solid #e2e8f0;">';
        $html .= '<h3 style="margin:0 0 8px;font-size:16px;color:#d63638;font-weight:700;">No WordPress Installations Found</h3>';
        $html .= '<p style="margin:0 0 16px;font-size:14px;color:#475569;">The scanner checked your cPanel account(s) but did not find any <code>wp-config.php</code> files. If you believe this is an error, please provide the raw API output below to support:</p>';
        $html .= '<textarea style="width:100%;height:200px;font-family:monospace;font-size:11px;background:#0f172a;color:#818cf8;padding:12px;border-radius:6px;border:1px solid #334155;box-sizing:border-box;resize:vertical;" readonly>' . $debug_string . '</textarea>';
        $html .= '</td></tr>';
    } else {
        foreach ($all_sites as $site) {
            $site_url = esc_url($site['site_url']);
            $admin_url = esc_url($site['admin_url']);
            $insid = esc_attr($site['insid']);
            $hosting_id = esc_attr($site['hosting_id']);
            $doc_root = esc_attr($site['doc_root']);
            $clean_url = str_replace(['https://', 'http://'], '', $site_url);
            
            $html .= '<tr style="background:#fff;border-bottom:1px solid #e5e7eb;">';
            $html .= '<td style="padding:16px;vertical-align:middle;">';
            $html .= '<a href="' . $site_url . '" target="_blank" style="font-weight:600;color:#2563eb;font-size:14px;text-decoration:none;">' . $clean_url . '</a>';
            $html .= '<div style="font-size:12px;color:#6b7280;margin-top:4px;">Source: ' . esc_html($site['source']) . '</div>';
            $html .= '</td>';
            
            $html .= '<td style="padding:16px;vertical-align:middle;">';
            $html .= '<span style="display:inline-flex;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:600;background:#dcfce7;color:#166534;border:1px solid #bbf7d0;">Active</span>';
            $html .= '</td>';
            
            $html .= '<td style="padding:16px;text-align:right;vertical-align:middle;">';
            $html .= '<div style="display:flex;gap:8px;justify-content:flex-end;">';
            
            // The Change Domain Button
            $html .= '<button class="skyhshoso-button skyhshoso-wp-change-domain-btn" data-hosting-id="' . $hosting_id . '" data-old-url="' . $site_url . '" data-docroot="' . $doc_root . '" data-nonce="' . wp_create_nonce('skyhshoso_dashboard_nonce') . '" style="background:#f59e0b;color:#fff;border:none;padding:6px 12px;font-size:13px;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;gap:4px;font-weight:500;">Change Domain</button>';

            if ($insid) {
                $html .= '<button class="skyhshoso-button skyhshoso-wp-sso-btn" data-hosting-id="' . $hosting_id . '" data-nonce="' . wp_create_nonce('skyhshoso_dashboard_nonce') . '" data-insid="' . $insid . '" style="background:#2563eb;color:#fff;border:none;padding:6px 12px;font-size:13px;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;gap:4px;font-weight:500;">WP Admin</button>';
            } else {
                $html .= '<button class="skyhshoso-button skyhshoso-wp-direct-sso-btn" data-site-url="' . $site_url . '" data-hosting-id="' . $hosting_id . '" data-nonce="' . wp_create_nonce('skyhshoso_dashboard_nonce') . '" style="background:#2563eb;color:#fff;border:none;padding:6px 12px;font-size:13px;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;gap:4px;font-weight:500;">WP Admin</button>';
            }
            
            $html .= '<button class="skyhshoso-button skyhshoso-cpanel-login-btn" data-hosting-id="' . $hosting_id . '" data-nonce="' . wp_create_nonce('skyhshoso_dashboard_nonce') . '" style="background:#1f2937;color:#fff;border:none;padding:6px 12px;font-size:13px;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;gap:4px;font-weight:500;">WP Toolkit</button>';
            
            $html .= '</div>';
            $html .= '</td>';
            $html .= '</tr>';
        }
    }

    wp_send_json_success([
        'html' => $html,
        'current_page' => 1,
        'total_pages' => 1
    ]);
}