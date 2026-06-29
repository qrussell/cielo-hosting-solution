<?php
/**
 * SkyHS WordPress Manager
 *
 * Manages WordPress installations on a shared cPanel account through WHM API.
 * Uses cPanel UAPI calls proxied through WHM for addon domains, MySQL, and file operations.
 *
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

class SkyHSHOSO_WordPress_Manager {

    private $whm_api;
    private $cpanel_user;
    private $whm_host;

    public function __construct( $whm_username, $whm_token, $whm_host, $cpanel_user ) {
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-whm-integration.php';
        $this->whm_api     = new SkyHSHOSO_WHM_API( $whm_username, $whm_token, $whm_host );
        $this->cpanel_user = $cpanel_user;
        $this->whm_host    = $whm_host;
    }

    private function debug_log( $message ) {
    }

    /**
     * Create an addon domain on the cPanel account.
     *
     * @param string $domain The domain to add.
     * @return string|WP_Error Document root path on success, WP_Error on failure.
     */
    public function create_addon_domain( $domain ) {
        $dir = "/home/{$this->cpanel_user}/addondomains/{$domain}";

        $this->debug_log( "Creating addon domain: domain={$domain}, dir={$dir}, cpanel_user={$this->cpanel_user}" );

        // Extract the subdomain prefix (e.g. "example" from "example.com")
        $subdomain_prefix = explode( '.', $domain )[0];

        // Use API v2 since UAPI v3 AddonDomain module may not be available
        $full = $this->whm_api->cpanel_api_v2_raw( $this->cpanel_user, 'AddonDomain', 'addaddondomain', array(
            'dir'         => $dir,
            'newdomain'   => $domain,
            'subdomain'   => $subdomain_prefix,
            'disallowdot' => 0,
        ) );

        $this->debug_log( 'AddonDomain API v2 response: ' . ( $full ? wp_json_encode( $full ) : 'false (connection failed)' ) );

		if ( $full === false ) {
			SkyHSHOSO_Logger::error( 'WordPress addon domain creation failed for ' . $domain . ': connection to WHM API failed', array( 'source' => 'wordpress_manager' ) );
			return new WP_Error( 'addon_domain_failed', 'Connection to WHM API failed.' );
		}

		$data = $full['cpanelresult']['data'] ?? array();
		$result_code = $data[0]['result'] ?? 0;

		if ( ! $result_code ) {
			$reason = $data[0]['reason'] ?? 'Unknown error';
			$this->debug_log( "Addon domain failed: {$reason}" );
			SkyHSHOSO_Logger::error( 'WordPress addon domain creation failed for ' . $domain . ': ' . $reason, array( 'source' => 'wordpress_manager' ) );
			return new WP_Error( 'addon_domain_failed', $reason );
		}

        $this->debug_log( "Addon domain created successfully: {$domain}" );
        return $dir;
    }

    /**
     * Trigger an AutoSSL check for the cPanel user to generate SSL certificates.
     *
     * @return bool
     */
    public function trigger_autossl_check() {
        $this->debug_log( "Triggering AutoSSL check via SSL::start_autossl_check" );
        $full = $this->whm_api->cpanel_uapi_call_v3_raw( $this->cpanel_user, 'SSL', 'start_autossl_check' );
        $this->debug_log( 'start_autossl_check response: ' . ( $full ? wp_json_encode( $full ) : 'false' ) );
        return ! empty( $full['result']['status'] );
    }

    /**
     * Check if an addon domain already exists.
     *
     * @param string $domain The domain to check.
     * @return bool
     */
    public function addon_domain_exists( $domain ) {
        $addons = $this->get_addon_domains();
        if ( empty( $addons ) ) {
            return false;
        }
        foreach ( $addons as $ad ) {
            $ad_domain = $ad['domain'] ?? $ad['addon_domain'] ?? '';
            if ( strtolower( $ad_domain ) === strtolower( $domain ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get all addon domains from the cPanel account.
     *
     * @return array
     */
    public function get_addon_domains() {
        $full = $this->whm_api->cpanel_api_v2_raw( $this->cpanel_user, 'AddonDomain', 'listaddondomains' );
        if ( empty( $full ) ) {
            return array();
        }
        return $full['cpanelresult']['data'] ?? array();
    }

    /**
     * Remove an addon domain.
     *
     * @param string $domain The domain to remove.
     * @return bool
     */
    public function remove_addon_domain( $domain ) {
        $full = $this->whm_api->cpanel_api_v2_raw( $this->cpanel_user, 'AddonDomain', 'deladdondomain', array(
            'domain' => $domain,
        ) );
        if ( empty( $full ) ) {
            return false;
        }
        $data = $full['cpanelresult']['data'] ?? array();
        return ! empty( $data[0]['result'] );
    }

    /**
     * Create a MySQL database on the cPanel account.
     *
     * @param string $db_name The database name.
     * @return bool
     */
    public function create_database( $db_name ) {
        $this->debug_log( "Creating database: {$db_name}" );
        $full = $this->whm_api->cpanel_uapi_call_v3_raw( $this->cpanel_user, 'Mysql', 'create_database', array(
            'name' => $db_name,
        ) );
		if ( $full === false ) {
			$this->debug_log( 'create_database: connection failed' );
			SkyHSHOSO_Logger::error( 'WordPress database creation failed for ' . $db_name . ': connection to WHM API failed', array( 'source' => 'wordpress_manager' ) );
			return false;
		}
		$status = $full['result']['status'] ?? 0;
		if ( ! $status ) {
			$errors = $full['result']['errors'] ?? array();
			$error_msg = ! empty( $errors ) ? implode( '; ', $errors ) : 'status=0';
			$this->debug_log( 'create_database failed: ' . $error_msg );
			SkyHSHOSO_Logger::error( 'WordPress database creation failed for ' . $db_name . ': ' . $error_msg, array( 'source' => 'wordpress_manager' ) );
			return false;
		}
        $this->debug_log( "Database created: {$db_name}" );
        return true;
    }

    public function create_database_user( $db_user, $db_pass ) {
        $this->debug_log( "Creating database user: {$db_user}" );
        $full = $this->whm_api->cpanel_uapi_call_v3_raw( $this->cpanel_user, 'Mysql', 'create_user', array(
            'name'     => $db_user,
            'password' => $db_pass,
        ) );
		if ( $full === false ) {
			$this->debug_log( 'create_database_user: connection failed' );
			SkyHSHOSO_Logger::error( 'WordPress database user creation failed: connection to WHM API failed', array( 'source' => 'wordpress_manager' ) );
			return false;
		}
		$status = $full['result']['status'] ?? 0;
		if ( ! $status ) {
			$errors = $full['result']['errors'] ?? array();
			$error_msg = ! empty( $errors ) ? implode( '; ', $errors ) : 'status=0';
			$this->debug_log( 'create_database_user failed: ' . $error_msg );
			SkyHSHOSO_Logger::error( 'WordPress database user creation failed: ' . $error_msg, array( 'source' => 'wordpress_manager' ) );
			return false;
		}
        $this->debug_log( "Database user created: {$db_user}" );
        return true;
    }

    /**
     * Grant database privileges to a user via cPanel UAPI.
     *
     * @param string $db_name Database name (must include cPanel prefix).
     * @param string $db_user Database username (must include cPanel prefix).
     * @param string $db_pass Database password (unused but kept for API compat).
     * @return bool
     */
    public function set_database_privileges( $db_name, $db_user, $db_pass ) {
        $this->debug_log( "Granting ALL PRIVILEGES via UAPI: db={$db_name}, user={$db_user}" );

        $full = $this->whm_api->cpanel_uapi_call_v3_raw( $this->cpanel_user, 'Mysql', 'set_privileges_on_database', array(
            'user'       => $db_user,
            'database'   => $db_name,
            'privileges' => 'ALL PRIVILEGES',
        ) );

        $this->debug_log( 'set_privileges_on_database response: ' . ( $full ? wp_json_encode( $full ) : 'false (connection failed)' ) );

        if ( $full === false ) {
            $this->debug_log( 'set_database_privileges: connection failed' );
            return false;
        }

        $status = $full['result']['status'] ?? 0;
        if ( ! $status ) {
            $errors = $full['result']['errors'] ?? array();
            $this->debug_log( 'set_database_privileges failed: ' . ( ! empty( $errors ) ? implode( '; ', $errors ) : 'status=0' ) );
            return false;
        }

        $this->debug_log( "Privileges granted successfully for {$db_user} on {$db_name}" );
        return true;
    }

    /**
     * Drop a MySQL database.
     *
     * @param string $db_name The database name.
     * @return bool
     */
    public function drop_database( $db_name ) {
        $result = $this->whm_api->cpanel_uapi_call_v3( $this->cpanel_user, 'Mysql', 'delete_database', array(
            'name' => $db_name,
        ) );
        return ! empty( $result );
    }

    /**
     * Delete a MySQL user.
     *
     * @param string $db_user The username.
     * @return bool
     */
    public function delete_database_user( $db_user ) {
        $result = $this->whm_api->cpanel_uapi_call_v3( $this->cpanel_user, 'Mysql', 'delete_user', array(
            'name' => $db_user,
        ) );
        return ! empty( $result );
    }

    /**
     * Deploy and run the WordPress provisioning script.
     *
     * @param string $doc_root    Document root of the addon domain.
     * @param string $db_name     MySQL database name.
     * @param string $db_user     MySQL username.
     * @param string $db_pass     MySQL password.
     * @param string $site_title  WordPress site title.
     * @param string $admin_user  WordPress admin username.
     * @param string $admin_pass  WordPress admin password.
     * @param string $admin_email WordPress admin email.
     * @return array|WP_Error Array with site_url and admin_url on success.
     */
    public function install_wordpress( $doc_root, $db_name, $db_user, $db_pass, $site_title, $admin_user, $admin_pass, $admin_email, $storage_mb = 500, $memory_limit = '64M', $plugins = array() ) {
        $this->debug_log( "Starting WordPress install: doc_root={$doc_root}, db_name={$db_name}, storage={$storage_mb}MB, memory={$memory_limit}" );

		// Load the provisioning script template
		$template_file = SKYHSHOSO_PLUGIN_DIR . 'includes/wp-installer-template.php';
		if ( ! file_exists( $template_file ) ) {
			$this->debug_log( 'install_wordpress: template file not found at ' . $template_file );
			SkyHSHOSO_Logger::error( 'WordPress install failed: provisioning script template not found at ' . $template_file, array( 'source' => 'wordpress_manager' ) );
			return new WP_Error( 'missing_template', 'Provisioning script template not found at ' . $template_file );
		}

		$script_content = file_get_contents( $template_file );
		if ( empty( $script_content ) ) {
			$this->debug_log( 'install_wordpress: template file is empty' );
			SkyHSHOSO_Logger::error( 'WordPress install failed: provisioning script template is empty', array( 'source' => 'wordpress_manager' ) );
			return new WP_Error( 'empty_template', 'Provisioning script template is empty.' );
		}

        // Inject the target install directory into the script
        $target_dir = rtrim( $doc_root, '/' );
        $script_content = str_replace(
            '// --- TARGET_DIR_PLACEHOLDER ---',
            "\$target_install_dir = '" . addslashes( $target_dir ) . "';",
            $script_content
        );

        // Inject storage and memory limits
        $script_content = str_replace(
            '// --- STORAGE_PLACEHOLDER ---',
            "\$skyhs_storage_mb = {$storage_mb};",
            $script_content
        );
        $script_content = str_replace(
            '// --- MEMORY_PLACEHOLDER ---',
            "\$skyhs_memory_limit = '{$memory_limit}';",
            $script_content
        );

        // Inject selected plugins (comma-separated slugs)
        $plugins_csv = ! empty( $plugins ) ? implode( ',', array_map( 'sanitize_title', $plugins ) ) : '';
        $script_content = str_replace(
            '// --- PLUGINS_PLACEHOLDER ---',
            "\$plugins_to_install = '{$plugins_csv}';",
            $script_content
        );

        // Deploy provisioning script to the MAIN domain's public_html (always accessible via HTTP)
        $main_dir   = "/home/{$this->cpanel_user}/public_html";
        $script_file = 'skyhs-wp-installer.php';

        $this->debug_log( "Writing provisioning script to main domain: {$main_dir}/{$script_file}" );
        $this->debug_log( "Script content length: " . strlen( $script_content ) . " bytes" );
        $this->debug_log( "Script starts with: " . substr( $script_content, 0, 50 ) );

        // IMPORTANT: cPanel UAPI save_file_content expects PLAIN TEXT content, NOT base64.
        // Sending base64 would save the encoded string literally to disk, producing a
        // non-executable file that the web server returns as raw text.
        $write_full = $this->whm_api->cpanel_uapi_call_v3_raw( $this->cpanel_user, 'Fileman', 'save_file_content', array(
            'dir'     => $main_dir,
            'file'    => $script_file,
            'content' => $script_content,
        ) );

        $this->debug_log( 'Fileman save_file_content response: ' . ( $write_full ? wp_json_encode( $write_full ) : 'false' ) );

		if ( $write_full === false ) {
			SkyHSHOSO_Logger::error( 'WordPress install failed: could not write provisioning script to cPanel (connection failed)', array( 'source' => 'wordpress_manager' ) );
			return new WP_Error( 'write_failed', 'Failed to write provisioning script to cPanel (connection failed).' );
		}

		$write_status = $write_full['result']['status'] ?? 0;
		if ( ! $write_status ) {
			$write_errors = $write_full['result']['errors'] ?? array();
			$error_msg = ! empty( $write_errors ) ? implode( '; ', $write_errors ) : 'Failed to write file (status=0)';
			$this->debug_log( "Fileman write failed: {$error_msg}" );
			SkyHSHOSO_Logger::error( 'WordPress install failed: could not write provisioning script: ' . $error_msg, array( 'source' => 'wordpress_manager' ) );
			return new WP_Error( 'write_failed', $error_msg );
		}

        // Deploy the mu-plugin template alongside the installer
        $mu_template_file = SKYHSHOSO_PLUGIN_DIR . 'includes/wp-mu-plugin-template.php';
        if ( file_exists( $mu_template_file ) ) {
            $mu_content = file_get_contents( $mu_template_file );
            $mu_content = str_replace( 'STORAGE_MB_VALUE', (string) $storage_mb, $mu_content );
            $this->whm_api->cpanel_uapi_call_v3_raw( $this->cpanel_user, 'Fileman', 'save_file_content', array(
                'dir'     => $main_dir,
                'file'    => 'skyhs-mu-plugin.php',
                'content' => $mu_content,
            ) );
            $this->debug_log( "Deployed mu-plugin template with storage={$storage_mb}MB" );
        } else {
            $this->debug_log( "mu-plugin template not found at {$mu_template_file}" );
        }

		// Resolve the main domain for this cPanel account via WHM
		$main_domain = $this->get_main_domain();
		if ( empty( $main_domain ) ) {
			SkyHSHOSO_Logger::error( 'WordPress install failed: could not resolve the main domain for cPanel user ' . $this->cpanel_user, array( 'source' => 'wordpress_manager' ) );
			return new WP_Error( 'no_main_domain', 'Could not resolve the main domain for this cPanel account.' );
		}

        $protocol = 'https';
        $url      = "{$protocol}://{$main_domain}/{$script_file}";

        $this->debug_log( "Calling provisioning script at: {$url}" );

        $domain    = $this->extract_domain_from_docroot( $doc_root );
        $site_url  = "https://{$domain}";

        $post_args = array(
            'body' => array(
                'db_name'     => $db_name,
                'db_user'     => $db_user,
                'db_pass'     => $db_pass,
                'site_title'  => $site_title,
                'admin_user'  => $admin_user,
                'admin_pass'  => $admin_pass,
                'admin_email' => $admin_email,
                'site_url'    => $site_url,
            ),
            'timeout'  => 120,
            'sslverify' => true,
        );

        $response = wp_remote_post( $url, $post_args );

        $this->debug_log( 'Provisioning script HTTP response status: ' . ( is_wp_error( $response ) ? 'WP_Error: ' . $response->get_error_message() : wp_remote_retrieve_response_code( $response ) ) );

		if ( is_wp_error( $response ) ) {
			$this->debug_log( 'Provisioning script HTTP error: ' . $response->get_error_message() );
			SkyHSHOSO_Logger::error( 'WordPress install failed: HTTP call to provisioning script failed: ' . $response->get_error_message(), array( 'source' => 'wordpress_manager' ) );
			return new WP_Error( 'install_http_failed', 'HTTP call to provisioning script failed: ' . $response->get_error_message() );
		}

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );

        $this->debug_log( "Provisioning response body: " . substr( $body, 0, 2000 ) );

		if ( $status_code !== 200 ) {
			// Strip HTML tags from WordPress error pages to get the actual error message
			$clean_body = trim( wp_strip_all_tags( $body ) );
			$this->debug_log( "Provisioning script error (HTTP {$status_code}): {$clean_body}" );
			SkyHSHOSO_Logger::error( 'WordPress install failed (HTTP ' . $status_code . '): ' . substr( $clean_body, 0, 500 ), array( 'source' => 'wordpress_manager' ) );
			return new WP_Error( 'install_failed', "WordPress installation failed (HTTP {$status_code}): " . substr( $clean_body, 0, 500 ) );
		}

		if ( trim( $body ) !== 'OK' ) {
			// Detect if the response is base64-encoded PHP (indicates save_file_content wrote base64 literally)
			$decoded = base64_decode( trim( $body ), true );
			if ( $decoded !== false && strpos( $decoded, '<?php' ) === 0 ) {
				$this->debug_log( 'ERROR: Response body is base64-encoded PHP script — save_file_content wrote base64 literally instead of plain text!' );
				SkyHSHOSO_Logger::error( 'WordPress install failed: provisioning script was saved as base64 instead of plain text', array( 'source' => 'wordpress_manager' ) );
				return new WP_Error( 'install_error', 'Provisioning script was not saved correctly (base64 encoding issue). The file content was not executable PHP.' );
			}
			// Detect if the response is raw PHP source (PHP execution disabled in that dir)
			if ( strpos( trim( $body ), '<?php' ) === 0 ) {
				$this->debug_log( 'ERROR: Response body is raw PHP source — PHP execution may be disabled in this directory.' );
				SkyHSHOSO_Logger::error( 'WordPress install failed: PHP execution appears disabled in target directory', array( 'source' => 'wordpress_manager' ) );
				return new WP_Error( 'install_error', 'PHP execution appears to be disabled in the target directory. The provisioning script was returned as raw source.' );
			}
			SkyHSHOSO_Logger::error( 'WordPress install failed: provisioning script returned error: ' . substr( $body, 0, 500 ), array( 'source' => 'wordpress_manager' ) );
			return new WP_Error( 'install_error', 'Provisioning script returned an error: ' . substr( $body, 0, 500 ) );
		}

        // Clean up the provisioning script from main domain
        $this->whm_api->cpanel_uapi_call_v3( $this->cpanel_user, 'Fileman', 'save_file_content', array(
            'dir'     => $main_dir,
            'file'    => $script_file,
            'content' => '<?php // cleaned up',
        ) );

        // Verify installation by checking for the status file
        sleep( 3 );
        $this->debug_log( 'Checking for skyhs-wp-installed.json status file' );
        $status_content = $this->whm_api->cpanel_uapi_call_v3( $this->cpanel_user, 'Fileman', 'get_file_content', array(
            'dir'  => $target_dir,
            'file' => 'skyhs-wp-installed.json',
        ) );

        $domain    = $this->extract_domain_from_docroot( $doc_root );
        $site_url  = "{$protocol}://{$domain}";
        $admin_url = "{$site_url}/wp-admin";

        if ( ! empty( $status_content['content'] ) ) {
            $status_data = json_decode( $status_content['content'], true );
            if ( ! empty( $status_data['site_url'] ) ) {
                $site_url  = $status_data['site_url'];
                $admin_url = $status_data['admin_url'];
            }
            $this->debug_log( 'Installation verified from status file' );
        } else {
            $this->debug_log( 'Status file not found, but install may have succeeded' );
        }

        $this->debug_log( "WordPress install complete: site_url={$site_url}" );
        return array(
            'site_url'   => $site_url,
            'admin_url'  => $admin_url,
            'admin_user' => $admin_user,
            'admin_pass' => $admin_pass,
        );
    }

    /**
     * Get the main domain for this cPanel account via WHM account summary.
     */
    private function get_main_domain() {
        $summary = $this->whm_api->call( 'accountsummary', array(
            'api.version' => 1,
            'user'        => $this->cpanel_user,
        ) );
        if ( ! empty( $summary['data']['acct'][0]['domain'] ) ) {
            return $summary['data']['acct'][0]['domain'];
        }
        // Fallback: extract from the known host
        $parts = explode( '.', $this->whm_host );
        if ( count( $parts ) >= 2 ) {
            return implode( '.', array_slice( $parts, -2 ) );
        }
        return '';
    }

    /**
     * Detect WordPress installations in a cPanel account via Fileman scan.
     *
     * @return array List of WP site info arrays.
     */
    public function detect_wordpress_installations() {
        $sites     = array();
        $addons    = $this->get_addon_domains();
        $dirs      = array();

        // Check public_html and each addon domain
        $dirs[] = array(
            'dir'    => "/home/{$this->cpanel_user}/public_html",
            'domain' => 'main',
        );

        foreach ( $addons as $ad ) {
            $doc_root = $ad['documentroot'] ?? $ad['dir'] ?? '';
            $domain   = $ad['domain'] ?? $ad['addon_domain'] ?? '';
            if ( $doc_root ) {
                $dirs[] = array(
                    'dir'    => $doc_root,
                    'domain' => $domain,
                );
            }
        }

        foreach ( $dirs as $check ) {
            $data = $this->whm_api->cpanel_uapi_call_v3( $this->cpanel_user, 'Fileman', 'listfiles', array(
                'dir'        => $check['dir'],
                'showhidden' => 1,
            ) );

            if ( empty( $data ) ) {
                continue;
            }

            $has_wp = false;
            foreach ( $data as $entry ) {
                $name = $entry['file'] ?? $entry['name'] ?? '';
                $type = $entry['type'] ?? '';
                if ( $name === 'wp-config.php' || ( $name === 'wp-includes' && $type === 'dir' ) ) {
                    $has_wp = true;
                    break;
                }
            }

            if ( $has_wp ) {
                $domain  = $check['domain'];
                $sites[] = array(
                    'site_url'  => $domain !== 'main' ? "https://{$domain}" : '',
                    'admin_url' => $domain !== 'main' ? "https://{$domain}/wp-admin" : '',
                    'doc_root'  => $check['dir'],
                    'domain'    => $domain,
                );
            }
        }

        return $sites;
    }

    /**
     * Check for the skyhs-wp-installed.json status file in a doc root.
     *
     * @param string $doc_root The document root to check.
     * @return array|null Status data or null.
     */
    public function get_installation_status( $doc_root ) {
        $status = $this->whm_api->cpanel_uapi_call_v3( $this->cpanel_user, 'Fileman', 'get_file_content', array(
            'dir'  => $doc_root,
            'file' => 'skyhs-wp-installed.json',
        ) );

        if ( ! empty( $status['content'] ) ) {
            return json_decode( $status['content'], true );
        }
        return null;
    }

    /**
     * Suspend a WordPress site by prepending a deny block to its .htaccess.
     *
     * @param string $doc_root The document root.
     * @return bool
     */
    public function suspend_wp_site( $doc_root ) {
        $response = $this->whm_api->cpanel_uapi_call_v3( $this->cpanel_user, 'Fileman', 'get_file_content', array(
            'dir'  => $doc_root,
            'file' => '.htaccess',
        ) );
        $content = ! empty( $response ) && isset( $response['content'] ) ? $response['content'] : '';

        // If already suspended, return true
        if ( strpos( $content, '# BEGIN SkyHS Suspension' ) !== false ) {
            return true;
        }

        $suspension_rules = "# BEGIN SkyHS Suspension\nOrder allow,deny\nDeny from all\n# END SkyHS Suspension\n";
        $new_content = $suspension_rules . $content;

        $result = $this->whm_api->cpanel_uapi_call_v3( $this->cpanel_user, 'Fileman', 'save_file_content', array(
            'dir'     => $doc_root,
            'file'    => '.htaccess',
            'content' => $new_content,
        ) );
        return ! empty( $result );
    }

    /**
     * Reactivate a suspended WordPress site by removing the deny block from its .htaccess.
     *
     * @param string $doc_root The document root.
     * @return bool
     */
    public function unsuspend_wp_site( $doc_root ) {
        $response = $this->whm_api->cpanel_uapi_call_v3( $this->cpanel_user, 'Fileman', 'get_file_content', array(
            'dir'  => $doc_root,
            'file' => '.htaccess',
        ) );
        $content = ! empty( $response ) && isset( $response['content'] ) ? $response['content'] : '';

        // Remove the suspension block
        $new_content = preg_replace( '/# BEGIN SkyHS Suspension\s*.*?\s*# END SkyHS Suspension\s*/s', '', $content );

        // If the new content is empty or only whitespace, we can write a clean reactivated comment,
        // or if it was modified, we write the new content.
        if ( trim( $new_content ) === '' ) {
            $new_content = "# BEGIN WordPress\n# END WordPress\n";
        }

        $result = $this->whm_api->cpanel_uapi_call_v3( $this->cpanel_user, 'Fileman', 'save_file_content', array(
            'dir'     => $doc_root,
            'file'    => '.htaccess',
            'content' => $new_content,
        ) );
        return ! empty( $result );
    }

    /**
     * Delete a WordPress site — files, database, and addon domain.
     *
     * @param string $doc_root The document root.
     * @param string $db_name  The MySQL database name.
     * @param string $db_user  The MySQL database user.
     * @param string $domain   The addon domain to remove.
     * @return bool
     */
    public function delete_wp_site( $doc_root, $db_name, $db_user, $domain ) {
        // 1. Drop database
        if ( ! empty( $db_name ) ) {
            $this->drop_database( $db_name );
        }

        // 2. Delete database user
        if ( ! empty( $db_user ) ) {
            $this->delete_database_user( $db_user );
        }

        // 3. Remove addon domain (removes files)
        if ( ! empty( $domain ) ) {
            $this->remove_addon_domain( $domain );
        }

        return true;
    }

    /**
     * Generate a random database name with cPanel prefix.
     *
     * @param string $prefix A prefix for the DB name.
     * @return string
     */
    public function generate_db_name( $prefix = 'wp' ) {
        return $this->cpanel_user . '_' . $prefix . substr( md5( uniqid() ), 0, 8 );
    }

    /**
     * Generate a random MySQL username.
     *
     * @return string
     */
    public function generate_db_user() {
        $user = $this->cpanel_user . '_wp' . substr( md5( uniqid() ), 0, 6 );
        return $user;
    }

    /**
     * Extract domain from a doc root path.
     * E.g. /home/cpaneluser/addondomains/example.com -> example.com
     *
     * @param string $doc_root The document root path.
     * @return string
     */
    private function extract_domain_from_docroot( $doc_root ) {
        $parts = explode( '/', rtrim( $doc_root, '/' ) );
        return end( $parts );
    }
}
