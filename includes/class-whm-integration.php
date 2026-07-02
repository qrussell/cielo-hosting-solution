<?php
class SkyHSHOSO_WHM_API {
    private $username;
    private $token;
    private $host;

    public function __construct($username, $token, $host) {
        $this->username = $username;
        $this->token = $token;
        $this->host = $host;
    }

    public function call($endpoint, $params = []) {
        $query = http_build_query($params);

        $clean_host = preg_replace('#^https?://#i', '', trim($this->host));
        $clean_host = rtrim($clean_host, '/');

        $url = "https://{$clean_host}:2087/json-api/{$endpoint}?{$query}";

        $ssl_verify = true;
        if ( class_exists( 'SkyHSHOSO_Settings' ) && SkyHSHOSO_Settings::is_test_mode() ) {
            $ssl_verify = false;
        }

        $args = [
            'headers' => [
                'Authorization' => "whm {$this->username}:{$this->token}"
            ],
            'sslverify' => $ssl_verify,
            'timeout'   => 30,
        ];

        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = wp_remote_retrieve_body( $response );

        return json_decode( $body, true );
    }

    public function change_account_plan($username, $new_package) {
        $params = [
            'api.version' => 1,
            'user' => $username,
            'pkg' => $new_package
        ];

        $result = $this->call('changepackage', $params);

        if (isset($result['metadata']['reason']) && $result['metadata']['reason'] == 'OK') {
            return true;
        } else {
            return false;
        }
    }

    public function create_whm_account($hosting_id, $domain) {
        $server_id = get_post_meta($hosting_id, 'skyhshoso_server_id', true);
        $plan = get_post_meta($hosting_id, 'skyhshoso_hosting_plan', true);
        
        // --- USE FIRST GLOBAL BASE DOMAIN AS DEFAULT ---
        if (empty($domain)) {
            $options = get_option('skyhshoso_settings_group', []);
            $base_domains_raw = $options['wp_base_domains'] ?? '';
            
            // Extract domains separated by commas or line breaks
            $base_domains = array_values(array_filter(preg_split('/[\s,]+/', $base_domains_raw)));
            
            if (!empty($base_domains)) {
                $first_base = str_replace(['https://', 'http://', 'www.', '/'], '', $base_domains[0]);
                
                // Generate a dynamic subdomain for the new cPanel account (e.g. cp4921.cielocloud.dev)
                $domain = 'cp' . rand(1000, 9999) . '.' . $first_base;
                
                // Save it to the hosting post meta
                update_post_meta($hosting_id, 'skyhshoso_hosting_domain', $domain);
            } else {
                // Smart Validation: Pause safely if settings are empty
                $is_admin = current_user_can('administrator') || current_user_can('manage_options');
                $error_message = $is_admin
                    ? 'Admin Action Required: Please configure the "WP Base Domains" in General Settings to auto-generate domains.'
                    : 'System configuration is finalizing. Nothing is wrong with your account, but it is temporarily pending.';

                if (class_exists('SkyHSHOSO_Logger')) {
                    SkyHSHOSO_Logger::error('WHM account creation halted: No Base Domains configured in settings.', ['source' => 'whm_integration']);
                }
                return new WP_Error('missing_base_domain', $error_message);
            }
        }
        // ------------------------------------------------

        $author_id = get_post_field('post_author', $hosting_id);
        $author = get_userdata($author_id);
        $user_email = $author ? $author->user_email : '';

        $username = get_post_meta($hosting_id, 'skyhshoso_hosting_username', true);
        if (empty($username)) {
            $username = substr(preg_replace('/[^a-z0-9]/', '', strtolower($domain)), 0, 8) . substr(md5(time()), 0, 8);
            update_post_meta($hosting_id, 'skyhshoso_hosting_username', $username);
        }

        $password = get_post_meta($hosting_id, '_skyhshoso_hosting_temp_password', true);
        if (empty($password)) {
            $password = wp_generate_password(16, true, true);
            update_post_meta($hosting_id, '_skyhshoso_hosting_temp_password', $password);
        }

        $params = array(
            'api.version' => 1,
            'domain'      => $domain,
            'username'    => $username,
            'password'    => $password,
            'plan'        => $plan
        );

        if (!empty($user_email)) {
            $params['contactemail'] = $user_email;
        }

        $result = $this->call('createacct', $params);

        if (isset($result['metadata']['reason']) && $result['metadata']['reason'] == 'OK') {
            return true;
        } else {
            $error_message = isset($result['metadata']['reason']) ? $result['metadata']['reason'] : (isset($result['metadata']['result']) ? wp_json_encode($result['metadata']) : 'Unknown WHM API error');
            if (class_exists('SkyHSHOSO_Logger')) {
                SkyHSHOSO_Logger::error( 'WHM account creation failed for hosting #' . $hosting_id . ' (Domain: ' . $domain . '): ' . $error_message, array( 'source' => 'whm_integration' ) );
            }
            return new WP_Error('whm_creation_failed', $error_message);
        }
    }

    public function get_account_summary($username) {
        $result = $this->call('accountsummary', [
            'api.version' => 1,
            'user'        => $username,
        ]);

        if (empty($result['data']['acct'][0])) {
            return false;
        }

        $acct = $result['data']['acct'][0];
        $mb_to_bytes = 1048576;

        return [
            'disk_used'       => (float) ($acct['diskused'] ?? 0) * $mb_to_bytes,
            'disk_limit'      => (float) ($acct['disklimit'] ?? 0) * $mb_to_bytes,
            'bandwidth_used'  => (float) ($acct['bandwidthusage'] ?? ($acct['bandwidthused'] ?? 0)) * $mb_to_bytes,
            'bandwidth_limit' => (float) ($acct['bandwidthlimit'] ?? 0) * $mb_to_bytes,
            'domain'          => $acct['domain'] ?? '',
            'plan'            => $acct['plan'] ?? '',
            'startdate'       => $acct['startdate'] ?? '',
        ];
    }

    public function cpanel_uapi_call_v3($cpanel_user, $module, $function, $params = []) {
        $full = $this->cpanel_uapi_call_v3_raw( $cpanel_user, $module, $function, $params );
        if ( $full === false ) {
            return false;
        }
        return $full['result']['data'] ?? $full['result'] ?? false;
    }

    public function cpanel_uapi_call_v3_raw($cpanel_user, $module, $function, $params = []) {
        $params['cpanel_jsonapi_module']      = $module;
        $params['cpanel_jsonapi_func']        = $function;
        $params['cpanel_jsonapi_apiversion'] = 3;
        $params['cpanel_jsonapi_user']        = $cpanel_user;
        $params['api.version']               = 1;

        $clean_host = preg_replace('#^https?://#i', '', trim($this->host));
        $clean_host = rtrim($clean_host, '/');

        $url = "https://{$clean_host}:2087/json-api/cpanel?" . http_build_query($params);

        $ssl_verify = true;
        if ( class_exists( 'SkyHSHOSO_Settings' ) && SkyHSHOSO_Settings::is_test_mode() ) {
            $ssl_verify = false;
        }

        $args = [
            'headers' => [
                'Authorization' => "whm {$this->username}:{$this->token}"
            ],
            'sslverify' => $ssl_verify,
            'timeout'   => 30,
        ];

        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        return json_decode( $body, true );
    }

    public function get_email_accounts($cpanel_user) {
        $data = $this->cpanel_uapi_call_v3($cpanel_user, 'Email', 'list_pops_with_disk');
        if ( empty( $data ) ) return [];
        
        $accounts = [];
        foreach ( $data as $acct ) {
            $accounts[] = [
                'email'      => ($acct['email'] ?? ''),
                'disk_used'  => (float) ($acct['_diskused'] ?? $acct['diskused'] ?? 0),
                'disk_limit' => (float) ($acct['_diskquota'] ?? $acct['diskquota'] ?? 0),
                'suspended'  => ! empty( $acct['suspended_login'] ) || ! empty( $acct['suspended_incoming'] ),
            ];
        }
        return $accounts;
    }

    public function get_subdomains($cpanel_user) {
        $data = $this->cpanel_uapi_call_v3($cpanel_user, 'SubDomain', 'listsubdomains');
        if ( empty( $data ) ) return [];
        
        $subs = [];
        foreach ( $data as $sub ) {
            $subs[] = [
                'domain' => $sub['domain'] ?? '',
                'root'   => $sub['dir'] ?? $sub['documentroot'] ?? '',
            ];
        }
        return $subs;
    }

    public function get_addon_domains($cpanel_user) {
        $data = $this->cpanel_uapi_call_v3($cpanel_user, 'AddonDomain', 'listaddondomains');
        if ( empty( $data ) ) return [];
        
        $addons = [];
        foreach ( $data as $ad ) {
            $addons[] = [
                'domain'    => $ad['domain'] ?? $ad['addon_domain'] ?? '',
                'subdomain' => $ad['subdomain'] ?? '',
                'root'      => $ad['documentroot'] ?? $ad['dir'] ?? '',
            ];
        }
        return $addons;
    }

    public function get_parked_domains($cpanel_user) {
        $data = $this->cpanel_uapi_call_v3($cpanel_user, 'Park', 'listparkeddomains');
        if ( empty( $data ) ) return [];
        
        $parked = [];
        foreach ( $data as $pd ) {
            $parked[] = [
                'domain' => $pd['domain'] ?? $pd['parked_domain'] ?? '',
            ];
        }
        return $parked;
    }

    /**
     * Discovers all WordPress installations natively across the cPanel account.
     * Works for WP Toolkit, Softaculous, Installatron, or Manual installations!
     */
    public function check_wordpress($username, $primary_domain, &$debug_data = []) {
        $found_sites = [];
        $debug_data[] = "Initiating Universal WP File Scan for $username...";

        $domains_to_check = [];
        $domains_to_check[] = ['domain' => $primary_domain, 'doc_root' => 'public_html'];

        // 1. Try fetching domains via standard DomainInfo
        $domain_data = $this->cpanel_uapi_call_v3($username, 'DomainInfo', 'domains_data');
        
        if (!empty($domain_data['addon_domains'])) {
            foreach ($domain_data['addon_domains'] as $addon) {
                $domains_to_check[] = ['domain' => $addon['domain'], 'doc_root' => $addon['documentroot']];
            }
        }
        if (!empty($domain_data['sub_domains'])) {
            foreach ($domain_data['sub_domains'] as $sub) {
                $domains_to_check[] = ['domain' => $sub['domain'], 'doc_root' => $sub['documentroot']];
            }
        }

        // 2. FALLBACK: If DomainInfo is restricted, use the older, highly reliable methods
        if (count($domains_to_check) <= 1) {
            $addons = $this->get_addon_domains($username);
            if (is_array($addons)) {
                foreach ($addons as $addon) {
                    if (!empty($addon['domain']) && !empty($addon['root'])) {
                        $domains_to_check[] = ['domain' => $addon['domain'], 'doc_root' => $addon['root']];
                    }
                }
            }
            $subs = $this->get_subdomains($username);
            if (is_array($subs)) {
                foreach ($subs as $sub) {
                    if (!empty($sub['domain']) && !empty($sub['root'])) {
                        $domains_to_check[] = ['domain' => $sub['domain'], 'doc_root' => $sub['root']];
                    }
                }
            }
        }

        $checked_roots = [];
        // Support scanning the root, PLUS common auto-installer subdirectories!
        $sub_directories = ['', '/blog', '/wp', '/wordpress']; 

        // 3. Scan each document root natively for wp-config.php
        foreach ($domains_to_check as $d) {
            $domain = trim($d['domain']);
            if (empty($domain)) continue;

            $doc_root_base = preg_replace('#^/?home/' . preg_quote($username, '#') . '/#', '', $d['doc_root']);
            $doc_root_base = trim($doc_root_base, '/');
            if (empty($doc_root_base)) $doc_root_base = 'public_html';

            if (in_array($doc_root_base, $checked_roots)) continue;
            $checked_roots[] = $doc_root_base;

            $found_in_domain = false;
            
            // Loop through the root and common subfolders to find WP
            foreach ($sub_directories as $sub) {
                $doc_root = $doc_root_base . $sub;
                $file_req = $this->cpanel_uapi_call_v3_raw($username, 'Fileman', 'get_file_information', [
                    'path' => $doc_root . '/wp-config.php'
                ]);

                // If status is 1, the file exists!
                if (isset($file_req['result']['status']) && $file_req['result']['status'] == 1) {
                    $debug_data[] = "[SUCCESS] WP detected at: " . $doc_root;
                    $found_sites[] = [
                        'site_url' => 'https://' . $domain . $sub, // Accurate URL including subdirectory!
                        'doc_root' => $doc_root,
                        'insid'    => '' 
                    ];
                    $found_in_domain = true;
                    break; // Found WP, stop searching subdirectories for this domain
                }
            }
            if (!$found_in_domain) {
                $debug_data[] = "[MISSING] No WordPress at: " . $doc_root_base;
            }
        }

        return $found_sites;
    }

    /**
     * Natively bypasses WP Passwords by injecting a self-destructing SSO Token.
     */
    public function inject_wp_sso_script($username, $site_url, $admin_user = 'admin') {
        $clean_url = str_replace(['www.', 'https://', 'http://'], '', rtrim($site_url, '/'));
        
        // Separate the domain from the subdirectory (e.g. domain.com/blog -> domain.com)
        $url_parts = explode('/', $clean_url, 2);
        $host_domain = $url_parts[0];
        $sub_path = isset($url_parts[1]) ? trim($url_parts[1], '/') : '';
        
        $doc_root = 'public_html'; // Default fallback
        
        // 1. Locate the correct folder path for the DOMAIN
        $domain_data = $this->cpanel_uapi_call_v3($username, 'DomainInfo', 'domains_data');
        if (!empty($domain_data['main_domain']) && $domain_data['main_domain']['domain'] === $host_domain) {
            $doc_root = $domain_data['main_domain']['documentroot'];
        } elseif (!empty($domain_data['addon_domains'])) {
            foreach ($domain_data['addon_domains'] as $addon) {
                if ($addon['domain'] === $host_domain) { $doc_root = $addon['documentroot']; break; }
            }
        } elseif (!empty($domain_data['sub_domains'])) {
            foreach ($domain_data['sub_domains'] as $sub) {
                if ($sub['domain'] === $host_domain) { $doc_root = $sub['documentroot']; break; }
            }
        }

        // Clean absolute path for Fileman
        $doc_root_clean = preg_replace('#^/?home/' . preg_quote($username, '#') . '/#', '', $doc_root);
        $doc_root_clean = trim($doc_root_clean, '/');
        if (empty($doc_root_clean)) $doc_root_clean = 'public_html';
        
        // Append the subdirectory if the URL has one
        if (!empty($sub_path)) {
            $doc_root_clean .= '/' . $sub_path;
        }

        // 2. Generate the SSO Magic Script
        $token = wp_generate_password(24, false);
        $filename = "sso_{$token}.php";

        // THE FIX: The SSO script actively hunts for WordPress in the root AND common subdirectories
        $payload = "<?php
        define('WP_USE_THEMES', false);
        
        // Hunt for WordPress Core
        \$wp_loaded = false;
        \$paths = [
            './wp-load.php',          // Root
            './blog/wp-load.php',     // Installatron Default
            './wp/wp-load.php',       // Softaculous Default
            './wordpress/wp-load.php' // Standard Default
        ];
        
        foreach (\$paths as \$path) {
            if (file_exists(\$path)) {
                require_once(\$path);
                \$wp_loaded = true;
                break;
            }
        }
        
        if (!\$wp_loaded) {
            @unlink(__FILE__);
            die('SSO Failed: Could not find WordPress in this directory or subdirectories. Ensure it is fully installed.');
        }
        
        // Grab the top administrator account regardless of name
        \$users = get_users(['role' => 'administrator', 'number' => 1]);
        
        if (empty(\$users)) {
            @unlink(__FILE__);
            die('SSO Failed: No administrator account found.');
        }
        
        \$user = \$users[0];
        
        // Authenticate safely
        wp_set_current_user(\$user->ID, \$user->user_login);
        wp_set_auth_cookie(\$user->ID, true, is_ssl());
        do_action('wp_login', \$user->user_login, \$user);
        
        // Self-Destruct instantly
        @unlink(__FILE__);
        
        // Redirect perfectly into the WordPress Dashboard
        wp_safe_redirect(admin_url());
        exit;
        ?>";

        // 3. Push the script to the server
        $req = $this->cpanel_uapi_call_v3_raw($username, 'Fileman', 'save_file_content', [
            'dir'    => $doc_root_clean,
            'file'   => $filename,
            'content'=> $payload
        ]);

        if (isset($req['result']['status']) && $req['result']['status'] == 1) {
            return 'https://' . $clean_url . '/' . $filename;
        }

        return false;
    }

    public function get_all_account_stats($hosting_id, $cpanel_user, $domain) {
        $stats = [
            'email_accounts' => $this->get_email_accounts( $cpanel_user ),
            'subdomains'     => $this->get_subdomains( $cpanel_user ),
            'addon_domains'  => $this->get_addon_domains( $cpanel_user ),
            'parked_domains' => $this->get_parked_domains( $cpanel_user ),
            'wordpress_sites' => $this->check_wordpress( $cpanel_user, $domain ),
        ];
        return $stats;
    }

    public static function clear_stats_cache($hosting_id) {
        delete_transient( 'skyhshoso_cpanel_stats_' . $hosting_id );
        delete_transient( 'skyhshoso_usage_' . $hosting_id );
    }

    public function terminate_account($username) {
        $result = $this->call('removeacct', [
            'api.version' => 1,
            'user'        => $username,
        ]);
        return isset($result['metadata']['reason']) && $result['metadata']['reason'] === 'OK';
    }

    public function suspend_account($username, $reason = 'Subscription expired') {
        $params = [
            'api.version' => 1,
            'user' => $username,
            'reason' => $reason
        ];
        $result = $this->call('suspendacct', $params);
        return isset($result['metadata']['reason']) && $result['metadata']['reason'] == 'OK';
    }

    public function reactivate_account($username) {
        $params = [
            'api.version' => 1,
            'user' => $username
        ];
        $result = $this->call('unsuspendacct', $params);
        return isset($result['metadata']['reason']) && $result['metadata']['reason'] == 'OK';
    }
}

class SkyHSHOSO_WHM_Integration {
    private $whm_api;

    public function __construct($username, $token, $host) {
        $this->whm_api = new SkyHSHOSO_WHM_API($username, $token, $host);
    }

    public function get_packages() {
        $params = [
            'api.version' => 1,
        ];
        $response = $this->whm_api->call('listpkgs', $params);

		if ( $response === false ) {
			SkyHSHOSO_Logger::error( 'WHM get_packages failed: connection error', array( 'source' => 'whm_integration' ) );
			return new WP_Error( 'whm_connection_error', __( 'Failed to connect to WHM API. Please verify the Host URL and API credentials.', 'skyhs-hosting-solution' ) );
		}

		if ( isset( $response['metadata']['reason'] ) && $response['metadata']['reason'] !== 'OK' ) {
			SkyHSHOSO_Logger::error( 'WHM get_packages failed: ' . $response['metadata']['reason'], array( 'source' => 'whm_integration' ) );
			return new WP_Error( 'whm_api_error', $response['metadata']['reason'] );
		}

        if ($response && isset($response['data']['pkg'])) {
            return $response['data']['pkg'];
        }

        return [];
    }

    public function save_packages($server_id) {
        $packages = $this->get_packages();

        if ( is_wp_error( $packages ) ) {
            delete_post_meta( $server_id, '_skyhshoso_whm_default_package_names' );
            update_post_meta( $server_id, '_skyhshoso_whm_last_error', $packages->get_error_message() );
            return false;
        }

        if (!empty($packages)) {
            $default_names = [];
            foreach ($packages as $package) {
                if (isset($package['FEATURELIST']) && $package['FEATURELIST'] === 'default') {
                    $default_names[] = $package['name'];
                }
            }
            if (!empty($default_names)) {
                update_post_meta($server_id, '_skyhshoso_whm_default_package_names', $default_names);
                delete_post_meta($server_id, '_skyhshoso_whm_last_error');
                return true;
            }
        }

        delete_post_meta($server_id, '_skyhshoso_whm_default_package_names');
        update_post_meta($server_id, '_skyhshoso_whm_last_error', __( 'Connection successful, but no packages with the default feature list were found on this WHM server.', 'skyhs-hosting-solution' ) );
        return false;
    }

    public function get_accounts() {
        $params = array(
            'api.version' => 1,
            'want'        => 'user,domain,plan,diskused,disklimit,startdate,suspended,email',
        );
        $response = $this->whm_api->call( 'listaccts', $params );

		if ( $response === false ) {
			SkyHSHOSO_Logger::error( 'WHM get_accounts failed: connection error', array( 'source' => 'whm_integration' ) );
			return new WP_Error( 'whm_connection_error', __( 'Failed to connect to WHM API.', 'skyhs-hosting-solution' ) );
		}

		if ( isset( $response['metadata']['reason'] ) && $response['metadata']['reason'] !== 'OK' ) {
			SkyHSHOSO_Logger::error( 'WHM get_accounts failed: ' . $response['metadata']['reason'], array( 'source' => 'whm_integration' ) );
			return new WP_Error( 'whm_api_error', $response['metadata']['reason'] );
		}

		$accts = isset( $response['data']['acct'] ) ? $response['data']['acct'] : array();
        if ( ! is_array( $accts ) ) {
            return array();
        }
        return $accts;
    }

    public function display_packages($server_id) {
        $default_names = get_post_meta($server_id, '_skyhshoso_whm_default_package_names', true);
        $last_error = get_post_meta($server_id, '_skyhshoso_whm_last_error', true);

        if (!empty($default_names)) {
            echo '<h3>' . esc_html__('WHM Packages with Default Feature List', 'skyhs-hosting-solution') . '</h3>';
            echo '<ul>';
            foreach ($default_names as $package_name) {
                $formatted_name = ucwords(str_replace('_', ' ', $package_name));
                echo '<li>' . esc_html( $formatted_name ) . '</li>';
            }
            echo '</ul>';
        } elseif (!empty($last_error)) {
            echo '<div class="notice notice-error inline" style="margin: 15px 0; padding: 8px 12px; border-left: 4px solid #d63638; background: #fff; box-shadow: 0 1px 1px 0 rgba(0,0,0,.1);"><p style="margin:0; font-weight:500; color:#d63638;"><strong>' . esc_html__('Last Sync Attempt Error:', 'skyhs-hosting-solution') . '</strong> ' . esc_html($last_error) . '</p></div>';
        } else {
            echo '<p>' . esc_html__('No packages have been imported yet. Ensure user configuration is correct and save changes to sync.', 'skyhs-hosting-solution') . '</p>';
        }
    }
}