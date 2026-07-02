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
 * PROVISION DOMAIN ONLY (WITH STRICT DOCUMENT ROOT ISOLATION)
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

    // 1. Determine Document Root & Create Domain
    $doc_root = 'public_html'; // The Primary domain stays safely in public_html
    
    if ($clean_domain !== $clean_primary) {
        // THE FIX: Set doc root outside public_html to prevent URL bleeding!
        // This places the files at /home/username/newsite.com/ securely.
        $doc_root = $clean_domain;

        $primary_tld_pattern = '/\.' . preg_quote($clean_primary, '/') . '$/i';
        
        if (preg_match($primary_tld_pattern, $clean_domain)) {
            // Register as Subdomain
            $sub_prefix = preg_replace($primary_tld_pattern, '', $clean_domain);
            $create_res = $whm_api->call('cpanel', [
                'cpanel_jsonapi_user'       => $username,
                'cpanel_jsonapi_apiversion' => '2',
                'cpanel_jsonapi_module'     => 'SubDomain',
                'cpanel_jsonapi_func'       => 'addsubdomain',
                'domain'                    => $sub_prefix,
                'rootdomain'                => $clean_primary,
                'dir'                       => $doc_root
            ]);
        } else {
            // Register as Addon Domain
            $safe_subdomain_alias = substr(preg_replace('/[^a-zA-Z0-9]/', '', $clean_domain), 0, 8) . rand(100,999);
            $create_res = $whm_api->call('cpanel', [
                'cpanel_jsonapi_user'       => $username,
                'cpanel_jsonapi_apiversion' => '2',
                'cpanel_jsonapi_module'     => 'AddonDomain',
                'cpanel_jsonapi_func'       => 'addaddondomain',
                'newdomain'                 => $clean_domain,
                'dir'                       => $doc_root,
                'subdomain'                 => $safe_subdomain_alias
            ]);
        }

        // Validate API 2 Response
        if (isset($create_res['cpanelresult']['error'])) {
            $reason = $create_res['cpanelresult']['error'];
            if (strpos(strtolower($reason), 'exists') === false && strpos(strtolower($reason), 'configured') === false) {
                 wp_send_json_error(['message' => 'cPanel could not create domain: ' . $reason]);
            }
        } elseif (isset($create_res['cpanelresult']['data'][0]['result']) && $create_res['cpanelresult']['data'][0]['result'] == 0) {
            $reason = $create_res['cpanelresult']['data'][0]['reason'];
            if (strpos(strtolower($reason), 'exists') === false && strpos(strtolower($reason), 'configured') === false) {
                 wp_send_json_error(['message' => 'cPanel could not create domain: ' . $reason]);
            }
        }
        
        // 2. AGGRESSIVE CLEANUP: Remove skeleton files that trigger WP Toolkit errors
        sleep(2); 
        $debris_files = ['index.php', 'default.html', 'php.ini', '.htaccess'];
        foreach ($debris_files as $debris) {
            $whm_api->call('uapi', [
                'user'   => $username,
                'module' => 'Fileman',
                'func'   => 'file_op',
                'op'     => 'unlink',
                'sourcefiles' => $doc_root . '/' . $debris
            ]);
        }
        $whm_api->call('uapi', [
            'user'   => $username,
            'module' => 'Fileman',
            'func'   => 'file_op',
            'op'     => 'rmdir',
            'sourcefiles' => $doc_root . '/cgi-bin'
        ]);
    }

    // 3. Register Domain in the Dashboard Database
    SkyHSHOSO_WHM_API::clear_stats_cache($hosting_id);

    $existing_site = get_posts([
        'post_type'  => 'skyhshoso_wp_site',
        'meta_query' => [
            ['key' => '_skyhshoso_wp_site_url', 'value' => "https://" . $clean_domain, 'compare' => '=']
        ],
        'post_status' => 'publish'
    ]);

    if (empty($existing_site)) {
        $new_wp_site_id = wp_insert_post([
            'post_title'  => $clean_domain,
            'post_type'   => 'skyhshoso_wp_site',
            'post_status' => 'publish',
            'post_author' => $current_user_id,
        ]);

        if ($new_wp_site_id) {
            update_post_meta($new_wp_site_id, '_skyhshoso_wp_site_url', "https://" . $clean_domain);
            update_post_meta($new_wp_site_id, 'skyhshoso_server_id', $hosting_id); 
        }
    }

    // 4. Build Detailed Instructions for the Frontend
    $success_msg = '<div style="text-align: left; line-height: 1.5;">';
    $success_msg .= '<strong style="font-size: 16px; color: #166534;">✓ Domain prepped successfully!</strong><br><br>';
    $success_msg .= 'The server directory is clean and strictly isolated. <strong>Please choose an installer to finish:</strong><br><br>';
    
    $success_msg .= '<strong>Option 1: WP Toolkit (Recommended)</strong><br>';
    $success_msg .= '&bull; Close this window.<br>';
    $success_msg .= '&bull; Click the <strong>"cPanel"</strong> button next to your new site.<br>';
    $success_msg .= '&bull; Open WP Toolkit inside cPanel and click "Install".<br><br>';
    
    $success_msg .= '<strong>Option 2: Installatron or Softaculous</strong><br>';
    $success_msg .= '&bull; Log into your cPanel account.<br>';
    $success_msg .= '&bull; Open Installatron or Softaculous and click Install.<br>';
    $success_msg .= '&bull; Select <code>' . esc_html($clean_domain) . '</code> from the Domain dropdown.<br><br>';
    
    $success_msg .= '<span style="color: #d63638;"><em><strong>Important:</strong> Leave the "Directory" (or "In Directory") field completely blank so WordPress installs to the root of your domain!</em></span>';
    $success_msg .= '</div>';

    wp_send_json_success(['message' => $success_msg]);
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
 * ASYNC WORDPRESS DISCOVERY ENGINE (Populates live in the browser)
 * =========================================================================
 */

// Helper to build HTML rows for the table dynamically
function skyhshoso_build_fleet_row_html($site) {
    $site_url = esc_url($site['site_url']);
    $clean_url = str_replace(['https://', 'http://'], '', $site_url);
    $hosting_id = esc_attr($site['hosting_id']);
    $insid = esc_attr($site['insid']);
    $doc_root = esc_attr($site['doc_root']);
    $source = esc_html($site['source']);

    $html = '<tr style="background:#fff;border-bottom:1px solid #e5e7eb;" class="skyhshoso-wp-row" data-url="'.$clean_url.'">';
    $html .= '<td style="padding:16px;vertical-align:middle;">';
    $html .= '<a href="' . $site_url . '" target="_blank" style="font-weight:600;color:#2563eb;font-size:14px;text-decoration:none;">' . $clean_url . '</a>';
    $html .= '<div style="font-size:12px;color:#6b7280;margin-top:4px;">Source: ' . $source . '</div></td>';
    $html .= '<td style="padding:16px;vertical-align:middle;"><span style="display:inline-flex;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:600;background:#dcfce7;color:#166534;border:1px solid #bbf7d0;">Active</span></td>';
    $html .= '<td style="padding:16px;text-align:right;vertical-align:middle;"><div style="display:flex;gap:8px;justify-content:flex-end;">';

    $html .= '<button class="skyhshoso-button skyhshoso-wp-change-domain-btn" data-hosting-id="' . $hosting_id . '" data-old-url="' . $site_url . '" data-docroot="' . $doc_root . '" data-nonce="' . wp_create_nonce('skyhshoso_dashboard_nonce') . '" style="background:#f59e0b;color:#fff;border:none;padding:6px 12px;font-size:13px;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;gap:4px;font-weight:500;">Change Domain</button>';

    if ($insid) {
        $html .= '<button class="skyhshoso-button skyhshoso-wp-sso-btn" data-hosting-id="' . $hosting_id . '" data-nonce="' . wp_create_nonce('skyhshoso_dashboard_nonce') . '" data-insid="' . $insid . '" style="background:#2563eb;color:#fff;border:none;padding:6px 12px;font-size:13px;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;gap:4px;font-weight:500;">WP Admin</button>';
    } else {
        $html .= '<button class="skyhshoso-button skyhshoso-wp-direct-sso-btn" data-site-url="' . $site_url . '" data-hosting-id="' . $hosting_id . '" data-nonce="' . wp_create_nonce('skyhshoso_dashboard_nonce') . '" style="background:#2563eb;color:#fff;border:none;padding:6px 12px;font-size:13px;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;gap:4px;font-weight:500;">WP Admin</button>';
    }
    
    // CHANGED: The button text is now "cPanel"
    $html .= '<button class="skyhshoso-button skyhshoso-cpanel-login-btn" data-hosting-id="' . $hosting_id . '" data-nonce="' . wp_create_nonce('skyhshoso_dashboard_nonce') . '" style="background:#1f2937;color:#fff;border:none;padding:6px 12px;font-size:13px;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;gap:4px;font-weight:500;">cPanel</button>';
    
    $html .= '</div></td></tr>';

    return $html;
}

// Loads the initial layout and passes the baton to JS
add_action('wp_ajax_skyhshoso_get_wp_site_page', 'skyhshoso_get_wp_site_page');
function skyhshoso_get_wp_site_page() {
    $nonce = $_POST['nonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'skyhshoso_dashboard_nonce') && !wp_verify_nonce($nonce, 'skyhshoso_wp_nonce')) {
        wp_send_json_error(['message' => 'Security check failed.']);
    }
    
    $current_user_id = get_current_user_id();
    $html = '';
    
    $wp_args = ['post_type' => 'skyhshoso_wp_site', 'posts_per_page' => -1, 'post_status' => 'publish'];
    if (!current_user_can('administrator')) $wp_args['author'] = $current_user_id;
    $wp_posts = get_posts($wp_args);

    // Load instantly provisioned local sites
    foreach ($wp_posts as $post) {
        $site_url = get_post_meta($post->ID, '_skyhshoso_wp_site_url', true);
        $hosting_id = get_post_meta($post->ID, 'skyhshoso_server_id', true); 
        if (!$hosting_id) {
            $sub_id = get_post_meta($post->ID, 'skyhshoso_subscription_id', true);
            if ($sub_id) {
                $linked_hosting = get_posts(['post_type' => 'skyhshoso_hosting', 'meta_query' => [['key' => 'skyhshoso_subscription_id', 'value' => $sub_id, 'compare' => '=']], 'posts_per_page' => 1]);
                if (!empty($linked_hosting)) $hosting_id = $linked_hosting[0]->ID;
            }
        }
        if ($site_url) {
            $html .= skyhshoso_build_fleet_row_html(['site_url' => $site_url, 'source' => 'Auto-Provisioned', 'hosting_id' => $hosting_id, 'insid' => '', 'doc_root' => '']);
        }
    }

    // Identify which server accounts the JS should begin scanning
    $hosting_queue = [];
    $h_args = ['post_type' => 'skyhshoso_hosting', 'posts_per_page' => -1, 'post_status' => 'publish'];
    if (!current_user_can('administrator')) $h_args['author'] = $current_user_id;
    $hosting_posts = get_posts($h_args);
    foreach ($hosting_posts as $h) { $hosting_queue[] = $h->ID; }

    wp_send_json_success(['html' => $html, 'hosting_queue' => $hosting_queue, 'current_page' => 1, 'total_pages' => 1]);
}

// Drops initial local sites into the dropdown and triggers the JS scan
add_action('wp_ajax_skyhshoso_scan_wp_sites', 'skyhshoso_scan_wp_sites');
function skyhshoso_scan_wp_sites() {
    $nonce = $_POST['nonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'skyhshoso_dashboard_nonce') && !wp_verify_nonce($nonce, 'skyhshoso_wp_nonce')) {
        wp_send_json_error(['message' => 'Security check failed.']);
    }
    
    $hosting_id = absint($_POST['hosting_id']);
    
    $wp_args = ['post_type' => 'skyhshoso_wp_site', 'posts_per_page' => -1, 'post_status' => 'publish', 'meta_query' => [['key' => 'skyhshoso_server_id', 'value' => $hosting_id, 'compare' => '=']]];
    if (!current_user_can('administrator')) $wp_args['author'] = get_current_user_id();
    $wp_posts = get_posts($wp_args);
    
    $sites = [];
    foreach ($wp_posts as $post) {
        $site_url = get_post_meta($post->ID, '_skyhshoso_wp_site_url', true);
        if ($site_url) {
            $domain_only = str_replace(['https://', 'http://', 'www.'], '', rtrim($site_url, '/'));
            $sites[] = ['url' => rtrim($site_url, '/'), 'doc_root' => 'public_html/' . $domain_only, 'insid' => ''];
        }
    }

    wp_send_json_success(['local_sites' => $sites, 'hosting_queue' => [$hosting_id]]);
}

// ASYNC STEP 1: Fast fetch of all domains/subdirectories to check
add_action('wp_ajax_skyhshoso_get_scan_targets', 'skyhshoso_ajax_get_scan_targets');
function skyhshoso_ajax_get_scan_targets() {
    $nonce = $_POST['nonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'skyhshoso_dashboard_nonce') && !wp_verify_nonce($nonce, 'skyhshoso_wp_nonce')) {
        wp_send_json_error(['message' => 'Security check failed.']);
    }
    $hosting_id = absint($_POST['hosting_id']);
    
    $server_id = get_post_meta($hosting_id, 'skyhshoso_server_id', true);
    $username  = get_post_meta($hosting_id, 'skyhshoso_hosting_username', true);
    $primary_domain = get_post_meta($hosting_id, 'skyhshoso_hosting_domain', true);

    $whm_api_user  = get_post_meta($server_id, '_skyhshoso_whm_user_id', true);
    $whm_api_token = get_post_meta($server_id, '_skyhshoso_whm_token', true);
    $whm_api_host  = get_post_meta($server_id, '_skyhshoso_whm_host', true);

    if (!class_exists('SkyHSHOSO_WHM_API')) require_once dirname(__FILE__) . '/class-whm-integration.php';
    $whm_api = new SkyHSHOSO_WHM_API($whm_api_user, $whm_api_token, $whm_api_host);

    $domains_to_check = [];
    if ($primary_domain) {
        $domains_to_check[] = ['domain' => $primary_domain, 'doc_root' => 'public_html'];
    }

    $domain_data = $whm_api->cpanel_uapi_call_v3($username, 'DomainInfo', 'domains_data');
    if (!empty($domain_data['addon_domains'])) {
        foreach ($domain_data['addon_domains'] as $addon) $domains_to_check[] = ['domain' => $addon['domain'], 'doc_root' => $addon['documentroot']];
    }
    if (!empty($domain_data['sub_domains'])) {
        foreach ($domain_data['sub_domains'] as $sub) $domains_to_check[] = ['domain' => $sub['domain'], 'doc_root' => $sub['documentroot']];
    }

    if (count($domains_to_check) <= 1) {
        $addons = $whm_api->get_addon_domains($username);
        if (is_array($addons)) {
            foreach ($addons as $addon) {
                if (!empty($addon['domain']) && !empty($addon['root'])) {
                    $domains_to_check[] = ['domain' => $addon['domain'], 'doc_root' => $addon['root']];
                }
            }
        }
        $subs = $whm_api->get_subdomains($username);
        if (is_array($subs)) {
            foreach ($subs as $sub) {
                if (!empty($sub['domain']) && !empty($sub['root'])) {
                    $domains_to_check[] = ['domain' => $sub['domain'], 'doc_root' => $sub['root']];
                }
            }
        }
    }

    // THE FIX: Move the Primary Domain to the absolute END of the queue.
    // This allows Addon Domains to "claim" their folders first!
    $primary_domain_key = null;
    foreach ($domains_to_check as $key => $d) {
        if ($d['domain'] === $primary_domain) {
            $primary_domain_key = $key;
            break;
        }
    }
    if ($primary_domain_key !== null) {
        $primary_data = $domains_to_check[$primary_domain_key];
        unset($domains_to_check[$primary_domain_key]);
        $domains_to_check[] = $primary_data;
    }

    $targets = [];
    $queued_doc_roots = []; // STRICT tracking array to prevent duplicate paths

    foreach ($domains_to_check as $d) {
        $domain = trim($d['domain']);
        if (empty($domain)) continue;

        $doc_root_base = preg_replace('#^/?home/' . preg_quote($username, '#') . '/#', '', $d['doc_root']);
        $doc_root_base = trim($doc_root_base, '/');
        if (empty($doc_root_base)) $doc_root_base = 'public_html';

        $dynamic_subs = ['']; // Always check the root of the domain

        // Dynamically scan the document root for ALL existing subdirectories
        $dir_list = $whm_api->cpanel_uapi_call_v3_raw($username, 'Fileman', 'list_files', [
            'dir' => $doc_root_base,
            'types' => 'dir'
        ]);

        if (isset($dir_list['result']['data']) && is_array($dir_list['result']['data'])) {
            foreach ($dir_list['result']['data'] as $item) {
                if (isset($item['type']) && $item['type'] === 'dir') {
                    $dirname = trim($item['file']);
                    
                    if (strpos($dirname, '.') === 0) continue;
                    if (in_array(strtolower($dirname), ['cgi-bin', 'css', 'js', 'images', 'wp-admin', 'wp-includes', 'wp-content', 'vendor', 'node_modules'])) continue;
                    
                    $dynamic_subs[] = '/' . $dirname;
                }
            }
        } else {
            $dynamic_subs = array_merge($dynamic_subs, ['/blog', '/wp', '/wordpress']);
        }

        $dynamic_subs = array_unique($dynamic_subs);

        // Queue all discovered folders
        foreach ($dynamic_subs as $sub) {
            $target_root = $doc_root_base . $sub;
            
            // THE FIX: If this exact folder was already claimed by an Addon domain, skip it entirely!
            if (isset($queued_doc_roots[$target_root])) {
                continue;
            }
            
            // Claim this folder path
            $queued_doc_roots[$target_root] = true;

            $targets[] = [
                'domain' => $domain, 
                'url' => $domain . $sub, 
                'doc_root' => $target_root
            ];
        }
    }

    wp_send_json_success(['targets' => $targets, 'username' => $username, 'hosting_id' => $hosting_id]);
}

// ASYNC STEP 2: Instantly check a target and return formatted HTML to Javascript
add_action('wp_ajax_skyhshoso_check_wp_target', 'skyhshoso_ajax_check_wp_target');
function skyhshoso_ajax_check_wp_target() {
    $nonce = $_POST['nonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'skyhshoso_dashboard_nonce') && !wp_verify_nonce($nonce, 'skyhshoso_wp_nonce')) {
        wp_send_json_error(['message' => 'Security check failed.']);
    }
    
    $hosting_id = absint($_POST['hosting_id']);
    $doc_root = sanitize_text_field($_POST['doc_root']);
    $url = sanitize_text_field($_POST['url']);
    $username = sanitize_text_field($_POST['username']);

    $server_id = get_post_meta($hosting_id, 'skyhshoso_server_id', true);
    $whm_api_user  = get_post_meta($server_id, '_skyhshoso_whm_user_id', true);
    $whm_api_token = get_post_meta($server_id, '_skyhshoso_whm_token', true);
    $whm_api_host  = get_post_meta($server_id, '_skyhshoso_whm_host', true);

    if (!class_exists('SkyHSHOSO_WHM_API')) require_once dirname(__FILE__) . '/class-whm-integration.php';
    $whm_api = new SkyHSHOSO_WHM_API($whm_api_user, $whm_api_token, $whm_api_host);

    $file_req = $whm_api->cpanel_uapi_call_v3_raw($username, 'Fileman', 'get_file_information', [
        'path' => $doc_root . '/wp-config.php'
    ]);

    if (isset($file_req['result']['status']) && $file_req['result']['status'] == 1) {
        $site = ['site_url' => 'https://' . $url, 'source' => 'Server Discovery', 'hosting_id' => $hosting_id, 'insid' => '', 'doc_root' => $doc_root];
        $row_html = skyhshoso_build_fleet_row_html($site);
        $option_html = '<option value="https://' . esc_attr($url) . '" data-docroot="'.esc_attr($doc_root).'">'.esc_html($url).'</option>';
        wp_send_json_success(['is_wp' => true, 'row_html' => $row_html, 'option_html' => $option_html]);
    }
    wp_send_json_success(['is_wp' => false]);
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
 * ASSIGN CUSTOM DOMAIN & WP DATABASE MIGRATOR (DNS BYPASS VERSION)
 * Uses a Self-Destructing Payload to update wp_options remotely!
 * =========================================================================
 */
add_action('wp_ajax_skyhshoso_assign_custom_domain', 'skyhshoso_assign_custom_domain');
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
    
    $whm_api_user  = get_post_meta($server_id, '_skyhshoso_whm_user_id', true);
    $whm_api_token = get_post_meta($server_id, '_skyhshoso_whm_token', true);
    $whm_api_host  = get_post_meta($server_id, '_skyhshoso_whm_host', true);
    
    if (!class_exists('SkyHSHOSO_WHM_API')) {
        require_once dirname(__FILE__) . '/class-whm-integration.php';
    }
    $whm_api = new SkyHSHOSO_WHM_API($whm_api_user, $whm_api_token, $whm_api_host);

    // Calculate Relative Directory for Fileman
    $relative_dir = preg_replace('#^/?home/' . preg_quote($username, '#') . '/#', '', $doc_root);
    if (empty($relative_dir)) $relative_dir = 'public_html';

    // ---------------------------------------------------------
    // PRE-FLIGHT CHECK: Ensure WordPress is actually installed!
    // ---------------------------------------------------------
    $file_check = $whm_api->cpanel_uapi_call_v3_raw($username, 'Fileman', 'get_file_information', [
        'path' => trim($relative_dir, '/') . '/wp-config.php'
    ]);

    if (!isset($file_check['result']['status']) || $file_check['result']['status'] != 1) {
        wp_send_json_error(['message' => 'WordPress is not fully provisioned yet! Please click the "cPanel" button and use WP Toolkit or Installatron to finish installing WordPress before changing the domain.']);
    }
    // ---------------------------------------------------------

    // Is this the main domain being swapped entirely?
    $is_primary = ($relative_dir === 'public_html' && $old_domain === $current_primary_clean);

    // ---------------------------------------------------------
    // PHASE 1: Server Infrastructure Domain Update
    // ---------------------------------------------------------
    if ($is_primary) {
        // Change the main WHM account domain
        $whm_result = $whm_api->call('modifyacct', [
            'api.version' => 1,
            'user' => $username,
            'domain' => $new_domain
        ]);
        
        if (isset($whm_result['metadata']['result']) && $whm_result['metadata']['result'] == 1) {
            update_post_meta($hosting_id, 'skyhshoso_hosting_domain', $new_domain);
        } else {
            $err_reason = $whm_result['metadata']['reason'] ?? json_encode($whm_result);
            wp_send_json_error(['message' => 'WHM Error: ' . $err_reason]);
        }
    } else {
        $safe_subdomain_alias = substr(preg_replace('/[^a-zA-Z0-9]/', '', $new_domain), 0, 8) . rand(100,999);

        // Create an Addon Domain pointing to the specific sub-folder where WP is located
        $create_res = $whm_api->call('cpanel', [
            'cpanel_jsonapi_user'       => $username,
            'cpanel_jsonapi_apiversion' => '2',
            'cpanel_jsonapi_module'     => 'AddonDomain',
            'cpanel_jsonapi_func'       => 'addaddondomain',
            'newdomain'                 => $new_domain,
            'dir'                       => $relative_dir,
            'subdomain'                 => $safe_subdomain_alias
        ]);

        // Validate the API 2 Response
        if (isset($create_res['cpanelresult']['error'])) {
            $reason = $create_res['cpanelresult']['error'];
            if (strpos(strtolower($reason), 'exists') === false && strpos(strtolower($reason), 'configured') === false) {
                 wp_send_json_error(['message' => 'cPanel could not create domain: ' . $reason]);
            }
        } elseif (isset($create_res['cpanelresult']['data'][0]['result']) && $create_res['cpanelresult']['data'][0]['result'] == 0) {
            $reason = $create_res['cpanelresult']['data'][0]['reason'];
            if (strpos(strtolower($reason), 'exists') === false && strpos(strtolower($reason), 'configured') === false) {
                 wp_send_json_error(['message' => 'cPanel could not create domain: ' . $reason]);
            }
        }
    }

    // Trigger AutoSSL for the new domain
    $whm_api->cpanel_uapi_call_v3_raw($username, 'SSL', 'start_autossl_check', []);

    // ---------------------------------------------------------
    // PHASE 2: WordPress Database Migration (DNS BYPASS INJECTION)
    // ---------------------------------------------------------
    $token = wp_generate_password(24, false);
    $filename = "skyhs_migrate_{$token}.php";

    $php_code = "<?php\n";
    $php_code .= "define('WP_USE_THEMES', false);\n";
    $php_code .= "require('./wp-load.php');\n";
    // Replace core URLs
    $php_code .= "update_option('siteurl', '{$new_url_https}');\n";
    $php_code .= "update_option('home', '{$new_url_https}');\n";
    // Search & Replace for images and content links
    $php_code .= "global \$wpdb;\n";
    $php_code .= "\$wpdb->query(\$wpdb->prepare(\"UPDATE {\$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s)\", '{$old_url_clean}', '{$new_url_https}'));\n";
    $php_code .= "\$wpdb->query(\$wpdb->prepare(\"UPDATE {\$wpdb->postmeta} SET meta_value = REPLACE(meta_value, %s, %s)\", '{$old_url_clean}', '{$new_url_https}'));\n";
    $php_code .= "echo 'MIGRATION_SUCCESS';\n";
    $php_code .= "@unlink(__FILE__);\n";

    // Inject the migration script remotely into the correct document root
    $whm_api->cpanel_uapi_call_v3_raw($username, 'Fileman', 'save_file_content', [
        'dir' => trim($relative_dir, '/'),
        'file' => $filename,
        'content' => $php_code
    ]);

    // DNS BYPASS FIX: Trigger the script using the Server IP and spoofing the Host header
    $whm_host_domain = parse_url($whm_api_host, PHP_URL_HOST) ?: $whm_api_host;
    $whm_host_domain = preg_replace('/:\d+$/', '', $whm_host_domain);
    $server_ip = gethostbyname($whm_host_domain);

    $trigger_url = "http://{$server_ip}/{$filename}";
    
    $response = wp_remote_get($trigger_url, [
        'timeout' => 45, 
        'sslverify' => false,
        'headers' => [
            'Host' => $new_domain // Tricks Apache into serving the exact folder for the new domain!
        ]
    ]);

    // Safety fallback: Clean up the file forcefully if HTTP failed to self-destruct
    $whm_api->cpanel_uapi_call_v3_raw($username, 'Fileman', 'file_op', [
        'op' => 'unlink',
        'sourcefiles' => trim($relative_dir, '/') . '/' . $filename
    ]);

    // ---------------------------------------------------------
    // PHASE 3: Update Dashboard Cache & Database
    // ---------------------------------------------------------
    
    // Update the local WordPress Sites table so it displays the new domain instantly
    $existing_sites = get_posts([
        'post_type'  => 'skyhshoso_wp_site',
        'meta_query' => [
            ['key' => '_skyhshoso_wp_site_url', 'value' => $old_url_clean, 'compare' => 'LIKE']
        ],
        'post_status' => 'publish'
    ]);

    if (!empty($existing_sites)) {
        update_post_meta($existing_sites[0]->ID, '_skyhshoso_wp_site_url', $new_url_https);
        wp_update_post([
            'ID' => $existing_sites[0]->ID,
            'post_title' => $new_domain
        ]);
    }

    SkyHSHOSO_WHM_API::clear_stats_cache($hosting_id);

    wp_send_json_success(['message' => "Successfully mapped $new_domain to the site! The database has been migrated to match the new URL."]);
}