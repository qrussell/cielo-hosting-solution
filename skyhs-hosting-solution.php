<?php
/**
 * Plugin Name: SkyHS - Sell Domain, Cpanel Hosting and Subscription using WooCommerce
 * Description: A hosting solution plugin that requires WooCommerce. Includes a built-in subscription system.
 * Version: 1.0.6
 * Author: Siteskyline
 * Author URI: http://siteskyline.com
 * Text Domain: skyhs-hosting-solution
 * Requires at least: 6.9
 * Requires PHP: 7.2
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * WC requires at least: 3.0
 * WC tested up to: 5.0
 * Requires Plugins: woocommerce
 * WooCommerce: true
 *
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

// Define plugin constants
define( 'SKYHSHOSO_VERSION', '1.0.6' );
define( 'SKYHSHOSO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SKYHSHOSO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * The main plugin class
 */
final class SkyHSHOSO {

    /**
     * Singleton instance
     *
     * @var SkyHSHOSO
     */
    protected static $instance = null;

    /**
     * Main SkyHSHOSO Instance.
     *
     * Ensures only one instance of SkyHSHOSO is loaded or can be loaded.
     *
     * @return SkyHSHOSO - Main instance.
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Enqueue plugin scripts and styles for admin pages
     */
    public function enqueue_admin_scripts_styles( $hook ) {
        $screen = get_current_screen();

        // Enqueue product fields JS on product edit pages
        if ( $screen && in_array( $screen->post_type, array( 'product' ), true ) ) {
            wp_enqueue_script(
                'skyhshoso-product-fields',
                SKYHSHOSO_PLUGIN_URL . 'assets/js/product-fields.js',
                array( 'jquery' ),
                SKYHSHOSO_VERSION,
                true
            );
        }

    }


    /**
     * Hosting_Solution Constructor.
     */
    public function __construct() {
        $this->init_hooks();
        
        // Check if WooCommerce is active before proceeding
        if ($this->is_woocommerce_active()) {
            // Include plugin files
            $this->includes();
            
            // Initialize SkyHS Menu Endpoints
            if (class_exists('SkyHSHOSO_Menu_Endpoints')) {
                new SkyHSHOSO_Menu_Endpoints();
            }
        }
    }

    /**
     * Hook into actions and filters.
     */
    private function init_hooks() {
        add_action( 'admin_init', array( $this, 'check_dependencies' ) );
        add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
        
        // Add plugin settings link
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_plugin_action_links' ) );
        
        // Enqueue admin scripts and styles
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts_styles' ) );
        
        // Enqueue frontend styles
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_scripts' ) );

        // Register navigation menu locations
        add_action( 'init', array( $this, 'register_nav_menus' ) );

        // Register Google Fonts for the dashboard shortcode
        add_action( 'wp_enqueue_scripts', array( $this, 'register_dashboard_fonts' ) );

        // Auto-generate UUIDs for supported post types.
        add_action( 'save_post', array( $this, 'maybe_generate_uuid' ), 10, 2 );

        // Auto-generate UUIDs for subscription-related orders.
        add_action( 'woocommerce_new_order', array( $this, 'maybe_generate_order_uuid' ), 10, 1 );

        // Auto-generate UUIDs for newly created subscriptions.
        add_action( 'skyhshoso_subscription_created', array( $this, 'maybe_generate_subscription_uuid' ), 5, 1 );

        // Force isolated dashboard page template
        add_filter( 'template_include', array( $this, 'force_dashboard_template' ), 99 );

        // Dequeue theme styles on the dashboard page template
        add_action( 'wp_enqueue_scripts', array( $this, 'isolate_dashboard_scripts' ), 9999 );
    }

    /**
     * Register navigation menus.
     */
    public function register_nav_menus() {
        register_nav_menu( 'skyhshoso_dashboard_header', __( 'SkyHS Dashboard Header Menu', 'skyhs-hosting-solution' ) );
    }



    /**
     * Include required files.
     */
    private function includes() {
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-autoloader.php';

        // -----------------------------------------------------------------
        // Subscription system (must load before anything that calls skyhshoso_*)
        // -----------------------------------------------------------------
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-subscription-db.php';
		// Load the PayPal Cart Manager
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-cielo-paypal-cart-manager.php';

		// Initialize the class, but only if WooCommerce is active
		add_action( 'plugins_loaded', 'cielo_init_paypal_cart_manager' );
		function cielo_init_paypal_cart_manager() {
			if ( class_exists( 'WooCommerce' ) ) {
				new Cielo_PayPal_Cart_Manager();
			}
		}
		// Load Provider Abstraction Classes
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-hosting-provider-interface.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-hosting-provider-factory.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/drivers/class-whm-driver.php';

        // Auto-create the DB table if it's missing (e.g. plugin was already active when the table was added).
        SkyHSHOSO_Subscription_DB::maybe_install();

        // UUID utility + import/export (loaded early so hooks can register).
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-uuid.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-export.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-import.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-import-export-admin.php';

        // Instantiate import/export admin early so admin_post_* hooks
        // register on admin-post.php requests (which skip admin_menu).
        SkyHSHOSO_Import_Export_Admin::instance();

        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-emails.php';

		// Activity log system (must load before cron/email classes that log to it).
		require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-activity-log.php';
		require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-logger.php';
        SkyHSHOSO_Activity_Log::maybe_install();

        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-subscription.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-subscription-functions.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-subscription-checkout.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-subscription-cron.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-subscription-admin.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-cart-display.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-extend-store-endpoint.php';

        // Initialize cart display hooks.
        SkyHSHOSO_Cart_Display::init();

        // Initialize Store API endpoint extension (for block cart/checkout).
        add_action( 'woocommerce_blocks_loaded', array( 'SkyHSHOSO_Extend_Store_Endpoint', 'init' ) );

        // -----------------------------------------------------------------
        // Admin Reports
        // -----------------------------------------------------------------
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/admin/class-skyhshoso-admin-reports.php';

        // -----------------------------------------------------------------
        // Specific Managers
        // -----------------------------------------------------------------
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-manual-renewal-manager.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-drip-downloads-manager.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-zero-initial-payment-checkout-manager.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-limited-recurring-coupon-manager.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-backup-manager.php';

        // Initialize zero initial payment manager.
        SkyHSHOSO_Zero_Initial_Payment_Checkout_Manager::init();

        // Initialize drip downloads manager.
        SkyHSHOSO_Drip_Downloads_Manager::init();

        // Initialize manual renewal manager.
        SkyHSHOSO_Manual_Renewal_Manager::init();

        // Initialize limited recurring coupon manager.
        SkyHSHOSO_Limited_Recurring_Coupon_Manager::init();

        // Initialize backup manager.
        SkyHSHOSO_Backup_Manager::init();

        // -----------------------------------------------------------------
        // Early Renewal
        // -----------------------------------------------------------------
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-cart-renewal.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/early-renewal/skyhshoso-early-renewal-functions.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/early-renewal/class-skyhshoso-early-renewal-manager.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/early-renewal/class-skyhshoso-cart-early-renewal.php';

        // Initialize early renewal.
        SkyHSHOSO_Early_Renewal_Manager::init();
        new SkyHSHOSO_Cart_Early_Renewal();

        // Force subscription_date_changes support for Stripe (needed for early renewal).
        add_filter( 'skyhshoso_subscription_payment_gateway_supports', function( $supports, $feature, $subscription ) {
            if ( 'subscription_date_changes' === $feature ) {
                $payment_method = $subscription->get_payment_method();
                $gateways       = WC()->payment_gateways()->get_available_payment_gateways();
                $gateway        = isset( $gateways[ $payment_method ] ) ? $gateways[ $payment_method ] : null;
                if ( $gateway && false !== strpos( strtolower( get_class( $gateway ) ), 'stripe' ) ) {
                    $supports = true;
                }
            }
            return $supports;
        }, 10, 3 );

        // Allow Stripe gateway through the subscriptions support check.
        add_filter( 'skyhshoso_available_payment_gateways', function( $supports, $gateway_id, $gateway ) {
            if ( false !== strpos( strtolower( get_class( $gateway ) ), 'stripe' ) ) {
                return true;
            }
            return $supports;
        }, 10, 3 );

		// Add the custom hosting product type to the WooCommerce dropdown
		add_filter( 'product_type_selector', 'cielo_register_hosting_product_type' );

		function cielo_register_hosting_product_type( $types ) {
			// Adds 'skyhshoso' as the internal slug, and 'Website Hosting' as the visible label
			$types['skyhshoso'] = __( 'Website Hosting', 'cielo-hosting' );
			
			return $types;
		}
        // -----------------------------------------------------------------
        // Subscription switching (upgrade/downgrade)
        // -----------------------------------------------------------------
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/switching/skyhshoso-switch-functions.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/switching/class-skyhshoso-switch-cart-item.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/switching/class-skyhshoso-add-cart-item.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/switching/class-skyhshoso-switch-totals-calculator.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/switching/class-skyhshoso-cart-switch.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/switching/class-skyhshoso-order-item-pending-switch.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/switching/class-skyhshoso-subscription-item-coupon-pending-switch.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/switching/class-skyhshoso-subscription-item-fee-pending-switch.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/switching/class-skyhshoso-subscription-line-item-switched.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/switching/class-skyhshoso-subscriptions-switcher.php';

        // Initialize switcher.
        SkyHSHOSO_Subscriptions_Switcher::init();

        // Inline AJAX subscription variation switching (frontend dashboard dropdown).
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-subscription-switch-ajax.php';
        SkyHSHOSO_Subscription_Switch_Ajax::init();

        // -----------------------------------------------------------------
        // Core plugin files
        // -----------------------------------------------------------------
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-hosting-solution-post-types.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-enom-integration.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-domain-cart.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-domain-checker-shortcode.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-product-fields.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-whm-integration.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-domain-meta-boxes.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/auto-complete-virtual-orders.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/wc-account-domains.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/dns-editor.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/whm-ajax-handlers.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-wordpress-manager.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-wp-site-handler.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/wc-account-collaborator.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-dashboard-shortcode.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-product-shortcode.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-hosting-detail.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-review-collector.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-onboarding-wizard.php';

        // Include settings class first
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-hosting-solution-settings.php';

        // Include role manager
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-role-manager.php';

        // Include menu organizer
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-menu-organizer.php';

        // Include customize page
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-customize.php';

        // Include product manager (guided product creation UI)
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-product-manager.php';

        // Include server manager (custom server admin page)
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-server-manager.php';

        // Include hosting manager (custom hosting admin page)
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-hosting-manager.php';

        // Include WP site manager (custom WP sites admin page)
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-wp-site-manager.php';

        // Include domain manager (custom domain admin page)
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-domain-manager.php';

        // Include email campaign system
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-email-campaign-db.php';
        SkyHSHOSO_Email_Campaign_DB::maybe_install();
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-email-campaign.php';
        SkyHSHOSO_Email_Campaign::instance();
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-email-campaign-admin.php';
        SkyHSHOSO_Email_Campaign_Admin::instance();

        // Register cron schedule and schedule events.
        add_filter( 'cron_schedules', array( 'SkyHSHOSO_Email_Campaign', 'add_cron_schedules' ) );
        SkyHSHOSO_Email_Campaign::schedule_events();

        // Include Enom domain sync (sync existing domains from Enom)
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-enom-domain-sync.php';

        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-cpanel-sync.php';

        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-woocommerce-subscription-handler.php';
        if ( ! SkyHSHOSO_Settings::is_subscription_processing_disabled() ) {
            SkyHSHOSO_Subscription_Handler();
        }

        // Include REST API filter class
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-rest-api-filter.php';

        // Include SkyHS Menu Endpoints class
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-menu-endpoints.php';



        // Include invoice system.
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-invoice.php';
        SkyHSHOSO_Invoice::init();

        // -----------------------------------------------------------------
        // Gateway compatibility (payment gateway filter, PayPal, retry, change payment)
        // -----------------------------------------------------------------
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-payment-gateways.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/gateways/paypal/includes/skyhshoso-paypal-functions.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/gateways/paypal/class-skyhshoso-paypal.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/gateways/paypal/includes/class-skyhshoso-paypal-supports.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/gateways/paypal/includes/class-skyhshoso-paypal-status-manager.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/gateways/paypal/includes/class-skyhshoso-paypal-standard-switcher.php';
        if ( is_admin() ) {
            require_once SKYHSHOSO_PLUGIN_DIR . 'includes/gateways/paypal/includes/admin/class-skyhshoso-paypal-admin.php';
        }
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/gateways/paypal/includes/class-skyhshoso-paypal-standard-ipn-failure-handler.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-change-payment-gateway.php';

        // Payment Retry Classes
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/payment-retry/class-skyhshoso-retry.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/payment-retry/class-skyhshoso-retry-rule.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/payment-retry/class-skyhshoso-retry-rules.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/payment-retry/class-skyhshoso-retry-manager.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/payment-retry/class-skyhshoso-retry-table-maker.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/payment-retry/class-skyhshoso-retry-migrator.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/payment-retry/class-skyhshoso-retry-background-migrator.php';
        if ( is_admin() ) {
            require_once SKYHSHOSO_PLUGIN_DIR . 'includes/payment-retry/admin/class-skyhshoso-retry-admin.php';
            require_once SKYHSHOSO_PLUGIN_DIR . 'includes/payment-retry/admin/class-skyhshoso-meta-box-payment-retries.php';
        }
        // Payment Retry Data Stores
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/payment-retry/data-stores/abstract-skyhshoso-retry-store.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/payment-retry/data-stores/class-skyhshoso-retry-database-store.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/payment-retry/data-stores/class-skyhshoso-retry-post-store.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/payment-retry/data-stores/class-skyhshoso-retry-hybrid-store.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/payment-retry/data-stores/class-skyhshoso-retry-stores.php';

        // Initialize gateway modules.
        SkyHSHOSO_PayPal::init();
        SkyHSHOSO_Change_Payment_Gateway::init();
        SkyHSHOSO_Retry_Manager::init();

        // Initialize custom post types
        SkyHSHOSO_Post_Types::init();
    }

    /**
     * Check if dependencies are met.
     */
    public function check_dependencies() {
        // Only require WooCommerce - subscriptions is optional
        if ( ! $this->is_woocommerce_active() ) {
            deactivate_plugins( plugin_basename( __FILE__ ) );
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Core WordPress 'activate' parameter in redirect.
            if ( isset( $_GET['activate'] ) && current_user_can( 'activate_plugins' ) ) {
                unset( $_GET['activate'] );
            }
        }
    }

    /**
     * Check if WooCommerce is active.
     *
     * @return bool
     */
    public function is_woocommerce_active() {
        // Check if WooCommerce class exists, which is the most reliable method
        if (class_exists('WooCommerce')) {
            return true;
        }
        
        // Standard location check
        if (in_array('woocommerce/woocommerce.php', apply_filters('skyhshoso_active_plugins', get_option('active_plugins')))) {
            return true;
        }
        
        // Check for multisite network activation
        if (is_multisite() && array_key_exists('woocommerce/woocommerce.php', get_site_option('active_sitewide_plugins', array()))) {
            return true;
        }
        
        return false;
    }
    
     /**
     * Display dependency notice.
     */
    public function dependency_notice() {
        // Only show notice if WooCommerce is missing (subscriptions is optional)
        if ( ! $this->is_woocommerce_active() ) {
            $message = __( 'SkyHS Hosting Solution requires WooCommerce to be installed and activated.', 'skyhs-hosting-solution' );
            echo '<div class="error"><p>' . esc_html( $message ) . '</p></div>';
        }
    }
    /**
     * Initialize the plugin.
     */
    public function init() {
        // Only require WooCommerce - subscriptions is optional
        if ( $this->is_woocommerce_active() ) {
            $this->includes();
            $this->init_hooks();
        }
    }
    
    /**
     * Add settings link to plugins page
     *
     * @param array $links Existing links.
     * @return array Modified links.
     */
    public function add_plugin_action_links( $links ) {
        $settings_link = '<a href="' . admin_url( 'admin.php?page=skyhshoso-settings' ) . '">' . __( 'Settings', 'skyhs-hosting-solution' ) . '</a>';
        $review_link   = '<a href="https://wordpress.org/support/plugin/skyhs-hosting-solution/reviews/#new-post" target="_blank">' . __( 'Rate Plugin', 'skyhs-hosting-solution' ) . '</a>';
        array_unshift( $links, $review_link );
        array_unshift( $links, $settings_link );
        return $links;
    }

    /**
     * Register Google Fonts for the dashboard shortcode
     */
    public function register_dashboard_fonts() {
        wp_enqueue_style(
            'skyhshoso-dashboard-fonts',
            'https://fonts.googleapis.com/css2?display=swap&family=Inter:wght@400;500;700;900&family=Noto+Sans:wght@400;500;700;900',
            array(),
            SKYHSHOSO_VERSION
        );
    }

    /**
     * Enqueue frontend scripts and styles
     */
    public function enqueue_frontend_scripts() {
        // Enqueue dashboard CSS on account pages
        if ( function_exists( 'is_account_page' ) && is_account_page() ) {
            wp_enqueue_style(
                'skyhshoso-dashboard',
                SKYHSHOSO_PLUGIN_URL . 'assets/css/skyhshoso-dashboard.css',
                array(),
                SKYHSHOSO_VERSION
            );

            // Localize script data for frontend
            wp_localize_script(
                'jquery',
                'skyhshoso_dashboard_data',
                array(
                    'ajax_url' => admin_url( 'admin-ajax.php' ),
                    'nonce'    => wp_create_nonce( 'skyhshoso_dashboard_nonce' ),
                )
            );
        }

        // Enqueue cart display JS on block cart/checkout pages.
        if ( function_exists( 'has_block' ) && ( has_block( 'woocommerce/cart' ) || has_block( 'woocommerce/checkout' ) || is_cart() || is_checkout() ) ) {
            wp_enqueue_script(
                'skyhshoso-cart-display',
                SKYHSHOSO_PLUGIN_URL . 'assets/js/cart-display.js',
                array( 'wc-blocks-checkout' ),
                SKYHSHOSO_VERSION,
                true
            );
        }
    }

    // -------------------------------------------------------------------------
    // UUID Auto-Generation Hooks
    // -------------------------------------------------------------------------

    /**
     * Auto-generate UUID for supported post types when saved.
     *
     * @param int     $post_id
     * @param WP_Post $post
     */
    public function maybe_generate_uuid( $post_id, $post ) {
        if ( ! in_array( $post->post_type, SkyHSHOSO_UUID::POST_TYPES, true ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        $uuid = get_post_meta( $post_id, SkyHSHOSO_UUID::META_KEY, true );
        if ( empty( $uuid ) ) {
            SkyHSHOSO_UUID::set_post_uuid( $post_id );
        }
    }

    /**
     * Auto-generate UUID for subscription-related orders.
     *
     * @param int $order_id
     */
    public function maybe_generate_order_uuid( $order_id ) {
        $has_renewal = get_post_meta( $order_id, '_skyhshoso_renewal_subscription_id', true );
        $has_created = get_post_meta( $order_id, '_skyhshoso_subscriptions_created', true );

        if ( empty( $has_renewal ) && empty( $has_created ) ) {
            return;
        }

        $uuid = get_post_meta( $order_id, SkyHSHOSO_UUID::META_KEY, true );
        if ( empty( $uuid ) ) {
            SkyHSHOSO_UUID::set_order_uuid( $order_id );
        }
    }

    /**
     * Auto-generate UUID for newly created subscriptions.
     *
     * @param SkyHSHOSO_Subscription $subscription
     */
    public function maybe_generate_subscription_uuid( $subscription ) {
        $uuid = SkyHSHOSO_UUID::get_subscription_uuid( $subscription->get_id() );
        if ( empty( $uuid ) ) {
            SkyHSHOSO_UUID::set_subscription_uuid( $subscription->get_id() );
        }
    }

    /**
     * Force the isolated dashboard canvas page template for the dashboard page.
     *
     * @param string $template Current template file path.
     * @return string Modified template file path.
     */
    public function force_dashboard_template( $template ) {
        $options = get_option( 'skyhshoso_settings_group', array() );
        $dashboard_page = isset( $options['dashboard_page'] ) ? absint( $options['dashboard_page'] ) : 0;

        $is_dashboard = false;
        if ( $dashboard_page > 0 && is_page( $dashboard_page ) ) {
            $is_dashboard = true;
        } else {
            global $post;
            if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'skyhshoso_dashboard' ) ) {
                $is_dashboard = true;
            }
        }

        if ( $is_dashboard ) {
            $plugin_template = SKYHSHOSO_PLUGIN_DIR . 'templates/skyhshoso-dashboard-template.php';
            if ( file_exists( $plugin_template ) ) {
                return $plugin_template;
            }
        }
        return $template;
    }

    /**
     * Dequeue theme-related stylesheets on the isolated dashboard page to prevent style conflicts.
     */
    public function isolate_dashboard_scripts() {
        $options = get_option( 'skyhshoso_settings_group', array() );
        $dashboard_page = isset( $options['dashboard_page'] ) ? absint( $options['dashboard_page'] ) : 0;

        $is_dashboard = false;
        if ( $dashboard_page > 0 && is_page( $dashboard_page ) ) {
            $is_dashboard = true;
        } else {
            global $post;
            if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'skyhshoso_dashboard' ) ) {
                $is_dashboard = true;
            }
        }

        if ( $is_dashboard ) {
            global $wp_styles;

            if ( ! empty( $wp_styles->queue ) ) {
                foreach ( $wp_styles->queue as $handle ) {
                    $src = isset( $wp_styles->registered[ $handle ] ) ? $wp_styles->registered[ $handle ]->src : '';
                    
                    // If style is loaded from the themes directory, dequeue it!
                    if ( $src && ( strpos( $src, '/themes/' ) !== false || strpos( $src, 'wp-includes/css/dist/block-library/' ) !== false ) ) {
                        wp_dequeue_style( $handle );
                    }
                }
            }
        }
    }
}

/**
 * Main instance of SkyHSHOSO.
 *
 * Returns the main instance of SkyHSHOSO to prevent the need to use globals.
 *
 * @return SkyHSHOSO
 */
function SkyHSHOSO() {
    return SkyHSHOSO::instance();
}

// Initialize the plugin
add_action( 'plugins_loaded', 'SkyHSHOSO', 10 );

// Declare HPOS compatibility
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

// Enable COD for subscription products.
add_filter( 'woocommerce_payment_gateway_supports', function( $is_supported, $feature, $gateway ) {
    if ( 'cod' === $gateway->id && 'subscriptions' === $feature ) {
        return true;
    }
    return $is_supported;
}, 10, 3 );

// Map subscription custom capabilities to check ownership or administrative access.
add_filter( 'map_meta_cap', function( $caps, $cap, $user_id, $args ) {
    switch ( $cap ) {
        case 'switch_shop_subscription':
        case 'edit_shop_subscription_status':
            $caps = array();
            $subscription_id = isset( $args[0] ) ? (int) $args[0] : 0;
            
            $subscription = false;
            if ( $subscription_id && function_exists( 'skyhshoso_get_subscription' ) ) {
                $subscription = skyhshoso_get_subscription( $subscription_id );
            }
            
            if ( $subscription ) {
                $customer_id = (int) $subscription->get_customer_id();
                
                if ( (int) $user_id === $customer_id ) {
                    // Owner is allowed
                    $caps[] = 'read';
                } elseif ( user_can( $user_id, 'manage_woocommerce' ) ) {
                    // Admin/Shop Manager is allowed
                    $caps[] = 'manage_woocommerce';
                } else {
                    // Not allowed
                    $caps[] = 'do_not_allow';
                }
            } else {
                $caps[] = 'do_not_allow';
            }
            break;
    }
    return $caps;
}, 10, 4 );

/**
 * Activation hook — create DB tables and schedule cron jobs.
 */
function skyhshoso_hosting_solution_activate() {
    if ( ! SkyHSHOSO()->is_woocommerce_active() ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die( esc_html__( 'SkyHS Hosting Solution requires WooCommerce to be installed and activated.', 'skyhs-hosting-solution' ) );
    }

    // Load DB class if not yet loaded (activation runs before plugins_loaded).
    if ( ! class_exists( 'SkyHSHOSO_Subscription_DB' ) ) {
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-subscription-db.php';
    }
    SkyHSHOSO_Subscription_DB::install();

    // Ensure UUID column exists (runs ALTER TABLE if needed).
    SkyHSHOSO_Subscription_DB::maybe_install();

    // Load UUID class and backfill existing records on upgrade.
    if ( ! class_exists( 'SkyHSHOSO_UUID' ) ) {
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-uuid.php';
    }
    SkyHSHOSO_UUID::backfill_batch( 250 );

    // Install activity log table.
    if ( ! class_exists( 'SkyHSHOSO_Activity_Log' ) ) {
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-activity-log.php';
    }
    SkyHSHOSO_Activity_Log::install();

    // Schedule cron events.
    if ( ! class_exists( 'SkyHSHOSO_Subscription_Cron' ) ) {
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-subscription-cron.php';
    }
    SkyHSHOSO_Subscription_Cron::schedule_events();

    // Install email campaign tables.
    if ( ! class_exists( 'SkyHSHOSO_Email_Campaign_DB' ) ) {
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-email-campaign-db.php';
    }
    SkyHSHOSO_Email_Campaign_DB::install();

    // Schedule email campaign cron.
    if ( ! class_exists( 'SkyHSHOSO_Email_Campaign' ) ) {
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-email-campaign.php';
    }
    add_filter( 'cron_schedules', array( 'SkyHSHOSO_Email_Campaign', 'add_cron_schedules' ) );
    SkyHSHOSO_Email_Campaign::schedule_events();
}
register_activation_hook( __FILE__, 'skyhshoso_hosting_solution_activate' );

/**
 * Deactivation hook — unschedule cron jobs.
 */
function skyhshoso_hosting_solution_deactivate() {
    if ( ! class_exists( 'SkyHSHOSO_Subscription_Cron' ) ) {
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-subscription-cron.php';
    }
    SkyHSHOSO_Subscription_Cron::unschedule_events();

    if ( ! class_exists( 'SkyHSHOSO_Backup_Manager' ) ) {
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-backup-manager.php';
    }
    SkyHSHOSO_Backup_Manager::unschedule_backup_cron();

    if ( ! class_exists( 'SkyHSHOSO_Email_Campaign' ) ) {
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-email-campaign.php';
    }
    SkyHSHOSO_Email_Campaign::unschedule_events();
}
register_deactivation_hook( __FILE__, 'skyhshoso_hosting_solution_deactivate' );

// Load translations safely on the 'init' hook for WordPress 6.7+ compatibility
add_action( 'init', 'skyhshoso_load_textdomain' );
function skyhshoso_load_textdomain() {
    load_plugin_textdomain( 'skyhs-hosting-solution', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
/**
 * =========================================================================
 * SERVER CONFIGURATION META BOX (WHM vs HestiaCP)
 * =========================================================================
 */

add_action('add_meta_boxes', 'skyhshoso_add_server_type_metabox');
function skyhshoso_add_server_type_metabox() {
    add_meta_box(
        'skyhshoso_server_type_box',
        'Control Panel Type',
        'skyhshoso_render_server_type_metabox',
        'skyhshoso_server', // Targets the Server custom post type
        'side',
        'high'
    );
}

function skyhshoso_render_server_type_metabox($post) {
    // Get the current saved type, default to 'whm' for backwards compatibility
    $current_type = get_post_meta($post->ID, '_skyhshoso_server_type', true);
    if (empty($current_type)) {
        $current_type = 'whm';
    }

    wp_nonce_field('skyhs_server_type_save', 'skyhs_server_type_nonce');
    ?>
    <div style="padding: 10px 0;">
        <label for="skyhshoso_server_type" style="display:block; font-weight:600; margin-bottom:8px;">Select API Driver:</label>
        <select name="_skyhshoso_server_type" id="skyhshoso_server_type" style="width:100%;">
            <option value="whm" <?php selected($current_type, 'whm'); ?>>cPanel / WHM</option>
            <option value="hestiacp" <?php selected($current_type, 'hestiacp'); ?>>HestiaCP</option>
        </select>
        <p class="description" style="margin-top:10px;">This tells the system how to communicate with this server.</p>
    </div>

    <script>
    jQuery(document).ready(function($) {
        function updateServerLabels() {
            var type = $('#skyhshoso_server_type').val();
            
            // Find the existing input fields by their database names
            var hostInput = $('input[name="_skyhshoso_whm_host"]');
            var userInput = $('input[name="_skyhshoso_whm_user_id"]');
            var tokenInput = $('input[name="_skyhshoso_whm_token"], textarea[name="_skyhshoso_whm_token"]');

            // Find their visual labels
            var hostLabel = hostInput.closest('tr, .acf-field, .inside').find('label').first();
            var userLabel = userInput.closest('tr, .acf-field, .inside').find('label').first();
            var tokenLabel = tokenInput.closest('tr, .acf-field, .inside').find('label').first();

            // Swap the text based on the dropdown!
            if (type === 'hestiacp') {
                if(hostLabel.length) hostLabel.html('<strong>HestiaCP Host / IP Address</strong>');
                if(userLabel.length) userLabel.html('<strong>HestiaCP Access Key ID</strong>');
                if(tokenLabel.length) tokenLabel.html('<strong>HestiaCP Secret Key</strong>');
            } else {
                if(hostLabel.length) hostLabel.html('<strong>WHM Host / IP Address</strong>');
                if(userLabel.length) userLabel.html('<strong>WHM Username (root)</strong>');
                if(tokenLabel.length) tokenLabel.html('<strong>WHM API Token</strong>');
            }
        }

        // Run when dropdown changes, and run once on page load
        $('#skyhshoso_server_type').on('change', updateServerLabels);
        setTimeout(updateServerLabels, 300); 
    });
    </script>
    <?php
}

add_action('save_post', 'skyhshoso_save_server_type');
function skyhshoso_save_server_type($post_id) {
    if (!isset($_POST['skyhs_server_type_nonce']) || !wp_verify_nonce($_POST['skyhs_server_type_nonce'], 'skyhs_server_type_save')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    if (isset($_POST['_skyhshoso_server_type'])) {
        update_post_meta($post_id, '_skyhshoso_server_type', sanitize_text_field($_POST['_skyhshoso_server_type']));
    }
}
/**
 * =========================================================================
 * CASCADING DELETES: CLEAN UP WP SITES WHEN HOSTING IS TERMINATED
 * =========================================================================
 */
add_action('trashed_post', 'skyhshoso_cleanup_orphaned_wpsites');
add_action('before_delete_post', 'skyhshoso_cleanup_orphaned_wpsites');

function skyhshoso_cleanup_orphaned_wpsites($post_id) {
    // 1. Only run if the post being deleted is a Hosting account
    if (get_post_type($post_id) !== 'skyhshoso_hosting') {
        return;
    }

    // 2. Identify the cPanel user and Subscription ID attached to this hosting account
    $username = get_post_meta($post_id, 'skyhshoso_hosting_username', true);
    $sub_id   = get_post_meta($post_id, 'skyhshoso_subscription_id', true);

    if (!$username && !$sub_id) {
        return;
    }

    // 3. Find all WP Sites linked to this cPanel username OR Subscription ID
    $args = array(
        'post_type'      => 'skyhshoso_wp_site',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => array(
            'relation' => 'OR'
        )
    );

    if ($username) {
        $args['meta_query'][] = array(
            'key'     => 'skyhshoso_wp_cpanel_user',
            'value'   => $username,
            'compare' => '='
        );
    }

    if ($sub_id) {
        $args['meta_query'][] = array(
            'key'     => 'skyhshoso_subscription_id',
            'value'   => $sub_id,
            'compare' => '='
        );
    }

    $wp_sites = get_posts($args);

    // 4. Cascade the trash/delete command down to the child WP Sites
    foreach ($wp_sites as $site_id) {
        if (current_action() === 'trashed_post') {
            wp_trash_post($site_id);
        } else {
            wp_delete_post($site_id, true);
        }
    }
}