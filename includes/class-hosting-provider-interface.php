<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * The standard contract for all Server Drivers (WHM, HestiaCP, WordOps, etc.)
 */
interface SkyHSHOSO_Hosting_Driver_Interface {
    
    // 1. Connection & Validation
    public function test_connection();

    // 2. Account Lifecycle
    public function create_account($domain, $username, $password, $email, $package_name);
    public function suspend_account($username, $reason = '');
    public function unsuspend_account($username);
    public function terminate_account($username);
    public function change_password($username, $new_password);

    // 3. Metrics & Stats
    public function get_account_stats($username);
    public function get_account_summary($username); // <-- Notice it ends in a semicolon, no curly braces!

    // 4. WordPress & SSO Handoff
    public function generate_sso_url($username, $target = 'panel');
    public function scan_for_wordpress($username, $domain_doc_roots);
	public function get_packages(); // <-- ADD THIS LINE
}