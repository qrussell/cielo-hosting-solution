<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class SkyHSHOSO_Account_Domains {
    public function __construct() {
        // We're no longer adding this to the WooCommerce account menu
        // add_filter('woocommerce_account_menu_items', array($this, 'add_domains_tab'), 30);
        // add_action('init', array($this, 'add_domains_endpoint'));
        // add_action('woocommerce_account_domains_endpoint', array($this, 'domains_content'));
        
        // Keep the view-domain endpoint for DNS management
        add_action('init', array($this, 'add_domains_endpoint'));
        add_action('woocommerce_account_view-domain_endpoint', array($this, 'view_domain_content'));
        
        // Register hooks for integration with the dashboard shortcode
        add_action('init', array($this, 'register_hooks'));
    }

    /**
     * Check if a user can manage a specific domain
     * 
     * @param int $domain_id The domain post ID
     * @param int $user_id The user ID (defaults to current user)
     * @return bool True if user can manage, false otherwise
     */
    public static function can_manage_domain($domain_id, $user_id = 0) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        if (!$user_id) {
            return false;
        }

        if (current_user_can('administrator')) {
            return true;
        }

        $domain = get_post($domain_id);
        if (!$domain || $domain->post_type !== 'skyhshoso_domain') {
            return false;
        }

        $domain_author_id = (int)$domain->post_author;

        if ($user_id === $domain_author_id) {
            return true;
        }

        // Check if user was invited by the domain author
        $invited_by = get_user_meta($user_id, 'skyhshoso_invited_by', true);
        $invited_by = is_array($invited_by) ? array_map('intval', $invited_by) : array();

        return in_array($domain_author_id, $invited_by, true);
    }
    
    /**
     * Register hooks for integration with the dashboard shortcode
     */
    public function register_hooks() {
        // Add hook for handling domain-related AJAX actions if needed
    }

    /**
     * Add domains endpoint for view-domain
     */
    public function add_domains_endpoint() {
        // We only need the view-domain endpoint for DNS management
        add_rewrite_endpoint('view-domain', EP_ROOT | EP_PAGES);
    }

    /**
     * Determine if the domains menu should be shown to the current user
     * 
     * @return bool True if user is admin or has domain products
     */
    public function should_show_domains_menu() {
        // Always show for admins
        if (current_user_can('administrator')) {
            return true;
        }

        // Check if user has any domain products
        $current_user_id = get_current_user_id();
        
        // Query for domain posts authored by the current user
        $args = array(
            'post_type' => 'skyhshoso_domain',
            'posts_per_page' => 1,
            'author' => $current_user_id,
        );
        
        $domain_query = new WP_Query($args);
        
        // Check if user was invited by others
        $invited_by = get_user_meta($current_user_id, 'skyhshoso_invited_by', true);
        $invited_by = is_array($invited_by) ? $invited_by : array();
        
        // If user has domains or was invited by others, show the menu
        return $domain_query->have_posts() || !empty($invited_by);
    }

    /**
     * Get domain data for the dashboard shortcode
     * 
     * @param int $user_id The user ID (defaults to current user)
     * @return array Domain data
     */
    public function get_user_domains_data($user_id = 0) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        $domains_data = array();
        
        // Query for domain posts authored by the user
        $args = array(
            'post_type' => 'skyhshoso_domain',
            'posts_per_page' => -1,
            'author' => $user_id,
        );
        
        $domain_query = new WP_Query($args);
        
        if ($domain_query->have_posts()) {
            while ($domain_query->have_posts()) {
                $domain_query->the_post();
                $domain_id = get_the_ID();
                $subscription_id = get_post_meta($domain_id, 'skyhshoso_subscription_id', true);
                
                // Get subscription status
                $subscription_status = '';
                
                if (!empty($subscription_id)) {
                    $subscription = skyhshoso_get_subscription($subscription_id);
                    if ($subscription) {
                        $subscription_status = $subscription->get_status();
                    }
                }
                
                $domains_data[] = array(
                    'id' => $domain_id,
                    'title' => get_the_title(),
                    'status' => $subscription_status,
                    'skyhshoso_subscription_id' => $subscription_id
                );
            }
        }
        
        wp_reset_postdata();
        
        return $domains_data;
    }

    /**
     * Get domains for the current user and invited users
     * 
     * @return array Domains grouped by owner
     */
    public function get_all_accessible_domains() {
        $current_user = wp_get_current_user();
        $domains_grouped = array();
        
        // If user is admin, get all domains
        if (current_user_can('administrator')) {
            $args = array(
                'post_type' => 'skyhshoso_domain',
                'posts_per_page' => -1,
            );
            $all_domains = new WP_Query($args);
            $domains_grouped['all'] = $this->process_domain_query($all_domains);
            wp_reset_postdata();
            return $domains_grouped;
        }

        // Get user's domains
        $args = array(
            'post_type' => 'skyhshoso_domain',
            'posts_per_page' => -1,
            'author' => $current_user->ID,
        );
        $your_domains = new WP_Query($args);
        $domains_grouped['your'] = $this->process_domain_query($your_domains);
        wp_reset_postdata();

        // Get domains from users who invited the current user
        $invited_by = get_user_meta($current_user->ID, 'skyhshoso_invited_by', true);
        $invited_by = is_array($invited_by) ? $invited_by : array();

        if (!empty($invited_by)) {
            foreach ($invited_by as $inviter_id) {
                $args = array(
                    'post_type' => 'skyhshoso_domain',
                    'posts_per_page' => -1,
                    'author' => $inviter_id,
                );
                $inviter_domains = new WP_Query($args);
                $inviter_info = get_userdata($inviter_id);
                $key = 'inviter_' . $inviter_id;
                $domains_grouped[$key] = array(
                    'title' => $inviter_info->display_name . '\'s Domains',
                    'domains' => $this->process_domain_query($inviter_domains)
                );
                wp_reset_postdata();
            }
        }
        
        return $domains_grouped;
    }
    
    /**
     * Process domain query into array of domain data
     * 
     * @param WP_Query $query The query object
     * @return array Domain data
     */
    private function process_domain_query($query) {
        $domains = array();
        
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $domain_id = get_the_ID();
                $subscription_id = get_post_meta($domain_id, 'skyhshoso_subscription_id', true);
                
                // Get subscription status
                $subscription_status = '';
                $status_class = '';
                
                if (!empty($subscription_id)) {
                    $subscription = skyhshoso_get_subscription($subscription_id);
                    if ($subscription) {
                        $subscription_status = $subscription->get_status();
                        // Set status class based on subscription status
                        if ($subscription_status === 'active') {
                            $status_class = 'status-active';
                        } elseif ($subscription_status === 'pending-cancel') {
                            $status_class = 'status-pending-cancel';
                        } else {
                            $status_class = 'status-onhold';
                        }
                    }
                }
                
                // Format the subscription status for display
                $display_status = !empty($subscription_status) ? 
                    ucfirst(str_replace('-', ' ', $subscription_status)) : 'Not active';
                
                $can_manage_dns = in_array($subscription_status, array('active', 'pending-cancel'));
                
                $domains[] = array(
                    'id' => $domain_id,
                    'title' => get_the_title(),
                    'status' => $subscription_status,
                    'display_status' => $display_status,
                    'status_class' => $status_class,
                    'can_manage_dns' => $can_manage_dns,
                    'skyhshoso_subscription_id' => $subscription_id
                );
            }
        }
        
        return $domains;
    }

    /**
     * View domain content with DNS management
     * Kept intact as requested
     */
    public function view_domain_content($domain_id) {
        $domain = get_post($domain_id);
        
        if (!$domain || $domain->post_type !== 'skyhshoso_domain') {
            echo '<p>' . esc_html__( 'Domain not found.', 'skyhs-hosting-solution' ) . '</p>';
            return;
        }

        $current_user_id = get_current_user_id();
        $domain_author_id = $domain->post_author;

        // Check if user is admin, domain author, or invitee
        $invited_by = get_user_meta($current_user_id, 'skyhshoso_invited_by', true);
        $invited_by = is_array($invited_by) ? $invited_by : array();
        
        if ($current_user_id != $domain_author_id 
            && !current_user_can('administrator')
            && !in_array($domain_author_id, $invited_by)) {
            echo '<p>' . esc_html__( 'You do not have permission to view this domain information.', 'skyhs-hosting-solution' ) . '</p>';
            return;
        }

        // Check subscription status
        $subscription_id = get_post_meta($domain_id, 'skyhshoso_subscription_id', true);
        $subscription_status = '';
        
        if (!empty($subscription_id)) {
            $subscription = skyhshoso_get_subscription($subscription_id);
            if ($subscription) {
                $subscription_status = $subscription->get_status();
            }
        }

        // Only allow DNS management for 'active' or 'pending-cancel' subscriptions
        if (!in_array($subscription_status, array('active', 'pending-cancel'))) {
            echo '<p>' . esc_html__( 'DNS management is only available for active or pending cancellation subscriptions.', 'skyhs-hosting-solution' ) . '</p>';
            return;
        }

        $domain_name = $domain->post_title;
        skyhshoso_enom_dns_editor($domain_name);
    }
}

new SkyHSHOSO_Account_Domains();
