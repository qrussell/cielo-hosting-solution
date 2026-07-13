<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SkyHSHOSO_WHM_Driver implements SkyHSHOSO_Hosting_Driver_Interface {
    private $host;
    private $user;
    private $token;
    private $whm_host_domain;

    public function __construct($host, $user, $token) {
        $this->host = $host;
        $this->user = $user;
        $this->token = $token;
        // Clean the host to just the domain/IP
        $this->whm_host_domain = preg_replace('/:\d+$/', '', parse_url($host, PHP_URL_HOST) ?: $host);
    }

    /**
     * Core Private Method for WHM Root API calls
     */
    private function whm_api_request($endpoint, $params = []) {
        $query = http_build_query($params);
        $url = "https://{$this->whm_host_domain}:2087/json-api/{$endpoint}?api.version=1&{$query}";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: whm root:' . $this->token]);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }

    public function test_connection() {
        $res = $this->whm_api_request('applist');
        if (isset($res['metadata']['result']) && $res['metadata']['result'] == 1) {
            return true;
        }
        return new WP_Error('whm_connection_failed', 'Could not connect to WHM. Check your API Token.');
    }

    public function suspend_account($username, $reason = '') {
        $res = $this->whm_api_request('suspendacct', [
            'user' => $username, 
            'reason' => $reason
        ]);
        
        if (isset($res['metadata']['result']) && $res['metadata']['result'] == 1) {
            return true;
        }
        return new WP_Error('whm_error', $res['metadata']['reason'] ?? 'Failed to suspend account.');
    }

    public function unsuspend_account($username) {
        $res = $this->whm_api_request('unsuspendacct', ['user' => $username]);
        
        if (isset($res['metadata']['result']) && $res['metadata']['result'] == 1) {
            return true;
        }
        return new WP_Error('whm_error', $res['metadata']['reason'] ?? 'Failed to unsuspend account.');
    }

    public function terminate_account($username) {
        $res = $this->whm_api_request('removeacct', ['user' => $username]);
        
        if (isset($res['metadata']['result']) && $res['metadata']['result'] == 1) {
            return true;
        }
        return new WP_Error('whm_error', $res['metadata']['reason'] ?? 'Failed to terminate account.');
    }

    public function get_account_summary($username) {
        $res = $this->whm_api_request('accountsummary', ['user' => $username]);
        
        if (isset($res['metadata']['result']) && $res['metadata']['result'] == 1) {
            // Return the array of account data
            return $res['data']['acct'][0]; 
        }
        return new WP_Error('whm_error', $res['metadata']['reason'] ?? 'Could not locate account on server.');
    }

    // --- STUBS FOR THE REST OF THE INTERFACE ---
    // We will port the rest of the WP Toolkit & UAPI calls into these later
    public function create_account($domain, $username, $password, $email, $package_name) { return true; }
    public function change_password($username, $new_password) { return true; }
    public function get_account_stats($username) { return true; }
    public function generate_sso_url($username, $target = 'panel') { return true; }
    public function scan_for_wordpress($username, $domain_doc_roots) { return true; }
}