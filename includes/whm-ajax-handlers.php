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
 * PROVISION DOMAIN ONLY (APPLICATION CONTAINER ARCHITECTURE)
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

    // STRICT TENANT VIEW
    $current_user_id = get_current_user_id();
    $post_author_id = absint(get_post_field('post_author', $hosting_id));
    $invited_by = get_user_meta($current_user_id, 'skyhshoso_invited_by', true) ?: [];
    
    if ($current_user_id !== $post_author_id && !in_array($post_author_id, $invited_by)) {
        wp_send_json_error(['message' => 'Permission denied.']);
    }

    $server_id = get_post_meta($hosting_id, 'skyhshoso_server_id', true);
    $username  = get_post_meta($hosting_id, 'skyhshoso_hosting_username', true);
    $primary_domain = get_post_meta($hosting_id, 'skyhshoso_hosting_domain', true);

    // --- SMART SUBDOMAIN VALIDATION (Global Settings) ---
    $options = get_option('skyhshoso_settings_group', []);
    $base_domains_raw = $options['wp_base_domains'] ?? '';
    $base_domains = array_filter(preg_split('/[\s,]+/', $base_domains_raw));

    if (empty($base_domains)) {
        $base_domains = [str_replace(['www.', 'https://', 'http://'], '', $primary_domain)];
    }
    
    $whm_api_user  = get_post_meta($server_id, '_skyhshoso_whm_user_id', true);
    $whm_api_token = get_post_meta($server_id, '_skyhshoso_whm_token', true);
    $whm_api_host  = get_post_meta($server_id, '_skyhshoso_whm_host', true);
    
    if (empty($whm_api_token) || empty($whm_api_host)) wp_send_json_error(['message' => 'Server API credentials missing.']);

    if (!class_exists('SkyHSHOSO_WHM_API')) require_once dirname(__FILE__) . '/class-whm-integration.php';
    $whm_api = new SkyHSHOSO_WHM_API($whm_api_user, $whm_api_token, $whm_api_host);

    $clean_domain = strtolower(trim(str_replace(['http://', 'https://', 'www.'], '', $domain), '/'));
    $clean_primary = strtolower(trim(str_replace(['http://', 'https://', 'www.'], '', $primary_domain), '/'));

    // 1. Determine Document Root & Create Domain
    $doc_root = 'public_html'; // Primary domain stays in public_html
    
    if ($clean_domain !== $clean_primary) {
        $domain_parts = explode('.', $clean_domain);
        $app_name = preg_replace('/[^a-zA-Z0-9]/', '', $domain_parts[0]);
        if (empty($app_name)) $app_name = 'wp';
        
        $doc_root = 'sites/' . $app_name . '_' . rand(100, 999);

        $primary_tld_pattern = '/\.' . preg_quote($clean_primary, '/') . '$/i';
        
        if (preg_match($primary_tld_pattern, $clean_domain)) {
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
        
        // 2. AGGRESSIVE CLEANUP
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
    // ---------------------------------------------------------
    // PHASE 2.5: UNIVERSAL AUTO-INSTALLER ROUTER
    // ---------------------------------------------------------
    $wp_admin_user = 'admin_' . rand(1000, 9999);
    $wp_admin_pass = wp_generate_password(16, true, true);
    $wp_admin_email = 'hello@' . $clean_domain;

    $options = get_option('skyhshoso_settings_group', array());
    
    $installer_engine = isset($_POST['installer_engine']) && !empty($_POST['installer_engine']) 
        ? sanitize_text_field($_POST['installer_engine']) 
        : (isset($options['wp_default_installer_engine']) ? $options['wp_default_installer_engine'] : 'wptoolkit');
        
    $plugin_set_id = isset($_POST['plugin_set']) ? absint($_POST['plugin_set']) : 0;

    $whm_host_domain = parse_url($whm_api_host, PHP_URL_HOST) ?: $whm_api_host;
    $whm_host_domain = preg_replace('/:\d+$/', '', $whm_host_domain);

    sleep(2); // Breathing room

    if ($installer_engine === 'wptoolkit') {
        $wpt_api_url = "https://{$whm_host_domain}:2087/cgi/wpt/index.php/v1/installations";
        $wpt_payload = [
            'domain'           => $clean_domain,
            'installationPath' => '', 
            'title'            => 'New WordPress Site',
            'language'         => 'en_US',
            'protocol'         => 'https',
            'admin'            => [
                'login'    => $wp_admin_user,
                'password' => $wp_admin_pass,
                'email'    => $wp_admin_email
            ]
        ];
        if ($plugin_set_id > 0) $wpt_payload['set'] = $plugin_set_id;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $wpt_api_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($wpt_payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: whm root:' . $whm_api_token, 
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200 && $status !== 201 && $status !== 202 && class_exists('SkyHSHOSO_Logger')) {
            SkyHSHOSO_Logger::error('WP Toolkit Install Failed. Status: '.$status.'. Response: ' . $response);
        }

    } elseif ($installer_engine === 'installatron') {
        $installatron_api_url = "https://{$whm_host_domain}:2087/cgi/installatron/api.cgi?api=json";
        
        $installatron_payload = [
            'cmd'         => 'install',
            'user'        => $username, 
            'application' => 'wordpress',
            'url'         => 'http://' . $clean_domain . '/', 
            'login'       => $wp_admin_user,
            'passwd'      => $wp_admin_pass,
            'email'       => $wp_admin_email,
            'sitetitle'   => 'New WordPress Site',
            'background'  => 0 
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $installatron_api_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($installatron_payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: whm root:' . $whm_api_token
        ]);
        
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $installatron_data = json_decode($response, true);
        $is_success = isset($installatron_data['result']) && $installatron_data['result'];

        if (!$is_success && class_exists('SkyHSHOSO_Logger')) {
            $error_msg = isset($installatron_data['message']) ? $installatron_data['message'] : 'Unknown Error';
            SkyHSHOSO_Logger::error('Installatron Auto-Install Failed. Error: ' . $error_msg, ['source' => 'installatron']);
        }
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
    $success_msg .= '<strong style="font-size: 16px; color: #166534;">✓ Application Container Provisioned!</strong><br><br>';
    $success_msg .= 'Your WordPress site has been automatically installed via WP Toolkit and is completely isolated.<br><br>';
    $success_msg .= '<div style="background:#f8fafc; border:1px solid #e2e8f0; padding:12px; border-radius:6px;">';
    $success_msg .= '<strong>Your Temporary WP Admin Credentials:</strong><br>';
    $success_msg .= 'Username: <code>' . esc_html($wp_admin_user) . '</code><br>';
    $success_msg .= 'Password: <code>' . esc_html($wp_admin_pass) . '</code><br>';
    $success_msg .= '</div><br>';
    $success_msg .= '<em>Note: You don\'t need these credentials right now. You can securely log in using the blue <strong>WP Admin</strong> SSO button on your dashboard!</em>';
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
        wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'skyhs-hosting-solution' ) ) );
        wp_die();
    }

    $hosting_id = isset($_POST['hosting_id']) ? absint( $_POST['hosting_id'] ) : 0;
    $server_id = get_post_meta($hosting_id, 'skyhshoso_server_id', true);
    
    // STRICT TENANT VIEW
    $current_user_id = get_current_user_id();
    $post_author_id = absint( get_post_field('post_author', $hosting_id) );
    $invited_by = get_user_meta($current_user_id, 'skyhshoso_invited_by', true) ?: [];

    if ( $current_user_id !== $post_author_id && ! in_array( $post_author_id, $invited_by ) ) {
        wp_send_json_error( array( 'message' => esc_html__( 'Permission denied.', 'skyhs-hosting-solution' ) ) );
        wp_die();
    }

    $username = get_post_meta($hosting_id, 'skyhshoso_hosting_username', true);
    $whm_username = get_post_meta( $server_id, '_skyhshoso_whm_user_id', true );
    $whm_token = get_post_meta( $server_id, '_skyhshoso_whm_token', true );
    $whm_host = get_post_meta( $server_id, '_skyhshoso_whm_host', true );

    if (!class_exists('SkyHSHOSO_WHM_API')) require_once dirname(__FILE__) . '/class-whm-integration.php';
    $whm_api = new SkyHSHOSO_WHM_API($whm_username, $whm_token, $whm_host);

    $result = $whm_api->call('create_user_session', ['api.version' => 1, 'user' => $username, 'service' => 'cpaneld']);

    if ($result && isset($result['data']['url'])) {
        wp_send_json_success(['login_url' => $result['data']['url']]);
    } else {
        wp_send_json_error(['message' => "Failed to generate login URL."]);
    }
    wp_die();
}

function skyhshoso_get_cpanel_stats() { }
function skyhshoso_refresh_cpanel_stats() {
    $_POST['hosting_id'] = absint($_POST['hosting_id']);
    skyhshoso_get_cpanel_stats_callback();
}

function skyhshoso_get_cpanel_section_url() {
    $nonce = $_POST['nonce'] ?? '';
    if ( ! wp_verify_nonce( $nonce, 'skyhshoso_dashboard_nonce' ) ) wp_send_json_error( array( 'message' => 'Security check failed.' ) );

    $hosting_id = absint( $_POST['hosting_id'] );
    $section    = sanitize_text_field( $_POST['section'] );
    $insid      = sanitize_text_field( $_POST['insid'] ?? '' );

    // STRICT TENANT VIEW
    $current_user_id = get_current_user_id();
    $post_author_id  = absint( get_post_field( 'post_author', $hosting_id ) );
    $invited_by      = get_user_meta( $current_user_id, 'skyhshoso_invited_by', true ) ?: [];

    if ( $current_user_id !== $post_author_id && ! in_array( $post_author_id, $invited_by ) ) {
        wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        wp_die();
    }

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

    $server_id  = get_post_meta( $hosting_id, 'skyhshoso_server_id', true );
    $username   = get_post_meta( $hosting_id, 'skyhshoso_hosting_username', true );
    $whm_user   = get_post_meta( $server_id, '_skyhshoso_whm_user_id', true );
    $whm_token  = get_post_meta( $server_id, '_skyhshoso_whm_token', true );
    $whm_host   = get_post_meta( $server_id, '_skyhshoso_whm_host', true );

    if (!class_exists('SkyHSHOSO_WHM_API')) require_once dirname(__FILE__) . '/class-whm-integration.php';
    $whm_api = new SkyHSHOSO_WHM_API($whm_user, $whm_token, $whm_host);

    $result = $whm_api->call('create_user_session', ['api.version' => 1, 'user' => $username, 'service' => 'cpaneld']);

    if ($result && isset($result['data']['url'])) {
        wp_send_json_success( array( 'url' => add_query_arg( 'goto_uri', $path, $result['data']['url'] ) ) );
    } else {
        wp_send_json_error( array( 'message' => 'Failed to generate session.' ) );
    }
    wp_die();
}

function skyhshoso_toggle_ssh() { }
function skyhshoso_reset_password() { }

/**
 * =========================================================================
 * ASYNC WORDPRESS DISCOVERY ENGINE 
 * =========================================================================
 */
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
    
    $html .= '<button class="skyhshoso-button skyhshoso-cpanel-login-btn" data-hosting-id="' . $hosting_id . '" data-nonce="' . wp_create_nonce('skyhshoso_dashboard_nonce') . '" style="background:#1f2937;color:#fff;border:none;padding:6px 12px;font-size:13px;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;gap:4px;font-weight:500;">cPanel</button>';
    
    $html .= '</div></td></tr>';

    return $html;
}

add_action('wp_ajax_skyhshoso_get_wp_site_page', 'skyhshoso_get_wp_site_page');
function skyhshoso_get_wp_site_page() {
    $nonce = $_POST['nonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'skyhshoso_dashboard_nonce') && !wp_verify_nonce($nonce, 'skyhshoso_wp_nonce')) {
        wp_send_json_error(['message' => 'Security check failed.']);
    }
    
    $current_user_id = get_current_user_id();
    $html = '';
    
    $wp_args = ['post_type' => 'skyhshoso_wp_site', 'posts_per_page' => -1, 'post_status' => 'publish'];
    
    // STRICT TENANT VIEW
    $invited_by = get_user_meta($current_user_id, 'skyhshoso_invited_by', true) ?: [];
    if (!empty($invited_by)) {
        $wp_args['author__in'] = array_merge([$current_user_id], $invited_by);
    } else {
        $wp_args['author'] = $current_user_id;
    }
    $wp_posts = get_posts($wp_args);

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

    $hosting_queue = [];
    $h_args = ['post_type' => 'skyhshoso_hosting', 'posts_per_page' => -1, 'post_status' => 'publish'];
    if (!empty($invited_by)) {
        $h_args['author__in'] = array_merge([$current_user_id], $invited_by);
    } else {
        $h_args['author'] = $current_user_id;
    }
    
    $hosting_posts = get_posts($h_args);
    foreach ($hosting_posts as $h) { $hosting_queue[] = $h->ID; }

    wp_send_json_success(['html' => $html, 'hosting_queue' => $hosting_queue, 'current_page' => 1, 'total_pages' => 1]);
}

add_action('wp_ajax_skyhshoso_scan_wp_sites', 'skyhshoso_scan_wp_sites');
function skyhshoso_scan_wp_sites() {
    $nonce = $_POST['nonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'skyhshoso_dashboard_nonce') && !wp_verify_nonce($nonce, 'skyhshoso_wp_nonce')) wp_send_json_error(['message' => 'Security check failed.']);
    
    $hosting_id = absint($_POST['hosting_id']);
    
    // STRICT TENANT VIEW
    $current_user_id = get_current_user_id();
    $post_author_id = absint(get_post_field('post_author', $hosting_id));
    $invited_by = get_user_meta($current_user_id, 'skyhshoso_invited_by', true) ?: [];
    if ($current_user_id !== $post_author_id && !in_array($post_author_id, $invited_by)) {
        wp_send_json_error(['message' => 'Permission denied.']);
    }

    $wp_args = ['post_type' => 'skyhshoso_wp_site', 'posts_per_page' => -1, 'post_status' => 'publish', 'meta_query' => [['key' => 'skyhshoso_server_id', 'value' => $hosting_id, 'compare' => '=']]];
    if (!empty($invited_by)) {
        $wp_args['author__in'] = array_merge([$current_user_id], $invited_by);
    } else {
        $wp_args['author'] = $current_user_id;
    }

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

add_action('wp_ajax_skyhshoso_get_scan_targets', 'skyhshoso_ajax_get_scan_targets');
function skyhshoso_ajax_get_scan_targets() {
    $nonce = $_POST['nonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'skyhshoso_dashboard_nonce') && !wp_verify_nonce($nonce, 'skyhshoso_wp_nonce')) wp_send_json_error(['message' => 'Security check failed.']);

    $hosting_id = absint($_POST['hosting_id']);
    
    // STRICT TENANT VIEW
    $current_user_id = get_current_user_id();
    $post_author_id = absint(get_post_field('post_author', $hosting_id));
    $invited_by = get_user_meta($current_user_id, 'skyhshoso_invited_by', true) ?: [];
    if ($current_user_id !== $post_author_id && !in_array($post_author_id, $invited_by)) {
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
    $queued_doc_roots = []; 

    foreach ($domains_to_check as $d) {
        $domain = trim($d['domain']);
        if (empty($domain)) continue;

        $doc_root_base = preg_replace('#^/?home/' . preg_quote($username, '#') . '/#', '', $d['doc_root']);
        $doc_root_base = trim($doc_root_base, '/');
        if (empty($doc_root_base)) $doc_root_base = 'public_html';

        $dynamic_subs = ['']; 
        $dir_list = $whm_api->cpanel_uapi_call_v3_raw($username, 'Fileman', 'list_files', [
            'dir' => $doc_root_base,
            'types' => 'dir'
        ]);

        if (isset($dir_list['result']['data']) && is_array($dir_list['result']['data'])) {
            foreach ($dir_list['result']['data'] as $item) {
                if (isset($item['type']) && $item['type'] === 'dir') {
                    $dirname = trim($item['file']);
                    if (strpos($dirname, '.') === 0) continue;
                    if (in_array(strtolower($dirname), ['cgi-bin', 'css', 'js', 'images', 'wp-admin', 'wp-includes', 'wp-content'])) continue;
                    $dynamic_subs[] = '/' . $dirname;
                }
            }
        }

        $dynamic_subs = array_unique($dynamic_subs);

        foreach ($dynamic_subs as $sub) {
            $target_root = trim($doc_root_base . $sub, '/'); 
            
            if (isset($queued_doc_roots[$target_root])) continue;
            $queued_doc_roots[$target_root] = true;

            $targets[] = ['domain' => $domain, 'url' => $domain . $sub, 'doc_root' => $target_root];
        }
    }

    wp_send_json_success(['targets' => $targets, 'username' => $username, 'hosting_id' => $hosting_id]);
}

add_action('wp_ajax_skyhshoso_check_wp_target', 'skyhshoso_ajax_check_wp_target');
function skyhshoso_ajax_check_wp_target() {
    $nonce = $_POST['nonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'skyhshoso_dashboard_nonce') && !wp_verify_nonce($nonce, 'skyhshoso_wp_nonce')) wp_send_json_error(['message' => 'Security check failed.']);
    
    $hosting_id = absint($_POST['hosting_id']);
    $doc_root = sanitize_text_field($_POST['doc_root']);
    $url = sanitize_text_field($_POST['url']);
    $username = sanitize_text_field($_POST['username']);

    // STRICT TENANT VIEW
    $current_user_id = get_current_user_id();
    $post_author_id = absint(get_post_field('post_author', $hosting_id));
    $invited_by = get_user_meta($current_user_id, 'skyhshoso_invited_by', true) ?: [];
    if ($current_user_id !== $post_author_id && !in_array($post_author_id, $invited_by)) {
        wp_send_json_error(['message' => 'Permission denied.']);
    }

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
 * UNIVERSAL SSO INJECTOR
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

    // STRICT TENANT VIEW
    $post_author_id = absint(get_post_field('post_author', $hosting_id));
    $invited_by = get_user_meta($current_user_id, 'skyhshoso_invited_by', true) ?: [];

    if ($current_user_id !== $post_author_id && !in_array($post_author_id, $invited_by)) {
        wp_send_json_error(['message' => 'Permission denied.']);
    }

    $server_id  = get_post_meta($hosting_id, 'skyhshoso_server_id', true);
    $username   = get_post_meta($hosting_id, 'skyhshoso_hosting_username', true);
    $admin_user = get_post_meta($hosting_id, 'skyhshoso_wp_admin_user', true) ?: 'admin';

    $whm_api_user  = get_post_meta($server_id, '_skyhshoso_whm_user_id', true);
    $whm_api_token = get_post_meta($server_id, '_skyhshoso_whm_token', true);
    $whm_api_host  = get_post_meta($server_id, '_skyhshoso_whm_host', true);

    if (!class_exists('SkyHSHOSO_WHM_API')) require_once dirname(__FILE__) . '/class-whm-integration.php';
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
    
    $new_domain = preg_replace('#^https?://#i', '', $new_domain);
    $new_domain = preg_replace('#^www\.#i', '', $new_domain);
    $new_domain = rtrim($new_domain, '/');
    $new_url_https = "https://" . $new_domain;

    $old_url_clean = rtrim($old_url, '/');
    $old_domain = preg_replace('#^https?://#i', '', $old_url_clean);
    $old_domain = preg_replace('#^www\.#i', '', $old_domain);

    // STRICT TENANT VIEW
    $current_user_id = get_current_user_id();
    $post_author_id = absint(get_post_field('post_author', $hosting_id));
    $invited_by = get_user_meta($current_user_id, 'skyhshoso_invited_by', true) ?: [];

    if ($current_user_id !== $post_author_id && !in_array($post_author_id, $invited_by)) {
        wp_send_json_error(['message' => 'Permission denied.']);
    }

    $server_id = get_post_meta($hosting_id, 'skyhshoso_server_id', true);
    $username  = get_post_meta($hosting_id, 'skyhshoso_hosting_username', true);
    $current_primary = get_post_meta($hosting_id, 'skyhshoso_hosting_domain', true);
    $current_primary_clean = preg_replace('#^www\.#i', '', $current_primary);
    
    $whm_api_user  = get_post_meta($server_id, '_skyhshoso_whm_user_id', true);
    $whm_api_token = get_post_meta($server_id, '_skyhshoso_whm_token', true);
    $whm_api_host  = get_post_meta($server_id, '_skyhshoso_whm_host', true);
    
    if (!class_exists('SkyHSHOSO_WHM_API')) require_once dirname(__FILE__) . '/class-whm-integration.php';
    $whm_api = new SkyHSHOSO_WHM_API($whm_api_user, $whm_api_token, $whm_api_host);

    $relative_dir = preg_replace('#^/?home/' . preg_quote($username, '#') . '/#', '', $doc_root);
    if (empty($relative_dir)) $relative_dir = 'public_html';

    $file_check = $whm_api->cpanel_uapi_call_v3_raw($username, 'Fileman', 'get_file_information', [
        'path' => trim($relative_dir, '/') . '/wp-config.php'
    ]);

    if (!isset($file_check['result']['status']) || $file_check['result']['status'] != 1) {
        wp_send_json_error(['message' => 'WordPress is not fully provisioned yet! Please click the "cPanel" button and finish installing WordPress before changing the domain.']);
    }

    $is_primary = ($relative_dir === 'public_html' && $old_domain === $current_primary_clean);

    if ($is_primary) {
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

        $create_res = $whm_api->call('cpanel', [
            'cpanel_jsonapi_user'       => $username,
            'cpanel_jsonapi_apiversion' => '2',
            'cpanel_jsonapi_module'     => 'AddonDomain',
            'cpanel_jsonapi_func'       => 'addaddondomain',
            'newdomain'                 => $new_domain,
            'dir'                       => $relative_dir,
            'subdomain'                 => $safe_subdomain_alias
        ]);

        $success = false;
        if (isset($create_res['cpanelresult']['error'])) {
            $reason = $create_res['cpanelresult']['error'];
            if (strpos(strtolower($reason), 'exists') !== false || strpos(strtolower($reason), 'configured') !== false) {
                $success = true;
            } else {
                 wp_send_json_error(['message' => 'cPanel could not create domain: ' . $reason]);
            }
        } elseif (isset($create_res['cpanelresult']['data'][0]['result'])) {
             if ($create_res['cpanelresult']['data'][0]['result'] == 1) {
                 $success = true;
             } else {
                 $reason = $create_res['cpanelresult']['data'][0]['reason'];
                 if (strpos(strtolower($reason), 'exists') !== false || strpos(strtolower($reason), 'configured') !== false) {
                     $success = true;
                 } else {
                     wp_send_json_error(['message' => 'cPanel could not create domain: ' . $reason]);
                 }
             }
        }

        if ($success && !empty($old_domain) && $old_domain !== $new_domain) {
            $whm_api->cpanel_uapi_call_v3_raw($username, 'AddonDomain', 'deladdondomain', ['domain' => $old_domain]);
            $whm_api->cpanel_uapi_call_v3_raw($username, 'SubDomain', 'delsubdomain', ['domain' => $old_domain]);
        }
    }

    $whm_api->cpanel_uapi_call_v3_raw($username, 'SSL', 'start_autossl_check', []);

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

    $whm_api->cpanel_uapi_call_v3_raw($username, 'Fileman', 'save_file_content', [
        'dir' => trim($relative_dir, '/'),
        'file' => $filename,
        'content' => $php_code
    ]);

    $whm_host_domain = parse_url($whm_api_host, PHP_URL_HOST) ?: $whm_api_host;
    $whm_host_domain = preg_replace('/:\d+$/', '', $whm_host_domain);
    $server_ip = gethostbyname($whm_host_domain);
    
    wp_remote_get("http://{$server_ip}/{$filename}", [
        'timeout' => 45, 
        'sslverify' => false,
        'headers' => ['Host' => $new_domain]
    ]);

    $whm_api->cpanel_uapi_call_v3_raw($username, 'Fileman', 'file_op', [
        'op' => 'unlink',
        'sourcefiles' => trim($relative_dir, '/') . '/' . $filename
    ]);

    $existing_sites = get_posts([
        'post_type'  => 'skyhshoso_wp_site',
        'meta_query' => [['key' => '_skyhshoso_wp_site_url', 'value' => $old_url_clean, 'compare' => 'LIKE']],
        'post_status' => 'publish'
    ]);

    if (!empty($existing_sites)) {
        update_post_meta($existing_sites[0]->ID, '_skyhshoso_wp_site_url', $new_url_https);
        wp_update_post(['ID' => $existing_sites[0]->ID, 'post_title' => $new_domain]);
    }

    SkyHSHOSO_WHM_API::clear_stats_cache($hosting_id);
    wp_send_json_success(['message' => "Successfully mapped $new_domain to the site! The database has been migrated to match the new URL."]);
}


/**
 * =========================================================================
 * CPANEL DASHBOARD FUNCTIONALITY (STATS, SSH, PASSWORD)
 * =========================================================================
 */
add_action('wp_ajax_skyhshoso_get_cpanel_stats_callback', 'skyhshoso_get_cpanel_stats_callback');
function skyhshoso_get_cpanel_stats_callback() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_key($_POST['nonce']), 'skyhshoso_dashboard_nonce')) {
        wp_send_json_error(['message' => 'Invalid security token.']);
    }
    
    $hosting_id = intval($_POST['hosting_id']);
    
    // STRICT TENANT VIEW
    $current_user_id = get_current_user_id();
    $post_author_id = absint(get_post_field('post_author', $hosting_id));
    $invited_by = get_user_meta($current_user_id, 'skyhshoso_invited_by', true) ?: [];
    
    if ($current_user_id !== $post_author_id && !in_array($post_author_id, $invited_by)) {
        wp_send_json_error(['message' => 'Permission denied.']);
    }

    $server_id  = get_post_meta($hosting_id, 'skyhshoso_server_id', true);
    $username   = get_post_meta($hosting_id, 'skyhshoso_hosting_username', true);

    if (!$server_id || !$username) wp_send_json_error(['message' => 'Server not provisioned yet.']);

    $whm_api_token = get_post_meta($server_id, '_skyhshoso_whm_token', true);
    $whm_api_host  = get_post_meta($server_id, '_skyhshoso_whm_host', true);

    if (empty($whm_api_token) || empty($whm_api_host)) wp_send_json_error(['message' => 'Server API credentials missing.']);

    $whm_host_domain = parse_url($whm_api_host, PHP_URL_HOST) ?: $whm_api_host;
    $whm_host_domain = preg_replace('/:\d+$/', '', $whm_host_domain);
    $auth_header = 'Authorization: whm root:' . $whm_api_token;

    $uapi_url = "https://{$whm_host_domain}:2087/json-api/cpanel?api.version=1&cpanel.module=StatsBar&cpanel.function=get_stat_items&cpanel.user={$username}&display=diskusage%7Csqldiskusage%7Cmysqldbs%7Csubdomains%7Caddondomains";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $uapi_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [$auth_header]);
    $uapi_res_raw = curl_exec($ch);
    curl_close($ch);
    $uapi_res = json_decode($uapi_res_raw, true);

    $summary_url = "https://{$whm_host_domain}:2087/json-api/accountsummary?api.version=1&user={$username}";
    $ch2 = curl_init();
    curl_setopt($ch2, CURLOPT_URL, $summary_url);
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch2, CURLOPT_HTTPHEADER, [$auth_header]);
    $summary_raw = curl_exec($ch2);
    curl_close($ch2);
    $summary = json_decode($summary_raw, true);
    
    $ssh_active = false;
    if (isset($summary['data']['acct'][0]['shell'])) {
        $shell = $summary['data']['acct'][0]['shell'];
        $ssh_active = (strpos($shell, 'noshell') === false && strpos($shell, 'nologin') === false);
    }

    $stats = array();
    if (isset($uapi_res['data']['result']['data']) && is_array($uapi_res['data']['result']['data'])) {
        foreach ($uapi_res['data']['result']['data'] as $item) {
            $stats[$item['id']] = $item;
        }
    }

    wp_send_json_success(array('stats' => $stats, 'ssh_active' => $ssh_active));
}

add_action('wp_ajax_skyhshoso_toggle_ssh_callback', 'skyhshoso_toggle_ssh_callback');
function skyhshoso_toggle_ssh_callback() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_key($_POST['nonce']), 'skyhshoso_dashboard_nonce')) {
        wp_send_json_error(['message' => 'Invalid security token.']);
    }
    
    $hosting_id = intval($_POST['hosting_id']);
    
    // STRICT TENANT VIEW
    $current_user_id = get_current_user_id();
    $post_author_id = absint(get_post_field('post_author', $hosting_id));
    $invited_by = get_user_meta($current_user_id, 'skyhshoso_invited_by', true) ?: [];
    
    if ($current_user_id !== $post_author_id && !in_array($post_author_id, $invited_by)) {
        wp_send_json_error(['message' => 'Permission denied.']);
    }

    $enable_ssh = filter_var($_POST['enable_ssh'], FILTER_VALIDATE_BOOLEAN);

    $server_id  = get_post_meta($hosting_id, 'skyhshoso_server_id', true);
    $username   = get_post_meta($hosting_id, 'skyhshoso_hosting_username', true);
    $whm_api_token = get_post_meta($server_id, '_skyhshoso_whm_token', true);
    $whm_api_host  = get_post_meta($server_id, '_skyhshoso_whm_host', true);

    $whm_host_domain = parse_url($whm_api_host, PHP_URL_HOST) ?: $whm_api_host;
    $whm_host_domain = preg_replace('/:\d+$/', '', $whm_host_domain);
    $auth_header = 'Authorization: whm root:' . $whm_api_token;
    
    $shell = $enable_ssh ? '/bin/bash' : '/usr/local/cpanel/bin/noshell';
    
    $modify_url = "https://{$whm_host_domain}:2087/json-api/modifyacct?api.version=1&user={$username}&shell=" . urlencode($shell);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $modify_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [$auth_header]);
    $res_raw = curl_exec($ch);
    curl_close($ch);
    $res = json_decode($res_raw, true);

    if (isset($res['metadata']['result']) && $res['metadata']['result'] == 1) {
        wp_send_json_success(['message' => 'SSH access updated.']);
    } else {
        $err = isset($res['metadata']['reason']) ? $res['metadata']['reason'] : 'Unknown error';
        wp_send_json_error(['message' => 'Failed to update SSH access: ' . $err]);
    }
}

add_action('wp_ajax_skyhshoso_reset_cpanel_pass_callback', 'skyhshoso_reset_cpanel_pass_callback');
function skyhshoso_reset_cpanel_pass_callback() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_key($_POST['nonce']), 'skyhshoso_dashboard_nonce')) {
        wp_send_json_error(['message' => 'Invalid security token.']);
    }
    
    $hosting_id = intval($_POST['hosting_id']);
    
    // STRICT TENANT VIEW
    $current_user_id = get_current_user_id();
    $post_author_id = absint(get_post_field('post_author', $hosting_id));
    $invited_by = get_user_meta($current_user_id, 'skyhshoso_invited_by', true) ?: [];
    
    if ($current_user_id !== $post_author_id && !in_array($post_author_id, $invited_by)) {
        wp_send_json_error(['message' => 'Permission denied.']);
    }

    $new_pass   = sanitize_text_field($_POST['new_pass']);

    if (strlen($new_pass) < 8) {
        wp_send_json_error(['message' => 'Password must be at least 8 characters.']);
    }

    $server_id  = get_post_meta($hosting_id, 'skyhshoso_server_id', true);
    $username   = get_post_meta($hosting_id, 'skyhshoso_hosting_username', true);
    $whm_api_token = get_post_meta($server_id, '_skyhshoso_whm_token', true);
    $whm_api_host  = get_post_meta($server_id, '_skyhshoso_whm_host', true);

    $whm_host_domain = parse_url($whm_api_host, PHP_URL_HOST) ?: $whm_api_host;
    $whm_host_domain = preg_replace('/:\d+$/', '', $whm_host_domain);
    $auth_header = 'Authorization: whm root:' . $whm_api_token;

    $pass_encoded = urlencode($new_pass);
    $passwd_url = "https://{$whm_host_domain}:2087/json-api/passwd?api.version=1&user={$username}&password={$pass_encoded}";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $passwd_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [$auth_header]);
    $res_raw = curl_exec($ch);
    curl_close($ch);
    $res = json_decode($res_raw, true);

    if (isset($res['metadata']['result']) && $res['metadata']['result'] == 1) {
        update_post_meta($hosting_id, 'skyhshoso_hosting_password', $new_pass);
        wp_send_json_success(['message' => 'Password reset successfully.']);
    } else {
        $err = isset($res['metadata']['reason']) ? $res['metadata']['reason'] : 'Unknown error';
        wp_send_json_error(['message' => 'Failed to reset password: ' . $err]);
    }
}


/**
 * =========================================================================
 * TWO-WAY WHM ACCOUNT MANAGEMENT (ADMIN PANEL SYNC, SUSPEND, TERMINATE)
 * =========================================================================
 */
add_action('wp_ajax_skyhshoso_sync_account', 'skyhshoso_sync_account_callback');
function skyhshoso_sync_account_callback() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied.']);
    }

    $hosting_id = absint($_POST['hosting_id']);
    $server_id = get_post_meta($hosting_id, 'skyhshoso_server_id', true);
    $username  = get_post_meta($hosting_id, 'skyhshoso_hosting_username', true);

    $whm_api_token = get_post_meta($server_id, '_skyhshoso_whm_token', true);
    $whm_api_host  = get_post_meta($server_id, '_skyhshoso_whm_host', true);
    $whm_host_domain = preg_replace('/:\d+$/', '', parse_url($whm_api_host, PHP_URL_HOST) ?: $whm_api_host);

    $url = "https://{$whm_host_domain}:2087/json-api/accountsummary?api.version=1&user={$username}";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Bypass self-signed SSL blocks
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: whm root:' . $whm_api_token]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (isset($res['metadata']['result']) && $res['metadata']['result'] == 1) {
        $acct = $res['data']['acct'][0];
        
        $status = $acct['suspended'] ? 'suspended' : 'active';
        update_post_meta($hosting_id, 'skyhshoso_account_status', $status);
        
        SkyHSHOSO_WHM_API::clear_stats_cache($hosting_id); 
        wp_send_json_success(['message' => 'Account successfully synced with WHM.', 'status' => $status]);
    } else {
        wp_send_json_error(['message' => 'Could not locate account on server. It may have been deleted.']);
    }
}

add_action('wp_ajax_skyhshoso_toggle_suspend', 'skyhshoso_toggle_suspend_callback');
function skyhshoso_toggle_suspend_callback() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied. Only administrators can suspend accounts.']);
    }
    
    $hosting_id = absint($_POST['hosting_id']);
    $action = sanitize_text_field($_POST['status_action']); 
    $api_action = $action === 'suspend' ? 'suspendacct' : 'unsuspendacct';

    $server_id = get_post_meta($hosting_id, 'skyhshoso_server_id', true);
    $username  = get_post_meta($hosting_id, 'skyhshoso_hosting_username', true);
    $whm_api_token = get_post_meta($server_id, '_skyhshoso_whm_token', true);
    $whm_api_host  = get_post_meta($server_id, '_skyhshoso_whm_host', true);
    $whm_host_domain = preg_replace('/:\d+$/', '', parse_url($whm_api_host, PHP_URL_HOST) ?: $whm_api_host);

    $url = "https://{$whm_host_domain}:2087/json-api/{$api_action}?api.version=1&user={$username}&reason=" . urlencode('Suspended by admin via WP Admin Dashboard.');
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: whm root:' . $whm_api_token]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (isset($res['metadata']['result']) && $res['metadata']['result'] == 1) {
        $new_status = $action === 'suspend' ? 'suspended' : 'active';
        update_post_meta($hosting_id, 'skyhshoso_account_status', $new_status);
        wp_send_json_success(['message' => 'Account status updated successfully.', 'new_status' => $new_status]);
    } else {
        wp_send_json_error(['message' => 'WHM Error: ' . ($res['metadata']['reason'] ?? 'Unknown API error')]);
    }
}

add_action('wp_ajax_skyhshoso_terminate_account', 'skyhshoso_terminate_account_callback');
function skyhshoso_terminate_account_callback() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied. Only administrators can terminate accounts.']);
    }
    
    $hosting_id = absint($_POST['hosting_id']);
    
    $server_id = get_post_meta($hosting_id, 'skyhshoso_server_id', true);
    $username  = get_post_meta($hosting_id, 'skyhshoso_hosting_username', true);
    $whm_api_token = get_post_meta($server_id, '_skyhshoso_whm_token', true);
    $whm_api_host  = get_post_meta($server_id, '_skyhshoso_whm_host', true);
    $whm_host_domain = preg_replace('/:\d+$/', '', parse_url($whm_api_host, PHP_URL_HOST) ?: $whm_api_host);

    $url = "https://{$whm_host_domain}:2087/json-api/removeacct?api.version=1&user={$username}";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: whm root:' . $whm_api_token]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    
    if (isset($res['metadata']['result']) && $res['metadata']['result'] == 1) {
        $sub_id = get_post_meta($hosting_id, 'skyhshoso_subscription_id', true);
        if ($sub_id && function_exists('wcs_get_subscription')) {
            $subscription = wcs_get_subscription($sub_id);
            if ($subscription && $subscription->can_be_updated_to('cancelled')) {
                $subscription->update_status('cancelled', 'Account permanently terminated by Admin.');
            }
        }
        wp_trash_post($hosting_id);
        wp_send_json_success(['message' => 'Account securely terminated and billing cancelled.']);
    } else {
        wp_send_json_error(['message' => 'WHM Error: ' . ($res['metadata']['reason'] ?? 'Could not terminate server account.')]);
    }
}

add_action('wp_ajax_skyhshoso_sync_wpt_sets', 'skyhshoso_sync_wpt_sets_callback');
function skyhshoso_sync_wpt_sets_callback() {
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Permission denied.']);

    $servers = get_posts(['post_type' => 'skyhshoso_server', 'posts_per_page' => 1, 'post_status' => 'publish']);
    if (empty($servers)) wp_send_json_error(['message' => 'No active servers found to query.']);

    $server_id = $servers[0]->ID;
    $whm_api_token = get_post_meta($server_id, '_skyhshoso_whm_token', true);
    $whm_api_host  = get_post_meta($server_id, '_skyhshoso_whm_host', true);

    if (empty($whm_api_token) || empty($whm_api_host)) wp_send_json_error(['message' => 'Server API credentials missing.']);

    $whm_host_domain = parse_url($whm_api_host, PHP_URL_HOST) ?: $whm_api_host;
    $whm_host_domain = preg_replace('/:\d+$/', '', $whm_host_domain);

    $wpt_api_url = "https://{$whm_host_domain}:2087/cgi/wpt/index.php/v1/sets";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $wpt_api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: whm root:' . $whm_api_token,
        'Accept: application/json'
    ]);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status === 200) {
        $data = json_decode($response, true);
        wp_send_json_success(['sets' => $data]);
    } else {
        wp_send_json_error(['message' => 'WHM API Error (' . $status . '): ' . $response]);
    }
}

/**
 * =========================================================================
 * SAFE FRONTEND MANAGEMENT ACTIONS (SYNC, SUSPEND, DELETE)
 * =========================================================================
 */
add_action('wp_ajax_skyhshoso_frontend_sync', 'skyhshoso_frontend_sync_callback');
function skyhshoso_frontend_sync_callback() {
    $hosting_id = intval($_POST['hosting_id']);
    
    // STRICT TENANT VIEW
    $current_user_id = get_current_user_id();
    $post_author_id = absint(get_post_field('post_author', $hosting_id));
    $invited_by = get_user_meta($current_user_id, 'skyhshoso_invited_by', true) ?: [];
    
    if ($current_user_id !== $post_author_id && !in_array($post_author_id, $invited_by)) {
        wp_send_json_error(['message' => 'Permission denied.']);
    }
    
    $server_id  = get_post_meta($hosting_id, 'skyhshoso_server_id', true);
    $username   = get_post_meta($hosting_id, 'skyhshoso_hosting_username', true);

    if (!$server_id || !$username) wp_send_json_error(['message' => 'No cPanel username found. The automated creation likely failed.']);

    $whm_api_token = get_post_meta($server_id, '_skyhshoso_whm_token', true);
    $whm_api_host  = get_post_meta($server_id, '_skyhshoso_whm_host', true);
    $whm_host_domain = preg_replace('/:\d+$/', '', parse_url($whm_api_host, PHP_URL_HOST) ?: $whm_api_host);

    $url = "https://{$whm_host_domain}:2087/json-api/accountsummary?api.version=1&user={$username}";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: whm root:' . $whm_api_token]);
    $res_raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $res = json_decode($res_raw, true);

    if ($status !== 200 || !isset($res['metadata']['result']) || $res['metadata']['result'] != 1) {
        $reason = isset($res['metadata']['reason']) ? $res['metadata']['reason'] : 'Unknown WHM Error';
        wp_send_json_error(['message' => "WHM Error: {$reason}. (If it says the account doesn't exist, check your WooCommerce logs—the initial provision failed)."]);
    }

    $suspended = isset($res['data']['acct'][0]['suspended']) && $res['data']['acct'][0]['suspended'] == 1;
    update_post_meta($hosting_id, 'skyhshoso_account_status', $suspended ? 'suspended' : 'active');
    
    wp_send_json_success(['message' => 'Account status synced perfectly from WHM!']);
}

add_action('wp_ajax_skyhshoso_frontend_toggle_status', 'skyhshoso_frontend_toggle_status_callback');
function skyhshoso_frontend_toggle_status_callback() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'You do not have permission to suspend accounts.']);
    }

    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_key($_POST['nonce']), 'skyhshoso_dashboard_nonce')) {
        wp_send_json_error(['message' => 'Invalid security token.']);
    }
    
    $hosting_id = intval($_POST['hosting_id']);
    $action = $_POST['account_action'] === 'suspend' ? 'suspendacct' : 'unsuspendacct';
    
    $server_id  = get_post_meta($hosting_id, 'skyhshoso_server_id', true);
    $username   = get_post_meta($hosting_id, 'skyhshoso_hosting_username', true);
    $whm_api_token = get_post_meta($server_id, '_skyhshoso_whm_token', true);
    $whm_api_host  = get_post_meta($server_id, '_skyhshoso_whm_host', true);
    $whm_host_domain = preg_replace('/:\d+$/', '', parse_url($whm_api_host, PHP_URL_HOST) ?: $whm_api_host);

    $url = "https://{$whm_host_domain}:2087/json-api/{$action}?api.version=1&user={$username}&reason=" . urlencode('Changed via Dashboard');
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: whm root:' . $whm_api_token]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (isset($res['metadata']['result']) && $res['metadata']['result'] == 1) {
        update_post_meta($hosting_id, 'skyhshoso_account_status', $action === 'suspendacct' ? 'suspended' : 'active');
        wp_send_json_success(['message' => 'Server status updated!']);
    } else {
        wp_send_json_error(['message' => 'Failed to toggle status: ' . (isset($res['metadata']['reason']) ? $res['metadata']['reason'] : 'Unknown error')]);
    }
}

add_action('wp_ajax_skyhshoso_frontend_terminate', 'skyhshoso_frontend_terminate_callback');
function skyhshoso_frontend_terminate_callback() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'You do not have permission to terminate accounts.']);
    }

    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_key($_POST['nonce']), 'skyhshoso_dashboard_nonce')) {
        wp_send_json_error(['message' => 'Invalid security token.']);
    }

    $hosting_id = intval($_POST['hosting_id']);
    
    $server_id  = get_post_meta($hosting_id, 'skyhshoso_server_id', true);
    $username   = get_post_meta($hosting_id, 'skyhshoso_hosting_username', true);
    $whm_api_token = get_post_meta($server_id, '_skyhshoso_whm_token', true);
    $whm_api_host  = get_post_meta($server_id, '_skyhshoso_whm_host', true);
    $whm_host_domain = preg_replace('/:\d+$/', '', parse_url($whm_api_host, PHP_URL_HOST) ?: $whm_api_host);

    $url = "https://{$whm_host_domain}:2087/json-api/removeacct?api.version=1&user={$username}";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: whm root:' . $whm_api_token]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (isset($res['metadata']['result']) && $res['metadata']['result'] == 1) {
        wp_delete_post($hosting_id, true); // Delete the local WordPress record
        wp_send_json_success(['message' => 'Server permanently destroyed.']);
    } else {
        wp_send_json_error(['message' => 'Failed to terminate: ' . (isset($res['metadata']['reason']) ? $res['metadata']['reason'] : 'Unknown error')]);
    }
}

/**
 * =========================================================================
 * ORPHAN CLEANUP: DELETE WP SITES WHEN HOSTING IS DELETED
 * =========================================================================
 */
add_action('before_delete_post', 'skyhshoso_cleanup_wp_sites_on_hosting_delete');
function skyhshoso_cleanup_wp_sites_on_hosting_delete($post_id) {
    if (get_post_type($post_id) === 'skyhshoso_hosting') {
        $username = get_post_meta($post_id, 'skyhshoso_hosting_username', true);
        if (!empty($username)) {
            $wp_sites = get_posts(array(
                'post_type'      => 'skyhshoso_wp_site',
                'posts_per_page' => -1,
                'meta_query'     => array(
                    array(
                        'key'     => 'skyhshoso_wp_cpanel_user',
                        'value'   => $username,
                        'compare' => '='
                    )
                )
            ));
            
            foreach ($wp_sites as $site) {
                wp_delete_post($site->ID, true);
            }
        }
    }
}