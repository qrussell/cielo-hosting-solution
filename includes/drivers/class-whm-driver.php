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

    // --- 1. CONNECTION ---
    public function test_connection() {
        $res = $this->whm_api_request('applist');
        if (isset($res['metadata']['result']) && $res['metadata']['result'] == 1) {
            return true;
        }
        return new WP_Error('whm_connection_failed', 'Could not connect to WHM. Check your API Token.');
    }

    // --- 2. LIFECYCLE MANAGEMENT ---
    public function suspend_account($username, $reason = '') {
        $res = $this->whm_api_request('suspendacct', ['user' => $username, 'reason' => $reason]);
        if (isset($res['metadata']['result']) && $res['metadata']['result'] == 1) { return true; }
        return new WP_Error('whm_error', $res['metadata']['reason'] ?? 'Failed to suspend account.');
    }

    public function unsuspend_account($username) {
        $res = $this->whm_api_request('unsuspendacct', ['user' => $username]);
        if (isset($res['metadata']['result']) && $res['metadata']['result'] == 1) { return true; }
        return new WP_Error('whm_error', $res['metadata']['reason'] ?? 'Failed to unsuspend account.');
    }

    public function terminate_account($username) {
        $res = $this->whm_api_request('removeacct', ['user' => $username]);
        if (isset($res['metadata']['result']) && $res['metadata']['result'] == 1) { return true; }
        return new WP_Error('whm_error', $res['metadata']['reason'] ?? 'Failed to terminate account.');
    }

    public function change_password($username, $new_password) {
        $res = $this->whm_api_request('passwd', ['user' => $username, 'password' => $new_password]);
        if (isset($res['metadata']['result']) && $res['metadata']['result'] == 1) { return true; }
        return new WP_Error('whm_error', $res['metadata']['reason'] ?? 'Failed to reset password.');
    }

    // --- 3. METRICS & STATS ---
    public function get_account_summary($username) {
        $res = $this->whm_api_request('accountsummary', ['user' => $username]);
        if (isset($res['metadata']['result']) && $res['metadata']['result'] == 1) {
            return $res['data']['acct'][0]; 
        }
        return new WP_Error('whm_error', $res['metadata']['reason'] ?? 'Could not locate account on server.');
    }

    public function get_account_stats($username) {
        $res = $this->whm_api_request('cpanel', [
            'cpanel.module'   => 'StatsBar',
            'cpanel.function' => 'get_stat_items',
            'cpanel.user'     => $username,
            'display'         => 'diskusage|sqldiskusage|mysqldbs|subdomains|addondomains'
        ]);

        $stats = [];
        if (isset($res['data']['result']['data']) && is_array($res['data']['result']['data'])) {
            foreach ($res['data']['result']['data'] as $item) {
                $stats[$item['id']] = [
                    'value'   => $item['value'],
                    'max'     => $item['max'],
                    'percent' => $item['percent'] ?? 0
                ];
            }
            return $stats;
        }

        return new WP_Error('whm_error', 'Failed to retrieve account stats from WHM.');
    }

    // --- 4. SSO AND WORDPRESS ---
    public function generate_sso_url($username, $target = 'panel') {
        $res = $this->whm_api_request('create_user_session', [
            'user' => $username,
            'service' => 'cpaneld' 
        ]);
        if (isset($res['data']['url'])) {
            return $res['data']['url'];
        }
        return new WP_Error('whm_error', 'Failed to generate SSO session link.');
    }

// --- 5. PROVISIONING & UPGRADES ---
    public function create_account($domain, $username, $password, $email, $package_name) {
        $res = $this->whm_api_request('createacct', [
            'domain' => $domain,
            'username' => $username,
            'password' => $password,
            'contactemail' => $email,
            'plan' => $package_name
        ]);
        
        if (isset($res['metadata']['result']) && $res['metadata']['result'] == 1) {
            return true;
        }
        return new WP_Error('whm_error', $res['metadata']['reason'] ?? 'Failed to create WHM account.');
    }

    public function change_package($username, $new_package) {
        $res = $this->whm_api_request('changepackage', [
            'user' => $username,
            'pkg'  => $new_package
        ]);
        
        if (isset($res['metadata']['result']) && $res['metadata']['result'] == 1) {
            return true;
        }
        return new WP_Error('whm_error', $res['metadata']['reason'] ?? 'Failed to upgrade WHM package.');
    }
    public function scan_for_wordpress($username, $domain_doc_roots) { return true; }
}