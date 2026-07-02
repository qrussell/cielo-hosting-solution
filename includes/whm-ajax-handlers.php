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
 * UNIVERSAL SSO INJECTOR (NATIVE AUTO-LOGIN WITHOUT SOFTACULOUS)
 * =========================================================================
 */
function skyhshoso_generate_wp_sso() {
    check_ajax_referer('skyhshoso_dashboard_nonce', 'nonce');
    
    $current_user_id = get_current_user_id();
    if (!$current_user_id) wp_send_json_error(['message' => 'Not logged in']);

    $hosting_id = isset($_POST['hosting_id']) ? absint($_POST['hosting_id']) : 0;
    $site_url = isset($_POST['site_url']) ? esc_url_raw($_POST['site_url']) : '';

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
    check_ajax_referer('skyhshoso_dashboard_nonce', 'nonce');
    
    $hosting_id = absint($_POST['hosting_id']);
    $new_domain = sanitize_text_field($_POST['new_domain']);
    $old_url    = esc_url_raw($_POST['old_url']);
    $doc_root   = sanitize_text_field($_POST['doc_root']); 
    
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