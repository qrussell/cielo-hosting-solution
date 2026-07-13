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
     */
    private function hestia_api_request($command, $args = [], $expect_json = false) {
        // Build standard payload
        $post_data = [
            'hash' => $this->user . ':' . $this->token, // Hestia allows user:pass or KeyID:SecretKey in the hash field
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

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Hestia returns '0' on success, other numbers on failure (unless we asked for JSON output)
        if ($expect_json) {
            return json_decode($response, true);
        }

        return [
            'success' => ($response === '0' || $response === 0),
            'code' => $response
        ];
    }

    public function test_connection() {
        // v-list-sys-info returns 0 if authenticated properly
        $res = $this->hestia_api_request('v-list-sys-info');
        if ($res['success']) {
            return true;
        }
        return new WP_Error('hestia_connection_failed', 'Could not connect to HestiaCP. Check your Access Keys and ensure port 8083 is open.');
    }

    public function suspend_account($username, $reason = '') {
        $res = $this->hestia_api_request('v-suspend-user', [$username]);
        if ($res['success']) {
            return true;
        }
        return new WP_Error('hestia_error', 'Failed to suspend HestiaCP account. Error Code: ' . $res['code']);
    }

    public function unsuspend_account($username) {
        $res = $this->hestia_api_request('v-unsuspend-user', [$username]);
        if ($res['success']) {
            return true;
        }
        return new WP_Error('hestia_error', 'Failed to unsuspend HestiaCP account. Error Code: ' . $res['code']);
    }

    public function terminate_account($username) {
        $res = $this->hestia_api_request('v-delete-user', [$username]);
        if ($res['success']) {
            return true;
        }
        return new WP_Error('hestia_error', 'Failed to terminate HestiaCP account. Error Code: ' . $res['code']);
    }

    public function get_account_summary($username) {
        // Passing 'json' as the second arg tells Hestia to format the output instead of standard CLI text
        $res = $this->hestia_api_request('v-list-user', [$username, 'json'], true);
        
        if (is_array($res) && isset($res[$username])) {
            $data = $res[$username];
            
            // Standardize the response so it behaves exactly like WHM for our frontend
            return [
                'suspended' => ($data['SUSPENDED'] === 'yes' ? 1 : 0),
                'email' => $data['CONTACT'],
                'disk_used' => $data['U_DISK'],
                'bandwidth_used' => $data['U_BANDWIDTH']
            ];
        }
        
        return new WP_Error('hestia_error', 'Could not locate account on HestiaCP server.');
    }

    // --- STUBS FOR FUTURE FEATURES ---
    public function create_account($domain, $username, $password, $email, $package_name) { return true; }
    public function change_password($username, $new_password) { return true; }
    public function get_account_stats($username) { return true; }
    public function generate_sso_url($username, $target = 'panel') { return true; }
    public function scan_for_wordpress($username, $domain_doc_roots) { return true; }
}