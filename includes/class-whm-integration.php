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

        // Normalize host: remove protocols (http/https) and trailing slashes
        $clean_host = preg_replace('#^https?://#i', '', trim($this->host));
        $clean_host = rtrim($clean_host, '/');

        $url = "https://{$clean_host}:2087/json-api/{$endpoint}?{$query}";

        // Enforce SSL verification unless explicitly disabled via Test Mode
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
            $error_message = isset($result['metadata']['reason']) ? $result['metadata']['reason'] : 'Unknown error';
            return false;
        }
}
    public function create_whm_account($hosting_id, $domain) {
    $server_id = get_post_meta($hosting_id, 'skyhshoso_server_id', true);
    $plan = get_post_meta($hosting_id, 'skyhshoso_hosting_plan', true);
    $current_user = wp_get_current_user();
    $user_email = $current_user->user_email;

    $username = substr(preg_replace('/[^a-z0-9]/', '', strtolower($domain)), 0, 8) . substr(md5(time()), 0, 8);
    $password = wp_generate_password(16, true, true);

    // Save password temporarily so provisioning email can include it.
    update_post_meta($hosting_id, '_skyhshoso_hosting_temp_password', $password);

    $params = array(
        'api.version' => 1,
        'domain' => $domain,
        'username' => $username,
        'password' => $password,
        'plan' => $plan,
        'contactemail' => $user_email
    );

    $result = $this->call('createacct', $params);

    if (isset($result['metadata']['reason']) && $result['metadata']['reason'] == 'OK') {
        update_post_meta($hosting_id, 'skyhshoso_hosting_username', $username);
        return true;
    } else {
		delete_post_meta($hosting_id, '_skyhshoso_hosting_temp_password');
		$error_message = isset($result['metadata']['reason']) ? $result['metadata']['reason'] : 'Unknown error';
		SkyHSHOSO_Logger::error( 'WHM account creation failed for hosting #' . $hosting_id . ': ' . $error_message, array( 'source' => 'whm_integration' ) );
		return new WP_Error('whm_creation_failed', $error_message);
    }
}

    /**
     * Get account summary with resource usage from WHM.
     *
     * @param string $username cPanel username.
     * @return array|false Disk/bandwidth stats or false on failure.
     */
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

    /**
     * Call cPanel UAPI v3 via WHM API proxy and return the full response including errors.
     * Uses the correct endpoint: /json-api/cpanel (not /json-api/uapi).
     *
     * @param string $cpanel_user
     * @param string $module
     * @param string $function
     * @param array  $params
     * @return array|false Full response array, or false on connection failure.
     */
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

    /**
     * Call cPanel API v2 via WHM proxy (useful when UAPI v3 module is unavailable).
     * Returns the full response array.
     *
     * @param string $cpanel_user
     * @param string $module
     * @param string $function
     * @param array  $params
     * @return array|false
     */
    public function cpanel_api_v2_raw($cpanel_user, $module, $function, $params = []) {
        $params['cpanel_jsonapi_module']      = $module;
        $params['cpanel_jsonapi_func']        = $function;
        $params['cpanel_jsonapi_apiversion'] = 2;
        $params['cpanel_jsonapi_user']        = $cpanel_user;

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

    public function get_softaculous_installations( $cpanel_user ) {
        $file_data = $this->cpanel_uapi_call_v3( $cpanel_user, 'Fileman', 'get_file_content', [
            'dir'  => "/home/{$cpanel_user}/.softaculous",
            'file' => 'installations.php',
        ]);

        if ( empty( $file_data['content'] ) ) {
            return false;
        }

        $content = $file_data['content'];
        
        // Extract serialized string
        if ( preg_match( '/unserialize\s*\(\s*[\'"](.*?)[\'"]\s*\)/s', $content, $matches ) ) {
            $serialized_str = $matches[1];
            // Decode escaped single quotes and backslashes
            $serialized_str = str_replace( array( "\\'", "\\\\" ), array( "'", "\\" ), $serialized_str );
            $data = @unserialize( $serialized_str );
            if ( is_array( $data ) ) {
                return $data;
            }
        }
        return false;
    }

    public function cpanel_uapi_call($cpanel_user, $module, $function, $params = []) {
        $params['cpanel_jsonapi_module']      = $module;
        $params['cpanel_jsonapi_func']        = $function;
        $params['cpanel_jsonapi_apiversion'] = 2;
        $params['cpanel_jsonapi_user']        = $cpanel_user;

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
        $data = json_decode( $body, true );

        $result_data = $data['cpanelresult']['data'] ?? null;

        if ( $result_data === null || ( is_array( $result_data ) && isset( $result_data['result'] ) && $result_data['result'] == 0 ) ) {
            return false;
        }

        return $result_data;
    }

    public function get_email_accounts($cpanel_user) {
        $data = $this->cpanel_uapi_call($cpanel_user, 'Email', 'listpopswithdisk');
        if ( empty( $data ) ) {
            return [];
        }
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
        $data = $this->cpanel_uapi_call($cpanel_user, 'SubDomain', 'listsubdomains');
        if ( empty( $data ) ) {
            return [];
        }
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
        $data = $this->cpanel_uapi_call($cpanel_user, 'AddonDomain', 'listaddondomains');
        if ( empty( $data ) ) {
            return [];
        }
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
        $data = $this->cpanel_uapi_call($cpanel_user, 'Park', 'listparkeddomains');
        if ( empty( $data ) ) {
            return [];
        }
        $parked = [];
        foreach ( $data as $pd ) {
            $parked[] = [
                'domain' => $pd['domain'] ?? $pd['parked_domain'] ?? '',
            ];
        }
        return $parked;
    }

    public function check_wordpress($cpanel_user, $domain) {
        $wordpress_sites = [];

        $subdomains = $this->get_subdomains($cpanel_user);

        $dirs_to_check = [
            ['dir' => "/home/{$cpanel_user}/public_html", 'url' => "https://{$domain}", 'depth' => 0],
        ];

        foreach ( $subdomains as $sub ) {
            $sub_domain = $sub['domain'] ?? '';
            $doc_root   = $sub['root'] ?? '';
            if ( $doc_root ) {
                $dirs_to_check[] = ['dir' => $doc_root, 'url' => "https://{$sub_domain}", 'depth' => 0];
            }
        }

        $softaculous_ins = $this->get_softaculous_installations($cpanel_user);
        $wp_installations = [];
        if ( isset($softaculous_ins[26]) && is_array($softaculous_ins[26]) ) {
            $wp_installations = $softaculous_ins[26];
        }

        foreach ( $dirs_to_check as $check ) {
            $data = $this->cpanel_uapi_call($cpanel_user, 'Fileman', 'listfiles', [
                'dir'        => $check['dir'],
                'showhidden' => 1,
            ]);

            if ( empty( $data ) ) {
                continue;
            }

            $subdirs = [];
            $has_wp = false;
            foreach ( $data as $entry ) {
                $name = $entry['file'] ?? $entry['name'] ?? '';
                $type = $entry['type'] ?? '';
                if ( $name === 'wp-config.php' || ( $name === 'wp-includes' && $type === 'dir' ) ) {
                    $has_wp = true;
                }
                if ( $type === 'dir' && ! in_array( $name, ['.', '..', 'cgi-bin'] ) && strpos( $name, '.' ) !== 0 ) {
                    $subdirs[] = $name;
                }
            }

            if ( $has_wp ) {
                $insid = '';
                $norm_check_url = rtrim( preg_replace( '#^https?://(www\.)?#i', '', $check['url'] ), '/' );
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
                    'site_url'  => $check['url'],
                    'admin_url' => $check['url'] . '/wp-admin',
                    'insid'     => $insid,
                ];
            }

            foreach ( $subdirs as $subdir ) {
                $sub_data = $this->cpanel_uapi_call($cpanel_user, 'Fileman', 'listfiles', [
                    'dir'        => $check['dir'] . '/' . $subdir,
                    'showhidden' => 1,
                ]);
                if ( empty( $sub_data ) ) continue;

                foreach ( $sub_data as $entry ) {
                    $name = $entry['file'] ?? $entry['name'] ?? '';
                    $type = $entry['type'] ?? '';
                    if ( $name === 'wp-config.php' || ( $name === 'wp-includes' && $type === 'dir' ) ) {
                        $site_url = $check['url'] . '/' . $subdir;
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
                            'admin_url' => $site_url . '/wp-admin',
                            'insid'     => $insid,
                        ];
                        break;
                    }
                }
            }
        }

        return $wordpress_sites;
    }

    public function get_all_account_stats($hosting_id, $cpanel_user, $domain) {
        $cache_key = 'skyhshoso_cpanel_stats_' . $hosting_id;
        $cached = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $stats = [
            'email_accounts' => $this->get_email_accounts( $cpanel_user ),
            'subdomains'     => $this->get_subdomains( $cpanel_user ),
            'addon_domains'  => $this->get_addon_domains( $cpanel_user ),
            'parked_domains' => $this->get_parked_domains( $cpanel_user ),
            'wordpress_sites' => $this->check_wordpress( $cpanel_user, $domain ),
        ];

        set_transient( $cache_key, $stats, DAY_IN_SECONDS );
        return $stats;
    }

    public static function clear_stats_cache($hosting_id) {
        delete_transient( 'skyhshoso_cpanel_stats_' . $hosting_id );
        delete_transient( 'skyhshoso_usage_' . $hosting_id );
    }

    /**
     * Terminate (remove) a cPanel account.
     *
     * @param string $username cPanel username.
     * @return bool
     */
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

        if (isset($result['metadata']['reason']) && $result['metadata']['reason'] == 'OK') {
            return true;
        } else {
            return false;
        }
    }

    public function reactivate_account($username) {
        $params = [
            'api.version' => 1,
            'user' => $username
        ];

        $result = $this->call('unsuspendacct', $params);

        if (isset($result['metadata']['reason']) && $result['metadata']['reason'] == 'OK') {
            return true;
        } else {
            return false;
        }
    }


}

/**
 * WHM Integration helper — fetches/saves packages, displays them.
 */
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

    /**
     * Fetch all cPanel accounts from the WHM server via listaccts.
     */
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
