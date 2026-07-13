<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SkyHSHOSO_HestiaCP_Driver implements SkyHSHOSO_Hosting_Driver_Interface {
    private $host;
    private $user; // Hestia Access Key ID or Admin Username
    private $token; // Hestia Secret Key or Admin Password
    private $api_url;

    public function __construct($host, $user, $token) {
        $this->host = $host;
        $this->user = $user;
        $this->token = $token;
        
        // HestiaCP API runs on port 8083 by default
        $clean_host = preg_replace('/:\d+$/', '', parse_url($host, PHP_URL_HOST) ?: $host);
        $this->api_url = "https://{$clean_host}:8083/api/";
    }

    /**
     * Core Private Method for HestiaCP API calls
     * Added $return_raw to handle string returns (like SSO URLs)
     */
    private function hestia_api_request($command, $args = [], $expect_json = false, $return_raw = false) {
        $post_data = [
            'hash' => $this->user . ':' . $this->token,
            'returncode' => 'yes',
            'cmd' => $command
        ];

        // Map arguments (arg1, arg2, arg3...)
        foreach ($args as $index => $value) {
            $post_data['arg' . ($index + 1)] = $value;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));

        $response = trim(curl_exec($ch));
        curl_close($ch);

        if ($expect_json) {
            return json_decode($response, true);
        }
        
        if ($return_raw) {
            return $response;
        }

        return [
            'success' => ($response === '0' || $response === ''),
            'code' => $response
        ];
    }

    // --- 1. CONNECTION ---
    public function test_connection() {
        $res = $this->hestia_api_request('v-list-sys-info');
        if ($res['success']) { return true; }
        return new WP_Error('hestia_connection_failed', 'Could not connect to HestiaCP. Check your Access Keys and ensure port 8083 is open.');
    }

    // --- 2. LIFECYCLE MANAGEMENT ---
    public function suspend_account($username, $reason = '') {
        $res = $this->hestia_api_request('v-suspend-user', [$username]);
        if ($res['success']) { return true; }
        return new WP_Error('hestia_error', 'Failed to suspend HestiaCP account. Error Code: ' . $res['code']);
    }

    public function unsuspend_account($username) {
        $res = $this->hestia_api_request('v-unsuspend-user', [$username]);
        if ($res['success']) { return true; }
        return new WP_Error('hestia_error', 'Failed to unsuspend HestiaCP account. Error Code: ' . $res['code']);
    }

    public function terminate_account($username) {
        $res = $this->hestia_api_request('v-delete-user', [$username]);
        if ($res['success']) { return true; }
        return new WP_Error('hestia_error', 'Failed to terminate HestiaCP account. Error Code: ' . $res['code']);
    }

    public function change_password($username, $new_password) {
        $res = $this->hestia_api_request('v-change-user-password', [$username, $new_password]);
        if ($res['success']) { return true; }
        return new WP_Error('hestia_error', 'Failed to change password. Error Code: ' . $res['code']);
    }

    // --- 3. PROVISIONING (NEW) ---
    public function create_account($domain, $username, $password, $email, $package_name) {
        // Hestia requires creating the user first, then adding the web domain to that user
        $res_user = $this->hestia_api_request('v-add-user', [$username, $password, $email, $package_name, $username, $username]);
        if (!$res_user['success']) {
            return new WP_Error('hestia_error', 'Failed to create HestiaCP user. Error Code: ' . $res_user['code']);
        }

        // Now attach the domain to the newly created user
        $res_domain = $this->hestia_api_request('v-add-web-domain', [$username, $domain]);
        if (!$res_domain['success']) {
            return new WP_Error('hestia_error', 'User created, but failed to assign domain. Error Code: ' . $res_domain['code']);
        }

        return true;
    }

    // --- 4. METRICS & STATS (NEW) ---
    public function get_account_summary($username) {
        $res = $this->hestia_api_request('v-list-user', [$username, 'json'], true);
        
        if (is_array($res) && isset($res[$username])) {
            $data = $res[$username];
            return [
                'suspended' => ($data['SUSPENDED'] === 'yes' ? 1 : 0),
                'email' => $data['CONTACT']
            ];
        }
        return new WP_Error('hestia_error', 'Could not locate account on HestiaCP server.');
    }

    public function get_account_stats($username) {
        $res = $this->hestia_api_request('v-list-user', [$username, 'json'], true);
        
        if (is_array($res) && isset($res[$username])) {
            $data = $res[$username];
            
            // Format these exactly how the WHM driver did so the Dashboard JS parses them seamlessly
            return [
                'diskusage' => [
                    'value'   => $data['U_DISK'] . ' MB',
                    'max'     => ($data['DISK_QUOTA'] === 'unlimited') ? 'unlimited' : $data['DISK_QUOTA'] . ' MB',
                    'percent' => ($data['DISK_QUOTA'] > 0) ? round(($data['U_DISK'] / $data['DISK_QUOTA']) * 100, 2) : 0
                ],
                'bandwidth' => [
                    'value'   => $data['U_BANDWIDTH'] . ' MB',
                    'max'     => ($data['BANDWIDTH'] === 'unlimited') ? 'unlimited' : $data['BANDWIDTH'] . ' MB',
                    'percent' => ($data['BANDWIDTH'] > 0) ? round(($data['U_BANDWIDTH'] / $data['BANDWIDTH']) * 100, 2) : 0
                ],
                'mysqldbs' => [
                    'value'   => $data['U_DATABASES'],
                    'max'     => $data['DATABASES']
                ],
                'domains' => [
                    'value'   => $data['U_WEB_DOMAINS'],
                    'max'     => $data['WEB_DOMAINS']
                ]
            ];
        }
        return new WP_Error('hestia_error', 'Could not load stats from HestiaCP.');
    }

    // --- 5. SSO AND WORDPRESS (NEW) ---
    public function generate_sso_url($username, $target = 'panel') {
        // v-generate-user-login generates a temporary one-click login URL for the Hestia panel
        $url = $this->hestia_api_request('v-generate-user-login', [$username], false, true);
        
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }
        return new WP_Error('hestia_error', 'Failed to generate SSO session link.');
    }

    public function scan_for_wordpress($username, $domain_doc_roots) { 
        // We will build the CLI/bash scanner for this next when we abstract the WP Toolkit logic!
        return []; 
    }
	// --- 6. PACKAGES (NEW) ---
    public function get_packages() {
        // HestiaCP returns a JSON object where the keys are the package names
        $res = $this->hestia_api_request('v-list-user-packages', ['json'], true);
        
        if (is_array($res) && !isset($res['error'])) {
            return array_keys($res); 
        }
        return new WP_Error('hestia_error', 'Failed to fetch HestiaCP packages.');
    }
}