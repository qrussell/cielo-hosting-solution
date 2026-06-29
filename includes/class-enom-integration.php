<?php

defined( 'ABSPATH' ) || exit;

class SkyHSHOSO_Enom_Integration {
    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action('admin_init', array($this, 'skyhshoso_register_enom_settings'));
        add_action('wp_ajax_skyhshoso_check_domain', array($this, 'skyhshoso_ajax_check_domain'));
        add_action('wp_ajax_nopriv_skyhshoso_check_domain', array($this, 'skyhshoso_ajax_check_domain'));
        add_action('wp_ajax_skyhshoso_update_ns', array($this, 'skyhshoso_ajax_update_nameservers'));
        add_action('wp_enqueue_scripts', array($this, 'skyhshoso_enqueue_dns_editor_scripts'));
        add_action('wp_ajax_skyhshoso_update_dns_record', array($this, 'skyhshoso_ajax_update_dns_record'));
        add_action('wp_ajax_skyhshoso_check_transfer', array($this, 'skyhshoso_ajax_check_transfer'));
        add_action('wp_ajax_nopriv_skyhshoso_check_transfer', array($this, 'skyhshoso_ajax_check_transfer'));
    }

    public function skyhshoso_enqueue_dns_editor_scripts() {
        wp_enqueue_script('jquery');
        wp_localize_script('jquery', 'skyhshoso_dns_editor_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('skyhshoso_dns_editor_nonce')
        ));
    }

    public function skyhshoso_add_enom_settings_page() {
        // This hook needs to exist for rendering the page,
        // but we don't show it in the menu as it will be under SKYHS
        add_options_page(
            'Enom Settings',
            'Enom Settings',
            'manage_options',
            'skyhshoso-enom-settings',
            array( $this, 'skyhshoso_render_enom_settings_page' )
        );
    }

    public function skyhshoso_register_enom_settings() {
        // Register settings with proper sanitization callbacks.
        register_setting( 'skyhshoso_enom_settings_group', 'skyhshoso_enom_live_username', array(
            'sanitize_callback' => 'sanitize_text_field',
            'type'             => 'string',
        ) );

        register_setting( 'skyhshoso_enom_settings_group', 'skyhshoso_enom_live_password', array(
            'sanitize_callback' => 'sanitize_text_field',
            'type'             => 'string',
        ) );

        register_setting( 'skyhshoso_enom_settings_group', 'skyhshoso_enom_test_username', array(
            'sanitize_callback' => 'sanitize_text_field',
            'type'             => 'string',
        ) );

        register_setting( 'skyhshoso_enom_settings_group', 'skyhshoso_enom_test_password', array(
            'sanitize_callback' => 'sanitize_text_field',
            'type'             => 'string',
        ) );

        register_setting( 'skyhshoso_enom_settings_group', 'skyhshoso_enom_mode', array(
            'sanitize_callback' => array( $this, 'sanitize_skyhshoso_enom_mode' ),
            'type'             => 'string',
        ) );
        register_setting( 'skyhshoso_enom_settings_group', 'skyhshoso_enom_price_markup', array(
            'sanitize_callback' => 'floatval',
            'type'             => 'number',
        ) );

        add_settings_section(
            'skyhshoso_enom_settings_section',
            'eNom Settings',
            array($this, 'skyhshoso_enom_settings_section_callback'),
            'skyhshoso-enom-settings'
        );

        add_settings_field(
            'skyhshoso_enom_default_nameservers',
            'Default Nameservers',
            array($this, 'skyhshoso_enom_default_nameservers_callback'),
            'skyhshoso-enom-settings',
            'skyhshoso_enom_settings_section'
        );
        add_settings_field(
    'skyhshoso_enom_price_markup',
    'Additional Price Per Domain($)',
    array($this, 'skyhshoso_enom_price_markup_callback'),
    'skyhshoso-enom-settings',
    'skyhshoso_enom_settings_section'
);

        register_setting('skyhshoso_enom_settings_group', 'skyhshoso_enom_default_nameservers', array($this, 'sanitize_nameservers'));
    }

    public function skyhshoso_enom_settings_section_callback() {
        echo '<p>' . esc_html__( 'Set your default nameservers for eNom domains.', 'skyhs-hosting-solution' ) . '</p>';
    }

    public function skyhshoso_enom_default_nameservers_callback() {
        $nameservers = get_option('skyhshoso_enom_default_nameservers', array('', '', '', ''));
        for ($i = 0; $i < 4; $i++) {
            echo "<input type='text' name='skyhshoso_enom_default_nameservers[]' value='" . esc_attr($nameservers[$i]) . "' /><br />";
        }
    }
    

    public function sanitize_nameservers($input) {
        $sanitized = array();
        foreach ($input as $nameserver) {
            $sanitized[] = sanitize_text_field($nameserver);
        }
        return $sanitized;
    }

    /**
     * Ensures the mode is either 'live' or 'test'. Defaults to 'live'.
     *
     * @param string $input Raw input value.
     * @return string Sanitized mode value.
     */
    public function sanitize_skyhshoso_enom_mode( $input ) {
        $allowed = array( 'live', 'test' );
        return in_array( $input, $allowed, true ) ? $input : 'live';
    }
    public function skyhshoso_enom_price_markup_callback() {
    $markup = get_option('skyhshoso_enom_price_markup', 0);
    echo "<input type='number' step='0.01' name='skyhshoso_enom_price_markup' value='" . esc_attr($markup) . "' /> USD";
}

    public function skyhshoso_render_enom_settings_page() {
        ?>
        <div class="skyhshoso-wizard-wrap">
            <div class="skyhshoso-wizard-header">
                <h1><?php esc_html_e( 'eNom Settings', 'skyhs-hosting-solution' ); ?></h1>
                <p><?php esc_html_e( 'Configure your eNom domain registrar API credentials and pricing.', 'skyhs-hosting-solution' ); ?></p>
            </div>
            <div class="skyhshoso-wizard-content">
                <form method="post" action="options.php">
                    <?php settings_fields( 'skyhshoso_enom_settings_group' ); ?>
                    <?php do_settings_sections( 'skyhshoso-enom-settings' ); ?>

                    <div class="skyhshoso-wizard-form-group">
                        <label><?php esc_html_e( 'Mode', 'skyhs-hosting-solution' ); ?></label>
                        <select name="skyhshoso_enom_mode">
                            <option value="live" <?php selected( get_option( 'skyhshoso_enom_mode' ), 'live' ); ?>><?php esc_html_e( 'Live', 'skyhs-hosting-solution' ); ?></option>
                            <option value="test" <?php selected( get_option( 'skyhshoso_enom_mode' ), 'test' ); ?>><?php esc_html_e( 'Test', 'skyhs-hosting-solution' ); ?></option>
                        </select>
                    </div>

                    <div class="skyhshoso-wizard-form-group">
                        <label><?php esc_html_e( 'Live Username', 'skyhs-hosting-solution' ); ?></label>
                        <input type="password" name="skyhshoso_enom_live_username" value="<?php echo esc_attr( get_option( 'skyhshoso_enom_live_username' ) ); ?>" />
                    </div>

                    <div class="skyhshoso-wizard-form-group">
                        <label><?php esc_html_e( 'Live Password', 'skyhs-hosting-solution' ); ?></label>
                        <input type="password" name="skyhshoso_enom_live_password" value="<?php echo esc_attr( get_option( 'skyhshoso_enom_live_password' ) ); ?>" />
                    </div>

                    <div class="skyhshoso-wizard-form-group">
                        <label><?php esc_html_e( 'Test Username', 'skyhs-hosting-solution' ); ?></label>
                        <input type="password" name="skyhshoso_enom_test_username" value="<?php echo esc_attr( get_option( 'skyhshoso_enom_test_username' ) ); ?>" />
                    </div>

                    <div class="skyhshoso-wizard-form-group">
                        <label><?php esc_html_e( 'Test Password', 'skyhs-hosting-solution' ); ?></label>
                        <input type="password" name="skyhshoso_enom_test_password" value="<?php echo esc_attr( get_option( 'skyhshoso_enom_test_password' ) ); ?>" />
                    </div>

                    <div class="skyhshoso-wizard-actions">
                        <div></div>
                        <div>
                            <button type="submit" class="skyhshoso-wizard-btn skyhshoso-wizard-btn-primary"><?php esc_html_e( 'Save Settings', 'skyhs-hosting-solution' ); ?></button>
                        </div>
                    </div>
                </form>

                <hr style="margin:30px 0;" />

                <div class="skyhshoso-wizard-form-group">
                    <h3><?php esc_html_e( 'Clear Synced Domain Cache', 'skyhs-hosting-solution' ); ?></h3>
                    <p style="font-size:13px;color:#6b7280;margin:0 0 12px 0;">
                        <?php esc_html_e( 'Delete all cached domain data from the local database. This does not affect your Enom account — data will be re-fetched on next sync.', 'skyhs-hosting-solution' ); ?>
                    </p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php esc_attr_e( 'Delete all cached Enom domain data? This cannot be undone.', 'skyhs-hosting-solution' ); ?>');">
                        <?php wp_nonce_field( 'skyhshoso_enom_clear_cache' ); ?>
                        <input type="hidden" name="action" value="skyhshoso_enom_clear_cache" />
                        <button type="submit" class="skyhshoso-wizard-btn" style="background:#dc2626;border-color:#dc2626;color:#fff;">
                            <?php esc_html_e( 'Delete All Cached Domains', 'skyhs-hosting-solution' ); ?>
                        </button>
                    </form>
                </div>

                <?php if ( isset( $_GET['cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                    <div class="skyhshoso-wizard-notice success" style="display:block;">
                        <?php esc_html_e( 'Domain cache cleared successfully.', 'skyhs-hosting-solution' ); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function skyhshoso_get_enom_credentials() {
        $mode = get_option( 'skyhshoso_enom_mode', 'live' );
        if ( $mode === 'live' ) {
            return array(
                'username' => get_option( 'skyhshoso_enom_live_username' ),
                'password' => get_option( 'skyhshoso_enom_live_password' ),
                'url' => 'https://reseller.enom.com/interface.asp',
            );
        } else {
            return array(
                'username' => get_option( 'skyhshoso_enom_test_username' ),
                'password' => get_option( 'skyhshoso_enom_test_password' ),
                'url' => 'https://resellertest.enom.com/interface.asp',
            );
        }
    }

    private static $last_api_call_time = 0;

    public function check_domain($sld, $tld) {
        $credentials = $this->skyhshoso_get_enom_credentials();

        if ( empty( $credentials['username'] ) || empty( $credentials['password'] ) ) {
            return array( 'error' => 'eNom API credentials are not configured. Go to SKYHS > Enom Settings to set them up.' );
        }

        $elapsed = ( microtime( true ) - self::$last_api_call_time );
        if ( $elapsed < 1.0 && self::$last_api_call_time > 0 ) {
            $sleep_usec = (int) ( ( 1.0 - $elapsed ) * 1000000 );
            usleep( $sleep_usec );
        }

        $query = http_build_query([
            'command' => 'check',
            'sld' => $sld,
            'tld' => $tld,
            'uid' => $credentials['username'],
            'pw' => $credentials['password'],
            'responsetype' => 'xml',
            'version' => '2',
            'includeprice' => '1',
            'includeproperties' => '1'
        ]);

        $url = "{$credentials['url']}?$query";

        self::$last_api_call_time = microtime( true );
        $response = wp_remote_get($url, array('timeout' => 5));

        if (is_wp_error($response)) {
            return ['error' => 'Failed to connect to eNom API: ' . $response->get_error_message()];
        }

        $response_code = wp_remote_retrieve_response_code( $response );

        $body = wp_remote_retrieve_body($response);
        // Debug: Log the response body
        
        $xml = simplexml_load_string($body);

        if ($xml === false) {
            return ['error' => 'Failed to parse XML response'];
        }

        if (isset($xml->ErrCount) && (int)$xml->ErrCount > 0) {
            $error_msg = isset($xml->errors->Err1) ? (string)$xml->errors->Err1 : 'Unknown error';
            return ['error' => 'API Error: ' . $error_msg];
        }

        // Check if Domains element exists
        if (!isset($xml->Domains) || !isset($xml->Domains->Domain)) {
            return ['error' => 'Unexpected API response structure'];
        }

        $domain_info = $xml->Domains->Domain;
         $markup = floatval(get_option('skyhshoso_enom_price_markup', 0));

        // Create the result array with safe defaults
        $result = [
            'name' => $sld . '.' . $tld,
            'available' => false,
            'status' => 'Unknown',
            'is_premium' => false,
            'registration_price' => 0,
            'renewal_price' => 0,
            'transfer_price' => 0,
            'min_registration_years' => 1,
            'max_registration_years' => 10,
        ];
        
        // Only fill in values if they exist in the XML
        if (isset($domain_info->Name)) {
            $result['name'] = (string)$domain_info->Name;
        }
        
        if (isset($domain_info->RRPCode)) {
            $result['available'] = ((int)$domain_info->RRPCode === 210);
        }
        
        if (isset($domain_info->RRPText)) {
            $result['status'] = (string)$domain_info->RRPText;
        }
        
        if (isset($domain_info->IsPremium)) {
            $result['is_premium'] = ((string)$domain_info->IsPremium === 'True');
        }
        
        if (isset($domain_info->Prices)) {
            if (isset($domain_info->Prices->Registration)) {
                $result['registration_price'] = (float)$domain_info->Prices->Registration + $markup;
            }
            
            if (isset($domain_info->Prices->Renewal)) {
                $result['renewal_price'] = (float)$domain_info->Prices->Renewal + $markup;
            }
            
            if (isset($domain_info->Prices->Transfer)) {
                $result['transfer_price'] = (float)$domain_info->Prices->Transfer + $markup;
            }
        }
        
        if (isset($domain_info->Properties)) {
            if (isset($domain_info->Properties->MinRegYear)) {
                $result['min_registration_years'] = (int)$domain_info->Properties->MinRegYear;
            }
            
            if (isset($domain_info->Properties->MaxRegYear)) {
                $result['max_registration_years'] = (int)$domain_info->Properties->MaxRegYear;
            }
        }
        
        return $result;
    }

    public function check_transfer_domain($sld, $tld) {
        $result = $this->check_domain($sld, $tld);

        if (isset($result['error'])) {
            return $result;
        }

        if ($result['available']) {
            return array(
                'error' => 'This domain is not registered yet and cannot be transferred. Please register it instead.',
                'name' => $result['name'],
                'transferable' => false,
                'registration_price' => $result['registration_price'],
            );
        }

        if ($result['transfer_price'] <= 0) {
            return array(
                'error' => 'Transfer is not available for this domain.',
                'name' => $result['name'],
                'transferable' => false,
            );
        }

        return array(
            'name' => $result['name'],
            'transferable' => true,
            'transfer_price' => $result['transfer_price'],
            'renewal_price' => $result['renewal_price'],
            'is_premium' => $result['is_premium'],
        );
    }

    public function initiate_transfer_domain($sld, $tld, $auth_code, $num_years, $client_details) {
        $credentials = $this->skyhshoso_get_enom_credentials();

        $params = array(
            'command' => 'TP_CreateOrder',
            'uid' => $credentials['username'],
            'pw' => $credentials['password'],
            'OrderType' => 'Autoverification',
            'DomainCount' => 1,
            'SLD1' => $sld,
            'TLD1' => $tld,
            'AuthInfo1' => $auth_code,
            'ResponseType' => 'XML',
        );

        if (!empty($client_details)) {
            $params['RegistrantFirstName'] = $client_details['first_name'];
            $params['RegistrantLastName'] = $client_details['last_name'];
            $params['RegistrantOrganizationName'] = $client_details['organization'];
            $params['RegistrantAddress1'] = $client_details['address1'];
            $params['RegistrantCity'] = $client_details['city'];
            $params['RegistrantStateProvince'] = $client_details['state_province'];
            $params['RegistrantPostalCode'] = $client_details['postal_code'];
            $params['RegistrantCountry'] = $client_details['country'];
            $params['RegistrantEmailAddress'] = $client_details['email'];
            $params['RegistrantPhone'] = $client_details['phone'];
        }

        $response = wp_remote_post($credentials['url'], array(
            'body' => $params,
            'timeout' => 60,
        ));

        if (is_wp_error($response)) {
            throw new Exception("API request failed: " . esc_html($response->get_error_message()));
        }

        $body = wp_remote_retrieve_body($response);
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);

        if (!$xml) {
            throw new Exception("Failed to parse API response");
        }

        if ((int)$xml->ErrCount > 0) {
            $err_msg = isset($xml->errors->Err1) ? (string)$xml->errors->Err1 : 'Unknown error';
            throw new Exception("API Error: " . esc_html($err_msg));
        }

        return array(
            'TransferOrderID' => isset($xml->transferorder->transferorderid) ? (string)$xml->transferorder->transferorderid : '',
            'TotalCharged' => isset($xml->transferorder->authamount) ? (string)$xml->transferorder->authamount : '',
            'Status' => isset($xml->transferorder->transferorderdetail->statusdesc) ? (string)$xml->transferorder->transferorderdetail->statusdesc : 'Pending',
        );
    }

    public function skyhshoso_ajax_check_transfer() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'skyhshoso_check_skyhshoso_domain_nonce')) {
            wp_send_json_error(array('message' => esc_html__('Security check failed.', 'skyhs-hosting-solution')));
            wp_die();
        }

        if (!isset($_POST['skyhshoso_domain'])) {
            wp_send_json_error(array('message' => esc_html__('Missing domain parameter.', 'skyhs-hosting-solution')));
            wp_die();
        }

        $domain = sanitize_text_field(wp_unslash($_POST['skyhshoso_domain']));
        $parts = explode('.', $domain, 2);

        if (count($parts) !== 2) {
            wp_send_json_error(array('message' => esc_html__('Invalid domain format.', 'skyhs-hosting-solution')));
            wp_die();
        }

        $result = $this->check_transfer_domain($parts[0], $parts[1]);
        wp_send_json($result);
    }

    public function skyhshoso_ajax_check_domain() {
        // Verify nonce for security
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'skyhshoso_check_skyhshoso_domain_nonce' ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Security check failed. Please refresh the page and try again.', 'skyhs-hosting-solution' ) ) );
            wp_die();
        }
        
        if ( ! isset( $_POST['skyhshoso_domain'] ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Missing domain parameter.', 'skyhs-hosting-solution' ) ) );
            wp_die();
        }
        
        $domain = isset( $_POST['skyhshoso_domain'] ) ? sanitize_text_field( wp_unslash( $_POST['skyhshoso_domain'] ) ) : '';
        
        $parts = explode('.', $domain, 2);
        
        if (count($parts) === 2) {
            $sld = $parts[0];
            $tld = $parts[1];
            $result = $this->check_domain($sld, $tld);
            
            // Skip suggestions for now to simplify debugging
            // We'll focus on getting the main domain check working first
            $result['suggestions'] = [];
            
            wp_send_json($result);
        } else {
            wp_send_json(['error' => 'Invalid domain format']);
        }
    }

    public function update_nameservers($domain, $nameservers) {
        $credentials = $this->skyhshoso_get_enom_credentials();
        $domain_parts = explode('.', $domain);
        $sld = $domain_parts[count($domain_parts) - 2];
        $tld = $domain_parts[count($domain_parts) - 1];

        $data = array(
            'command' => 'modifyns',
            'uid' => $credentials['username'],
            'pw' => $credentials['password'],
            'sld' => $sld,
            'tld' => $tld,
            'responsetype' => 'xml'
        );

        for ($i = 0; $i < 4; $i++) {
            if (!empty($nameservers[$i])) {
                $data["ns" . ($i + 1)] = $nameservers[$i];
            }
        }

        $response = wp_remote_post($credentials['url'], array(
            'body' => $data
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        } else {
            $body = wp_remote_retrieve_body($response);
            $xml = simplexml_load_string($body);
            if ($xml->ErrCount > 0) {
                return array(
                    'success' => false,
                    'message' => (string)$xml->errors->error[0]
                );
            } else {
                return array(
                    'success' => true,
                    'message' => "Nameservers updated successfully for domain: $domain"
                );
            }
        }
    }


    public function update_nameservers_after_purchase($domain, $nameservers) {
        $result = $this->update_nameservers($domain, $nameservers);

        if (!$result['success']) {
        } else {
        }

        return $result;
    }

    public function disable_auto_renew($sld, $tld) {
        $credentials = $this->skyhshoso_get_enom_credentials();
        if (empty($credentials['username']) || empty($credentials['password'])) {
            return false;
        }
        $query = http_build_query([
            'command' => 'SetRenew',
            'uid' => $credentials['username'],
            'pw' => $credentials['password'],
            'SLD' => $sld,
            'TLD' => $tld,
            'renewflag' => '0',
            'ResponseType' => 'XML'
        ]);
        $response = wp_remote_get($credentials['url'] . '?' . $query, array('timeout' => 30));
        if (is_wp_error($response)) {
            return false;
        }
        return true;
    }

    public function purchase_domain($sld, $tld, $num_years, $client_details) {
        $credentials = $this->skyhshoso_get_enom_credentials();
        $purchase = new SkyHSHOSO_Enom_Domain_Purchase($credentials['username'], $credentials['password']);
        $purchase->setApiEndpoint($credentials['url']);
        $purchase->setClientDetails(
            $client_details['first_name'],
            $client_details['last_name'],
            $client_details['organization'],
            $client_details['address1'],
            $client_details['city'],
            $client_details['state_province'],
            $client_details['postal_code'],
            $client_details['country'],
            $client_details['email'],
            $client_details['phone']
        );
        $purchase->setDomainDetails($sld, $tld, $num_years);

        try {
            $result = $purchase->purchaseDomain();
            if ($result) {
                return $result;
            } else {
                throw new Exception("Domain purchase failed. No specific error returned.");
            }
        } catch (Exception $e) {
            return false;
        }
    }

    public function renew_domain($domain, $num_years = 1) {
        $credentials = $this->skyhshoso_get_enom_credentials();
        $domain_parts = explode('.', $domain, 2);
        
        if (count($domain_parts) !== 2) {
            return false;
        }
        
        $sld = $domain_parts[0];
        $tld = $domain_parts[1];
        
        try {
            $query = http_build_query([
                'command' => 'Extend',
                'uid' => $credentials['username'],
                'pw' => $credentials['password'],
                'SLD' => $sld,
                'TLD' => $tld,
                'NumYears' => $num_years,
                'ResponseType' => 'XML'
            ]);
            
            $response = wp_remote_get($credentials['url'] . '?' . $query);
            
            if (is_wp_error($response)) {
                throw new Exception("API request failed: " . esc_html( $response->get_error_message() ) );
            }
            
            $body = wp_remote_retrieve_body($response);
            $xml = simplexml_load_string($body);
            
            if (!$xml) {
                throw new Exception("Failed to parse API response");
            }
            
            if ((string)$xml->ErrCount > 0) {
                throw new Exception("API error: " . (string)$xml->errors->Err1);
            }
            
            if ((string)$xml->RRPCode !== '200') {
                throw new Exception("Renewal failed. RRP Code: " . (string)$xml->RRPCode . ", RRP Text: " . (string)$xml->RRPText);
            }
            
            $this->disable_auto_renew($sld, $tld);
            
            return true;
            
        } catch (Exception $e) {
            return false;
        }
    }
    
    
    
    public function skyhshoso_ajax_update_nameservers() {
    check_ajax_referer('skyhshoso_dns_editor_nonce', 'nonce');

    $domain = isset( $_POST['skyhshoso_domain'] ) ? sanitize_text_field( wp_unslash( $_POST['skyhshoso_domain'] ) ) : '';
    
    // Find domain ID by meta query
    $domain_posts = get_posts( array(
        'post_type'      => 'skyhshoso_domain',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => array(
            array(
                'key'     => 'skyhshoso_domain_name',
                'value'   => $domain,
                'compare' => '=',
            ),
        ),
    ) );
    $domain_id = ! empty( $domain_posts ) ? intval( reset( $domain_posts ) ) : 0;

    if ( ! SkyHSHOSO_Account_Domains::can_manage_domain($domain_id) ) {
        wp_send_json_error(__('Unauthorized access.', 'skyhs-hosting-solution'));
    }

    $nameservers = isset( $_POST['nameservers'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['nameservers'] ) ) : array();

    $result = $this->update_nameservers($domain, $nameservers);

    if ($result['success']) {
        wp_send_json_success($result['message']);
    } else {
        wp_send_json_error($result['message']);
    }
}

public function skyhshoso_ajax_update_dns_record() {
    check_ajax_referer('skyhshoso_dns_editor_nonce', 'nonce');
    $domain     = isset( $_POST['skyhshoso_domain'] ) ? sanitize_text_field( wp_unslash( $_POST['skyhshoso_domain'] ) ) : '';
    
    // Find domain ID by meta query
    $domain_posts = get_posts( array(
        'post_type'      => 'skyhshoso_domain',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => array(
            array(
                'key'     => 'skyhshoso_domain_name',
                'value'   => $domain,
                'compare' => '=',
            ),
        ),
    ) );
    $domain_id = ! empty( $domain_posts ) ? intval( reset( $domain_posts ) ) : 0;

    if ( ! SkyHSHOSO_Account_Domains::can_manage_domain($domain_id) ) {
        wp_send_json_error(array('message' => __('Unauthorized access.', 'skyhs-hosting-solution')));
    }
    $dns_action = isset( $_POST['dns_action'] ) ? sanitize_text_field( wp_unslash( $_POST['dns_action'] ) ) : '';
    $record_data = isset( $_POST['record_data'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['record_data'] ) ) : array();
    // Sanitize record data
    foreach ($record_data as $key => $value) {
        $record_data[$key] = sanitize_text_field($value);
    }
    // Fetch current records
    $current_records = $this->skyhshoso_enom_api_request('GetHosts', $domain);
    if (isset($current_records['error'])) {
        wp_send_json_error(array('message' => $current_records['error']));
        return;
    }
    $host_records = array();
    $record_index = 1;
    // Process existing records
    if (isset($current_records->host)) {
        foreach ($current_records->host as $record) {
            // Check if this is the record we're updating or deleting
            if ($dns_action !== 'add' &&
                $record->type == $record_data['record_type'] &&
                $record->name == $record_data['old_host_name'] &&
                $record->address == $record_data['old_address']) {
                // If it's the record we're updating or deleting, skip adding it
                continue;
            } else {
                // Add existing records to $host_records array
                $host_records["HostName{$record_index}"] = (string)$record->name;
                $host_records["RecordType{$record_index}"] = (string)$record->type;
                $host_records["Address{$record_index}"] = (string)$record->address;
                $host_records["MXPref{$record_index}"] = isset($record->mxpref) ? (string)$record->mxpref : '';
                $record_index++;
            }
        }
    }
    // Add or update the new record
    if ($dns_action !== 'delete') {
        $host_records["HostName{$record_index}"] = $record_data['host_name'];
        $host_records["RecordType{$record_index}"] = $record_data['record_type'];
        $host_records["Address{$record_index}"] = $record_data['address'];
        
        if ($record_data['record_type'] === 'MX') {
            $host_records["MXPref{$record_index}"] = $record_data['mx_pref'];
        } else {
            $host_records["MXPref{$record_index}"] = '';
        }
    }
    // Make the API call to set hosts
    $result = $this->skyhshoso_enom_api_request('SetHosts', $domain, $host_records);
   
    if (isset($result['error'])) {
        wp_send_json_error(array('message' => $result['error']));
    } elseif (isset($result->ErrCount) && (int)$result->ErrCount > 0) {
        wp_send_json_error(array('message' => (string)$result->errors->Err1));
    } else {
        wp_send_json_success(array('message' => 'DNS records updated successfully.', 'debug' => wp_json_encode($result)));
    }
}

public function skyhshoso_enom_api_request($command, $domain_name, $additional_params = []) {
    $credentials = $this->skyhshoso_get_enom_credentials();
    $query = array_merge([
        'command' => $command,
        'uid' => $credentials['username'],
        'pw' => $credentials['password'],
        'sld' => explode('.', $domain_name)[0],
        'tld' => explode('.', $domain_name)[1],
        'responsetype' => 'xml'
    ], $additional_params);

    $url = add_query_arg($query, $credentials['url']);
    $response = wp_remote_get($url);
    if (is_wp_error($response)) {
        return ['error' => $response->get_error_message()];
    }
    $body = wp_remote_retrieve_body($response);
    $xml = simplexml_load_string($body);
    if ($xml === false) {
        return ['error' => 'Error parsing XML response'];
    }
    return $xml;
}

}

// Initialize the Enom_Integration class
function SkyHSHOSO_Enom_Integration() {
    return SkyHSHOSO_Enom_Integration::instance();
}

SkyHSHOSO_Enom_Integration();

class SkyHSHOSO_Enom_Domain_Purchase {
    private $apiEndpoint;
    private $accountId;
    private $apiToken;
    private $clientDetails = [];
    private $domainDetails = [];

    public function __construct($accountId, $apiToken) {
        $this->accountId = $accountId;
        $this->apiToken = $apiToken;
    }

    public function setApiEndpoint($endpoint) {
        $this->apiEndpoint = $endpoint;
    }

    public function setClientDetails($firstName, $lastName, $organization, $address1, $city, $stateProvince, $postalCode, $country, $email, $phone) {
        $this->clientDetails = [
            'RegistrantFirstName' => $firstName,
            'RegistrantLastName' => $lastName,
            'RegistrantOrganizationName' => $organization,
            'RegistrantAddress1' => $address1,
            'RegistrantCity' => $city,
            'RegistrantStateProvince' => $stateProvince,
            'RegistrantPostalCode' => $postalCode,
            'RegistrantCountry' => $country,
            'RegistrantEmailAddress' => $email,
            'RegistrantPhone' => $phone
        ];
    }

    public function setDomainDetails($sld, $tld, $numYears = 1) {
        $this->domainDetails = [
            'SLD' => $sld,
            'TLD' => $tld,
            'NumYears' => $numYears
        ];
    }

    public function purchaseDomain() {
        $params = array_merge(
            [
                'Command' => 'Purchase',
                'UID' => $this->accountId,
                'PW' => $this->apiToken,
                'EndUserIP' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
                'UseDNS' => 'default',
                'ResponseType' => 'xml'
            ],
            $this->clientDetails,
            $this->domainDetails
        );

        $url = $this->apiEndpoint . '?' . http_build_query($params);

        $response = wp_remote_get($url, array(
            'timeout' => 60 // Increase timeout to 60 seconds
        ));
        
        if (is_wp_error($response)) {
            throw new Exception("API request failed: " . esc_html( $response->get_error_message() ) );
        }

        $body = wp_remote_retrieve_body($response);
        $xml = simplexml_load_string($body);

        if (!$xml) {
            throw new Exception("Failed to parse API response");
        }

        if ((int)$xml->ErrCount > 0) {
            throw new Exception( esc_html( (string) $xml->errors->Err1 ) );
        }

        return [
            'OrderID' => (string)$xml->OrderID,
            'TotalCharged' => (string)$xml->TotalCharged,
            'RegistryExpDate' => (string)$xml->RegistryExpDate
        ];
    }
    
}
