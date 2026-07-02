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
     * UNIVERSAL WORDPRESS SCANNER
     * Now captures and returns the exact document root (folder path) for each site.
     */
    public function check_wordpress($cpanel_user, $domain, &$debug_info = null) {
        if (!is_array($debug_info)) $debug_info = [];
        $wordpress_sites = [];
        
        $dirs_to_check = [
            'public_html' => "https://{$domain}"
        ];

        // Gather Subdomains
        $subdomains = $this->get_subdomains($cpanel_user);
        foreach ($subdomains as $sub) {
            if (!empty($sub['root'])) {
                $rel_path = preg_replace('#^/?home/' . preg_quote($cpanel_user, '#') . '/#', '', $sub['root']);
                $dirs_to_check[trim($rel_path, '/')] = "https://{$sub['domain']}";
            }
        }

        // Gather Addon Domains
        $addons = $this->get_addon_domains($cpanel_user);
        foreach ($addons as $addon) {
            if (!empty($addon['root'])) {
                $rel_path = preg_replace('#^/?home/' . preg_quote($cpanel_user, '#') . '/#', '', $addon['root']);
                $dirs_to_check[trim($rel_path, '/')] = "https://{$addon['domain']}";
            }
        }

        $debug_info[] = "Scanning mapped directories: " . json_encode($dirs_to_check);

        // Gather Softaculous info for Native Softaculous Auto-Login
        $softaculous_ins = false;
        $file_data = $this->cpanel_uapi_call_v3_raw( $cpanel_user, 'Fileman', 'get_file_content', [
            'dir'  => '.softaculous',
            'file' => 'installations.php',
        ]);
        
        if ( isset($file_data['result']['data']['content']) ) {
            $content = $file_data['result']['data']['content'];
            if ( preg_match( '/unserialize\s*\(\s*[\'"](.*?)[\'"]\s*\)/s', $content, $matches ) ) {
                $serialized_str = str_replace( array( "\\'", "\\\\" ), array( "'", "\\" ), $matches[1] );
                $softaculous_ins = @unserialize( $serialized_str );
            }
        }
        
        $wp_installations = [];
        if ( isset($softaculous_ins[26]) && is_array($softaculous_ins[26]) ) {
            $wp_installations = $softaculous_ins[26];
        }

        // Scan mapped directories
        foreach ( $dirs_to_check as $dir => $url ) {
            if (empty($dir)) continue;

            $file_check = $this->cpanel_uapi_call_v3_raw($cpanel_user, 'Fileman', 'get_file_information', [
                'path' => $dir . '/wp-config.php'
            ]);

            $debug_info[] = "Checking [{$dir}/wp-config.php] -> Response: " . json_encode($file_check);

            $is_wp = false;
            if (isset($file_check['result']['data'][0]['type']) && $file_check['result']['data'][0]['type'] === 'file') {
                $is_wp = true;
            } elseif (isset($file_check['result']['data']['type']) && $file_check['result']['data']['type'] === 'file') {
                $is_wp = true;
            }

            if ( $is_wp ) {
                $insid = '';
                $norm_check_url = rtrim( preg_replace( '#^https?://(www\.)?#i', '', $url ), '/' );
                
                foreach ( $wp_installations as $inst ) {
                    $inst_url = $inst['softurl'] ?? $inst['url'] ?? '';
                    if ( $inst_url ) {
                        $norm_inst_url = rtrim( preg_replace( '#^https?://(www\.)?#i', '', $inst_url ), '/' );
                        if ( $norm_check_url === $norm_inst_url ) {
                            $insid = $inst['insid'] ?? '';
                            break;
                        }
                    }
                }

                $wordpress_sites[] = [
                    'site_url'  => $url,
                    'admin_url' => rtrim($url, '/') . '/wp-admin/',
                    'insid'     => $insid,
                    'doc_root'  => "/home/{$cpanel_user}/" . trim($dir, '/')
                ];
            } else {
                // Check common subdirectories for nested WP Toolkit installs
                $common_subs = ['wp', 'wordpress', 'site', 'blog'];
                foreach ($common_subs as $sub) {
                    $sub_check = $this->cpanel_uapi_call_v3_raw($cpanel_user, 'Fileman', 'get_file_information', [
                        'path' => $dir . '/' . $sub . '/wp-config.php'
                    ]);
                    
                    $is_sub_wp = false;
                    if (isset($sub_check['result']['data'][0]['type']) && $sub_check['result']['data'][0]['type'] === 'file') {
                        $is_sub_wp = true;
                    } elseif (isset($sub_check['result']['data']['type']) && $sub_check['result']['data']['type'] === 'file') {
                        $is_sub_wp = true;
                    }

                    if ($is_sub_wp) {
                        $site_url = rtrim($url, '/') . '/' . $sub;
                        $insid = '';
                        $norm_site_url = rtrim( preg_replace( '#^https?://(www\.)?#i', '', $site_url ), '/' );
                        
                        foreach ( $wp_installations as $inst ) {
                            $inst_url = $inst['softurl'] ?? $inst['url'] ?? '';
                            if ( $inst_url ) {
                                $norm_inst_url = rtrim( preg_replace( '#^https?://(www\.)?#i', '', $inst_url ), '/' );
                                if ( $norm_site_url === $norm_inst_url ) {
                                    $insid = $inst['insid'] ?? '';
                                    break;
                                }
                            }
                        }

                        $wordpress_sites[] = [
                            'site_url'  => $site_url,
                            'admin_url' => rtrim($site_url, '/') . '/wp-admin/',
                            'insid'     => $insid,
                            'doc_root'  => "/home/{$cpanel_user}/" . trim($dir, '/') . '/' . $sub
                        ];
                        break;
                    }
                }
            }
        }

        return $wordpress_sites;
    }

    public function inject_wp_sso_script($cpanel_user, $site_url, $admin_username = '') {
        $parsed = wp_parse_url($site_url);
        $host = $parsed['host'] ?? '';
        $path = $parsed['path'] ?? '';
        $host = preg_replace('#^www\.#i', '', $host);

        $doc_root = "/home/{$cpanel_user}/public_html"; 
        
        $subdomains = $this->get_subdomains($cpanel_user);
        foreach ($subdomains as $sub) {
            if ($sub['domain'] === $host && !empty($sub['root'])) $doc_root = $sub['root'];
        }
        $addons = $this->get_addon_domains($cpanel_user);
        foreach ($addons as $addon) {
            if ($addon['domain'] === $host && !empty($addon['root'])) $doc_root = $addon['root'];
        }
        
        if (!empty($path) && $path !== '/') {
            $doc_root = rtrim($doc_root, '/') . '/' . trim($path, '/');
        }
        
        $rel_path = preg_replace('#^/?home/' . preg_quote($cpanel_user, '#') . '/#', '', $doc_root);

        $token = wp_generate_password(40, false);
        $filename = "skyhs_sso_{$token}.php";

        $php_code = "<?php\n";
        $php_code .= "define('WP_USE_THEMES', false);\n";
        $php_code .= "require('./wp-load.php');\n";
        $php_code .= "\$target_user = '" . addslashes($admin_username) . "';\n";
        $php_code .= "\$user = get_user_by('login', \$target_user);\n";
        $php_code .= "if (!\$user) {\n";
        $php_code .= "    \$users = get_users(['role' => 'administrator']);\n";
        $php_code .= "    if (!empty(\$users)) \$user = \$users[0];\n";
        $php_code .= "}\n";
        $php_code .= "if (\$user) {\n";
        $php_code .= "    wp_clear_auth_cookie();\n";
        $php_code .= "    wp_set_current_user(\$user->ID);\n";
        $php_code .= "    wp_set_auth_cookie(\$user->ID);\n";
        $php_code .= "    @unlink(__FILE__);\n";
        $php_code .= "    wp_safe_redirect(admin_url());\n";
        $php_code .= "    exit;\n";
        $php_code .= "}\n";
        $php_code .= "@unlink(__FILE__);\n";
        $php_code .= "die('SSO Failed: No administrator accounts could be found to log into.');\n";

        $result = $this->cpanel_uapi_call_v3_raw($cpanel_user, 'Fileman', 'save_file_content', [
            'dir' => trim($rel_path, '/'),
            'file' => $filename,
            'content' => $php_code
        ]);

        if (isset($result['result']['status']) && $result['result']['status']) {
            return rtrim($site_url, '/') . '/' . $filename;
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