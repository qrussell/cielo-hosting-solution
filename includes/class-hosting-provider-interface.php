<?php
/**
 * Hosting Provider Interface
 *
 * The blueprint that all Control Panel drivers (WHM, HestiaCP, WordOps) must follow.
 * Note: Interfaces can only define the function signature ending in a semicolon (;).
 *
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

interface SkyHSHOSO_Hosting_Driver_Interface {
    
    // 1. Connection
    public function test_connection();
    
    // 2. Lifecycle Management
    public function suspend_account($username, $reason = '');
    public function unsuspend_account($username);
    public function terminate_account($username);
    public function change_password($username, $new_password);
    
    // 3. Metrics & Stats
    public function get_account_summary($username);
    public function get_account_stats($username);
    
    // 4. SSO 
    public function generate_sso_url($username, $target = 'panel');
    
    // 5. Provisioning & Upgrades
    public function create_account($domain, $username, $password, $email, $package_name);
    public function change_package($username, $new_package);
    
    // 6. Packages
    public function get_packages();
    
    // 7. WordPress Sync
    public function scan_for_wordpress($username = 'root', $domain_doc_roots = array());
}