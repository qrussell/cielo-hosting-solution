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

    $current_user_id = get_current_user_id();
    $post_author_id = absint(get_post_field('post_author', $hosting_id));
    if ($current_user_id !== $post_author_id && !current_user_can('administrator')) {
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
        $is_admin = current_user_can('administrator') || current_user_can('manage_options');
        if ($is_admin) {
            wp_send_json_error(['message' => 'Admin Action Required: Please configure the WP Base Domains in the Plugin General Settings.']);
        } else {
            wp_send_json_error(['message' => 'System configuration is finalizing. Nothing is wrong with your account, but we cannot create the site just yet. Please try again shortly!']);
        }
    }
    // ----------------------------------------------------
    
    $whm_api_user  = get_post_meta($server_id, '_skyhshoso_whm_user_id', true);
    $whm_api_token = get_post_meta($server_id, '_skyhshoso_whm_token', true);
    $whm_api_host  = get_post_meta($server_id, '_skyhshoso_whm_host', true);
    
    if (!class_exists('SkyHSHOSO_WHM_API')) require_once dirname(__FILE__) . '/class-whm-integration.php';
    $whm_api = new SkyHSHOSO_WHM_API($whm_api_user, $whm_api_token, $whm_api_host);

    $clean_domain = str_replace(['www.', 'https://', 'http://'], '', rtrim($domain, '/'));
    $clean_primary = str_replace(['www.', 'https://', 'http://'], '', rtrim($primary_domain, '/'));

    // 1. Determine Document Root & Create Domain
    $doc_root = 'public_html'; // Primary domain stays in public_html
    
    if ($clean_domain !== $clean_primary) {
        // THE FIX: "Application Container" Architecture
        // Extract the prefix (e.g. 'wp931' from 'wp931.cielocloud.dev')
        $domain_parts = explode('.', $clean_domain);
        $app_name = preg_replace('/[^a-zA-Z0-9]/', '', $domain_parts[0]);
        if (empty($app_name)) $app_name = 'wp';
        
        // Put the site in a dedicated "sites" folder outside public_html.
        // We append a short random number to prevent folder name collisions.
        $doc_root = 'sites/' . $app_name . '_' . rand(100, 999);

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

	sleep(2); // Breathing room for WHM to register the new domain

	if ($installer_engine === 'wptoolkit') {
		// --- WP TOOLKIT LOGIC ---
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
		// --- INSTALLATRON LOGIC ---
		// Force JSON response via querystring
		$installatron_api_url = "https://{$whm_host_domain}:2087/cgi/installatron/api.cgi?api=json";
		
		// Installatron requires standard URL-encoded form data
		$installatron_payload = [
			'cmd'         => 'install',
			'user'        => $whm_username, // Tells Installatron which cPanel account owns this
			'application' => 'wordpress',
			'url'         => 'http://' . $clean_domain . '/', // THE FIX: HTTP + Trailing slash bypasses early SSL validation failures!
			'login'       => $wp_admin_user,
			'passwd'      => $wp_admin_pass,
			'email'       => $wp_admin_email,
			'sitetitle'   => 'New WordPress Site',
			'background'  => 0 // THE FIX: Forces Installatron to wait and report actual errors rather than dying silently
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

		// Read the JSON response to verify the installation actually completed
		$installatron_data = json_decode($response, true);
		$is_success = isset($installatron_data['result']) && $installatron_data['result'];

		if (!$is_success && class_exists('SkyHSHOSO_Logger')) {
			$error_msg = isset($installatron_data['message']) ? $installatron_data['message'] : 'Unknown Installatron Error';
			if (empty($response)) $error_msg = 'Empty response from Installatron API (Check WHM port 2087 accessibility).';
			
			SkyHSHOSO_Logger::error('Installatron Auto-Install Failed. Error: ' . $error_msg . ' | Raw: ' . $response, ['source' => 'installatron']);
		}
	} elseif ($installer_engine === 'softaculous') {
		// --- SOFTACULOUS LOGIC ---
		// Softaculous does not expose a native root-level REST API. It requires either SSH CLI access
		// or hitting the user-level port (2083) with their plain-text password. 
		if (class_exists('SkyHSHOSO_Logger')) {
			SkyHSHOSO_Logger::error('Softaculous requires user-level cPanel API auth which is not available in this secure REST context. Please default to WP Toolkit or Installatron.');
		}
	}
	// ---------------------------------------------------------

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
                    if (in_array(strtolower($dirname), ['cgi-bin', 'css', 'js', 'images', 'wp-admin', 'wp-includes', 'wp-content', 'vendor', 'node_modules'])) continue;
                    $dynamic_subs[] = '/' . $dirname;
                }
            }
        } else {
            $dynamic_subs = array_merge($dynamic_subs, ['/blog', '/wp', '/wordpress']);
        }

        $dynamic_subs = array_unique($dynamic_subs);

        foreach ($dynamic_subs as $sub) {
            // THE FIX: Strict formatting ensures the array blocks duplicates perfectly
            $target_root = trim($doc_root_base . $sub, '/'); 
            
            if (isset($queued_doc_roots[$target_root])) {
                continue;
            }
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

    $relative_dir = preg_replace('#^/?home/' . preg_quote($username, '#') . '/#', '', $doc_root);
    if (empty($relative_dir)) $relative_dir = 'public_html';

    // PRE-FLIGHT CHECK
    $file_check = $whm_api->cpanel_uapi_call_v3_raw($username, 'Fileman', 'get_file_information', [
        'path' => trim($relative_dir, '/') . '/wp-config.php'
    ]);

    if (!isset($file_check['result']['status']) || $file_check['result']['status'] != 1) {
        wp_send_json_error(['message' => 'WordPress is not fully provisioned yet! Please click the "cPanel" button and finish installing WordPress before changing the domain.']);
    }

    $is_primary = ($relative_dir === 'public_html' && $old_domain === $current_primary_clean);

    // ---------------------------------------------------------
    // PHASE 1: Server Infrastructure Domain Update & Cleanup
    // ---------------------------------------------------------
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

        // THE FIX: Eradicate the old domain routing so the scanner never sees duplicates!
        if ($success && !empty($old_domain) && $old_domain !== $new_domain) {
            $whm_api->cpanel_uapi_call_v3_raw($username, 'AddonDomain', 'deladdondomain', ['domain' => $old_domain]);
            $whm_api->cpanel_uapi_call_v3_raw($username, 'SubDomain', 'delsubdomain', ['domain' => $old_domain]);
        }
    }

    $whm_api->cpanel_uapi_call_v3_raw($username, 'SSL', 'start_autossl_check', []);

    // ---------------------------------------------------------
    // PHASE 2: WordPress Database Migration (DNS BYPASS INJECTION)
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

    // ---------------------------------------------------------
    // PHASE 3: Update Dashboard Cache & Database
    // ---------------------------------------------------------
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
 * TWO-WAY WHM ACCOUNT MANAGEMENT (SYNC, SUSPEND, TERMINATE)
 * =========================================================================
 */

// 1. Synchronize Account with WHM
add_action('wp_ajax_skyhshoso_sync_account', 'skyhshoso_sync_account_callback');
function skyhshoso_sync_account_callback() {
    $nonce = $_POST['nonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'skyhshoso_dashboard_nonce')) wp_send_json_error(['message' => 'Security check failed.']);
    
    $hosting_id = absint($_POST['hosting_id']);
    $current_user_id = get_current_user_id();
    if ($current_user_id !== absint(get_post_field('post_author', $hosting_id)) && !current_user_can('administrator')) {
        wp_send_json_error(['message' => 'Permission denied.']);
    }

    $server_id = get_post_meta($hosting_id, 'skyhshoso_server_id', true);
    $username  = get_post_meta($hosting_id, 'skyhshoso_hosting_username', true);

    require_once dirname(__FILE__) . '/class-whm-integration.php';
    $whm_api = new SkyHSHOSO_WHM_API(
        get_post_meta($server_id, '_skyhshoso_whm_user_id', true),
        get_post_meta($server_id, '_skyhshoso_whm_token', true),
        get_post_meta($server_id, '_skyhshoso_whm_host', true)
    );

    $summary = $whm_api->call('accountsummary', ['user' => $username]);

    if (isset($summary['metadata']['result']) && $summary['metadata']['result'] == 1) {
        $acct = $summary['data']['acct'][0];
        
        // Sync Disk Usage & Status
        $status = $acct['suspended'] ? 'suspended' : 'active';
        update_post_meta($hosting_id, 'skyhshoso_account_status', $status);
        
        SkyHSHOSO_WHM_API::clear_stats_cache($hosting_id); // Clear cache so new stats load
        wp_send_json_success(['message' => 'Account successfully synced with WHM.', 'status' => $status]);
    } else {
        wp_send_json_error(['message' => 'Could not locate account on server. It may have been deleted.']);
    }
}

// 2. Suspend / Unsuspend Account
add_action('wp_ajax_skyhshoso_toggle_suspend', 'skyhshoso_toggle_suspend_callback');
function skyhshoso_toggle_suspend_callback() {
    $nonce = $_POST['nonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'skyhshoso_dashboard_nonce')) wp_send_json_error(['message' => 'Security check failed.']);
    
    $hosting_id = absint($_POST['hosting_id']);
    $action = sanitize_text_field($_POST['status_action']); // 'suspend' or 'unsuspend'
    
    if ($current_user_id !== absint(get_post_field('post_author', $hosting_id)) && !current_user_can('administrator')) {
        wp_send_json_error(['message' => 'Permission denied.']);
    }

    $server_id = get_post_meta($hosting_id, 'skyhshoso_server_id', true);
    $username  = get_post_meta($hosting_id, 'skyhshoso_hosting_username', true);

    require_once dirname(__FILE__) . '/class-whm-integration.php';
    $whm_api = new SkyHSHOSO_WHM_API(
        get_post_meta($server_id, '_skyhshoso_whm_user_id', true),
        get_post_meta($server_id, '_skyhshoso_whm_token', true),
        get_post_meta($server_id, '_skyhshoso_whm_host', true)
    );

    if ($action === 'suspend') {
        $res = $whm_api->call('suspendacct', ['user' => $username, 'reason' => 'Suspended by user via dashboard.']);
        $new_status = 'suspended';
    } else {
        $res = $whm_api->call('unsuspendacct', ['user' => $username]);
        $new_status = 'active';
    }

    if (isset($res['metadata']['result']) && $res['metadata']['result'] == 1) {
        update_post_meta($hosting_id, 'skyhshoso_account_status', $new_status);
        wp_send_json_success(['message' => 'Account status updated successfully.', 'new_status' => $new_status]);
    } else {
        wp_send_json_error(['message' => 'WHM Error: ' . ($res['metadata']['reason'] ?? 'Unknown API error')]);
    }
}

// 3. Terminate Account (And Cancel WooCommerce Subscription)
add_action('wp_ajax_skyhshoso_terminate_account', 'skyhshoso_terminate_account_callback');
function skyhshoso_terminate_account_callback() {
    $nonce = $_POST['nonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'skyhshoso_dashboard_nonce')) wp_send_json_error(['message' => 'Security check failed.']);
    
    $hosting_id = absint($_POST['hosting_id']);
    if (get_current_user_id() !== absint(get_post_field('post_author', $hosting_id)) && !current_user_can('administrator')) {
        wp_send_json_error(['message' => 'Permission denied.']);
    }

    $server_id = get_post_meta($hosting_id, 'skyhshoso_server_id', true);
    $username  = get_post_meta($hosting_id, 'skyhshoso_hosting_username', true);

    require_once dirname(__FILE__) . '/class-whm-integration.php';
    $whm_api = new SkyHSHOSO_WHM_API(
        get_post_meta($server_id, '_skyhshoso_whm_user_id', true),
        get_post_meta($server_id, '_skyhshoso_whm_token', true),
        get_post_meta($server_id, '_skyhshoso_whm_host', true)
    );

    // 1. Remove from WHM Server
    $res = $whm_api->call('removeacct', ['user' => $username]);
    
    if (isset($res['metadata']['result']) && $res['metadata']['result'] == 1) {
        
        // 2. Cancel WooCommerce Subscription if it exists
        $sub_id = get_post_meta($hosting_id, 'skyhshoso_subscription_id', true);
        if ($sub_id && function_exists('wcs_get_subscription')) {
            $subscription = wcs_get_subscription($sub_id);
            if ($subscription && $subscription->can_be_updated_to('cancelled')) {
                $subscription->update_status('cancelled', 'Account permanently terminated by user via Dashboard.');
            }
        }

        // 3. Trash the local hosting post
        wp_trash_post($hosting_id);
        wp_send_json_success(['message' => 'Account securely terminated and billing cancelled.']);
    } else {
        wp_send_json_error(['message' => 'WHM Error: ' . ($res['metadata']['reason'] ?? 'Could not terminate server account.')]);
    }
}
/**
 * =========================================================================
 * FETCH WP TOOLKIT SETS FROM WHM
 * =========================================================================
 */
add_action('wp_ajax_skyhshoso_sync_wpt_sets', 'skyhshoso_sync_wpt_sets_callback');
function skyhshoso_sync_wpt_sets_callback() {
    // Security check: Only admins can sync server settings
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Permission denied.']);

    // Grab the first active server to use its API credentials
    $servers = get_posts(['post_type' => 'skyhshoso_server', 'posts_per_page' => 1, 'post_status' => 'publish']);
    if (empty($servers)) wp_send_json_error(['message' => 'No active servers found to query.']);

    $server_id = $servers[0]->ID;
    $whm_api_token = get_post_meta($server_id, '_skyhshoso_whm_token', true);
    $whm_api_host  = get_post_meta($server_id, '_skyhshoso_whm_host', true);

    if (empty($whm_api_token) || empty($whm_api_host)) wp_send_json_error(['message' => 'Server API credentials missing.']);

    $whm_host_domain = parse_url($whm_api_host, PHP_URL_HOST) ?: $whm_api_host;
    $whm_host_domain = preg_replace('/:\d+$/', '', $whm_host_domain);

    // WP Toolkit API Endpoint for fetching Sets
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