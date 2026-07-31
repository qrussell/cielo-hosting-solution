<?php
/**
 * Plugin Name: Cielo Hosting Solution
 * Plugin URI: https://github.com/qrussell/cielo-hosting-solution
 * Description: Automated cPanel and WP Toolkit provisioning via WooCommerce. (Forked from Sky Hosting Solution).
 * Version: 1.0.0
 * Author: cielocloud plugins
 * License: GPLv2 or later
 * Text Domain: skyhs-hosting-solution
 * Update URI: false
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 1. MUST BE AT THE TOP: Initialize the updater namespace
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

// Define plugin constants
define( 'SKYHSHOSO_VERSION', '1.0.6' );
define( 'SKYHSHOSO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SKYHSHOSO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * The main plugin class
 */
final class SkyHSHOSO {

    protected static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function enqueue_admin_scripts_styles( $hook ) {
        $screen = get_current_screen();
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

    public function __construct() {
        $this->init_hooks();
        
        if ($this->is_woocommerce_active()) {
            $this->includes();
            
            if (class_exists('SkyHSHOSO_Menu_Endpoints')) {
                new SkyHSHOSO_Menu_Endpoints();
            }
        }
    }

    private function init_hooks() {
        add_action( 'admin_init', array( $this, 'check_dependencies' ) );
        add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_plugin_action_links' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts_styles' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_scripts' ) );
        add_action( 'init', array( $this, 'register_nav_menus' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'register_dashboard_fonts' ) );
        add_action( 'save_post', array( $this, 'maybe_generate_uuid' ), 10, 2 );
        add_action( 'woocommerce_new_order', array( $this, 'maybe_generate_order_uuid' ), 10, 1 );
        add_action( 'skyhshoso_subscription_created', array( $this, 'maybe_generate_subscription_uuid' ), 5, 1 );
        add_filter( 'template_include', array( $this, 'force_dashboard_template' ), 99 );
        add_action( 'wp_enqueue_scripts', array( $this, 'isolate_dashboard_scripts' ), 9999 );
    }

    public function register_nav_menus() {
        register_nav_menu( 'skyhshoso_dashboard_header', __( 'CieloHS Dashboard Header Menu', 'skyhs-hosting-solution' ) );
    }

    private function includes() {
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-autoloader.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-subscription-db.php';

        // SAFE LOAD: The PayPal Cart Manager
        $paypal_manager = plugin_dir_path( __FILE__ ) . 'includes/class-cielo-paypal-cart-manager.php';
        if ( file_exists( $paypal_manager ) ) {
            require_once $paypal_manager;
            add_action( 'plugins_loaded', 'cielo_init_paypal_cart_manager' );
        }

        // SAFE LOAD: Provider Abstraction Classes
        $provider_interface = plugin_dir_path( __FILE__ ) . 'includes/class-hosting-provider-interface.php';
        $provider_factory   = plugin_dir_path( __FILE__ ) . 'includes/class-hosting-provider-factory.php';
        $whm_driver         = plugin_dir_path( __FILE__ ) . 'includes/drivers/class-whm-driver.php';
        
        if ( file_exists( $provider_interface ) ) require_once $provider_interface;
        if ( file_exists( $provider_factory ) ) require_once $provider_factory;
        if ( file_exists( $whm_driver ) ) require_once $whm_driver;

        SkyHSHOSO_Subscription_DB::maybe_install();

        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-uuid.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-export.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-import.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-import-export-admin.php';

        SkyHSHOSO_Import_Export_Admin::instance();

        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-emails.php';
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

        SkyHSHOSO_Cart_Display::init();
        add_action( 'woocommerce_blocks_loaded', array( 'SkyHSHOSO_Extend_Store_Endpoint', 'init' ) );

        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/admin/class-skyhshoso-admin-reports.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-manual-renewal-manager.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-drip-downloads-manager.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-zero-initial-payment-checkout-manager.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-limited-recurring-coupon-manager.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-backup-manager.php';

        SkyHSHOSO_Zero_Initial_Payment_Checkout_Manager::init();
        SkyHSHOSO_Drip_Downloads_Manager::init();
        SkyHSHOSO_Manual_Renewal_Manager::init();
        SkyHSHOSO_Limited_Recurring_Coupon_Manager::init();
        SkyHSHOSO_Backup_Manager::init();

        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-cart-renewal.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/early-renewal/skyhshoso-early-renewal-functions.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/early-renewal/class-skyhshoso-early-renewal-manager.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/early-renewal/class-skyhshoso-cart-early-renewal.php';

        // SAFE LOAD: Plugin Update Checker
        $updater_path = plugin_dir_path( __FILE__ ) . 'includes/plugin-update-checker/plugin-update-checker.php';
        if ( file_exists( $updater_path ) ) {
            require_once $updater_path;
            $cielo_update_checker = PucFactory::buildUpdateChecker(
                'https://github.com/qrussell/cielo-hosting-solution/',
                __FILE__,
                'cielo-hosting-solution'
            );
            $cielo_update_checker->setBranch('main'); 
        }

        SkyHSHOSO_Early_Renewal_Manager::init();
        new SkyHSHOSO_Cart_Early_Renewal();

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

        add_filter( 'skyhshoso_available_payment_gateways', function( $supports, $gateway_id, $gateway ) {
            if ( false !== strpos( strtolower( get_class( $gateway ) ), 'stripe' ) ) {
                return true;
            }
            return $supports;
        }, 10, 3 );

        add_filter( 'product_type_selector', 'cielo_register_hosting_product_type' );

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

        SkyHSHOSO_Subscriptions_Switcher::init();

        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-subscription-switch-ajax.php';
        SkyHSHOSO_Subscription_Switch_Ajax::init();

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

        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-hosting-solution-settings.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-role-manager.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-menu-organizer.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-customize.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-product-manager.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-server-manager.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-hosting-manager.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-wp-site-manager.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-domain-manager.php';

        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-email-campaign-db.php';
        SkyHSHOSO_Email_Campaign_DB::maybe_install();
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-email-campaign.php';
        SkyHSHOSO_Email_Campaign::instance();
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-email-campaign-admin.php';
        SkyHSHOSO_Email_Campaign_Admin::instance();

        add_filter( 'cron_schedules', array( 'SkyHSHOSO_Email_Campaign', 'add_cron_schedules' ) );
        SkyHSHOSO_Email_Campaign::schedule_events();

        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-enom-domain-sync.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-cpanel-sync.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-woocommerce-subscription-handler.php';
        if ( ! SkyHSHOSO_Settings::is_subscription_processing_disabled() ) {
            SkyHSHOSO_Subscription_Handler();
        }

        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-rest-api-filter.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-menu-endpoints.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-invoice.php';
        SkyHSHOSO_Invoice::init();

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

        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/payment-retry/data-stores/abstract-skyhshoso-retry-store.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/payment-retry/data-stores/class-skyhshoso-retry-database-store.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/payment-retry/data-stores/class-skyhshoso-retry-post-store.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/payment-retry/data-stores/class-skyhshoso-retry-hybrid-store.php';
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/payment-retry/data-stores/class-skyhshoso-retry-stores.php';

        SkyHSHOSO_PayPal::init();
        SkyHSHOSO_Change_Payment_Gateway::init();
        SkyHSHOSO_Retry_Manager::init();

        SkyHSHOSO_Post_Types::init();
    }

    public function check_dependencies() {
        if ( ! $this->is_woocommerce_active() ) {
            deactivate_plugins( plugin_basename( __FILE__ ) );
            if ( isset( $_GET['activate'] ) && current_user_can( 'activate_plugins' ) ) {
                unset( $_GET['activate'] );
            }
        }
    }

    public function is_woocommerce_active() {
        if (class_exists('WooCommerce')) {
            return true;
        }
        if (in_array('woocommerce/woocommerce.php', apply_filters('skyhshoso_active_plugins', get_option('active_plugins')))) {
            return true;
        }
        if (is_multisite() && array_key_exists('woocommerce/woocommerce.php', get_site_option('active_sitewide_plugins', array()))) {
            return true;
        }
        return false;
    }
    
    public function dependency_notice() {
        if ( ! $this->is_woocommerce_active() ) {
            $message = __( 'Cielo Hosting Solution requires WooCommerce to be installed and activated.', 'skyhs-hosting-solution' );
            echo '<div class="error"><p>' . esc_html( $message ) . '</p></div>';
        }
    }

    public function init() {
        if ( $this->is_woocommerce_active() ) {
            $this->includes();
            $this->init_hooks();
        }
    }
    
    public function add_plugin_action_links( $links ) {
        $settings_link = '<a href="' . admin_url( 'admin.php?page=skyhshoso-settings' ) . '">' . __( 'Settings', 'skyhs-hosting-solution' ) . '</a>';
        $review_link   = '<a href="https://wordpress.org/support/plugin/skyhs-hosting-solution/reviews/#new-post" target="_blank">' . __( 'Rate Plugin', 'skyhs-hosting-solution' ) . '</a>';
        array_unshift( $links, $review_link );
        array_unshift( $links, $settings_link );
        return $links;
    }

    public function register_dashboard_fonts() {
        wp_enqueue_style(
            'skyhshoso-dashboard-fonts',
            'https://fonts.googleapis.com/css2?display=swap&family=Inter:wght@400;500;700;900&family=Noto+Sans:wght@400;500;700;900',
            array(),
            SKYHSHOSO_VERSION
        );
    }

    public function enqueue_frontend_scripts() {
        if ( function_exists( 'is_account_page' ) && is_account_page() ) {
            wp_enqueue_style(
                'skyhshoso-dashboard',
                SKYHSHOSO_PLUGIN_URL . 'assets/css/skyhshoso-dashboard.css',
                array(),
                SKYHSHOSO_VERSION
            );

            wp_localize_script(
                'jquery',
                'skyhshoso_dashboard_data',
                array(
                    'ajax_url' => admin_url( 'admin-ajax.php' ),
                    'nonce'    => wp_create_nonce( 'skyhshoso_dashboard_nonce' ),
                )
            );
        }

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

    public function maybe_generate_subscription_uuid( $subscription ) {
        $uuid = SkyHSHOSO_UUID::get_subscription_uuid( $subscription->get_id() );
        if ( empty( $uuid ) ) {
            SkyHSHOSO_UUID::set_subscription_uuid( $subscription->get_id() );
        }
    }

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
                    if ( $src && ( strpos( $src, '/themes/' ) !== false || strpos( $src, 'wp-includes/css/dist/block-library/' ) !== false ) ) {
                        wp_dequeue_style( $handle );
                    }
                }
            }
        }
    }
}

function SkyHSHOSO() {
    return SkyHSHOSO::instance();
}

add_action( 'plugins_loaded', 'SkyHSHOSO', 10 );

add_action( 'before_woocommerce_init', function() {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

add_filter( 'woocommerce_payment_gateway_supports', function( $is_supported, $feature, $gateway ) {
    if ( 'cod' === $gateway->id && 'subscriptions' === $feature ) {
        return true;
    }
    return $is_supported;
}, 10, 3 );

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
                    $caps[] = 'read';
                } elseif ( user_can( $user_id, 'manage_woocommerce' ) ) {
                    $caps[] = 'manage_woocommerce';
                } else {
                    $caps[] = 'do_not_allow';
                }
            } else {
                $caps[] = 'do_not_allow';
            }
            break;
    }
    return $caps;
}, 10, 4 );

// =========================================================================
// CUSTOM FUNCTIONS MOVED OUTSIDE THE CLASS 
// =========================================================================
function cielo_init_paypal_cart_manager() {
    if ( class_exists( 'WooCommerce' ) && class_exists( 'Cielo_PayPal_Cart_Manager' ) ) {
        new Cielo_PayPal_Cart_Manager();
    }
}

function cielo_register_hosting_product_type( $types ) {
    $types['skyhshoso'] = __( 'Website Hosting', 'cielo-hosting' );
    return $types;
}

// =========================================================================
// ACTIVATION & DEACTIVATION
// =========================================================================
function skyhshoso_hosting_solution_activate() {
    if ( ! SkyHSHOSO()->is_woocommerce_active() ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die( esc_html__( 'Cielo Hosting Solution requires WooCommerce to be installed and activated.', 'skyhs-hosting-solution' ) );
    }

    if ( ! class_exists( 'SkyHSHOSO_Subscription_DB' ) ) {
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-subscription-db.php';
    }
    SkyHSHOSO_Subscription_DB::install();
    SkyHSHOSO_Subscription_DB::maybe_install();

    if ( ! class_exists( 'SkyHSHOSO_UUID' ) ) {
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-uuid.php';
    }
    SkyHSHOSO_UUID::backfill_batch( 250 );

    if ( ! class_exists( 'SkyHSHOSO_Activity_Log' ) ) {
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-activity-log.php';
    }
    SkyHSHOSO_Activity_Log::install();

    if ( ! class_exists( 'SkyHSHOSO_Subscription_Cron' ) ) {
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-subscription-cron.php';
    }
    SkyHSHOSO_Subscription_Cron::schedule_events();

    if ( ! class_exists( 'SkyHSHOSO_Email_Campaign_DB' ) ) {
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-email-campaign-db.php';
    }
    SkyHSHOSO_Email_Campaign_DB::install();

    if ( ! class_exists( 'SkyHSHOSO_Email_Campaign' ) ) {
        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-email-campaign.php';
    }
    add_filter( 'cron_schedules', array( 'SkyHSHOSO_Email_Campaign', 'add_cron_schedules' ) );
    SkyHSHOSO_Email_Campaign::schedule_events();
}
register_activation_hook( __FILE__, 'skyhshoso_hosting_solution_activate' );

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
        'skyhshoso_server', 
        'side',
        'high'
    );
}

function skyhshoso_render_server_type_metabox($post) {
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
            var hostInput = $('input[name="_skyhshoso_whm_host"]');
            var userInput = $('input[name="_skyhshoso_whm_user_id"]');
            var tokenInput = $('input[name="_skyhshoso_whm_token"], textarea[name="_skyhshoso_whm_token"]');

            var hostLabel = hostInput.closest('tr, .acf-field, .inside').find('label').first();
            var userLabel = userInput.closest('tr, .acf-field, .inside').find('label').first();
            var tokenLabel = tokenInput.closest('tr, .acf-field, .inside').find('label').first();

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
    if (get_post_type($post_id) !== 'skyhshoso_hosting') return;

    $username = get_post_meta($post_id, 'skyhshoso_hosting_username', true);
    $sub_id   = get_post_meta($post_id, 'skyhshoso_subscription_id', true);

    if (!$username && !$sub_id) return;

    $args = array(
        'post_type'      => 'skyhshoso_wp_site',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => array('relation' => 'OR')
    );

    if ($username) {
        $args['meta_query'][] = array('key' => 'skyhshoso_wp_cpanel_user', 'value' => $username, 'compare' => '=');
    }

    if ($sub_id) {
        $args['meta_query'][] = array('key' => 'skyhshoso_subscription_id', 'value' => $sub_id, 'compare' => '=');
    }

    $wp_sites = get_posts($args);

    foreach ($wp_sites as $site_id) {
        if (current_action() === 'trashed_post') {
            wp_trash_post($site_id);
        } else {
            wp_delete_post($site_id, true);
        }
    }
}

add_filter('manage_skyhshoso_wp_site_posts_columns', 'skyhshoso_set_custom_wp_site_columns');
function skyhshoso_set_custom_wp_site_columns($columns) {
    $new_columns = array();
    foreach($columns as $key => $title) {
        if ($key == 'date') {
            $new_columns['cpanel_user'] = __('cPanel Account', 'skyhs-hosting-solution');
            $new_columns['server_name'] = __('Server', 'skyhs-hosting-solution');
            $new_columns['managed_status'] = __('Billing Status', 'skyhs-hosting-solution');
        }
        $new_columns[$key] = $title;
    }
    return $new_columns;
}

add_action('manage_skyhshoso_wp_site_posts_custom_column', 'skyhshoso_custom_wp_site_column_data', 10, 2);
function skyhshoso_custom_wp_site_column_data($column, $post_id) {
    if ($column === 'cpanel_user') {
        $user = get_post_meta($post_id, 'skyhshoso_wp_cpanel_user', true);
        echo !empty($user) ? esc_html($user) : '<em>Unknown</em>';
    }
    if ($column === 'server_name') {
        $server_id = get_post_meta($post_id, 'skyhshoso_server_id', true);
        echo $server_id ? esc_html(get_the_title($server_id)) : '<em>Unassigned</em>';
    }
    if ($column === 'managed_status') {
        $sub_id = get_post_meta($post_id, 'skyhshoso_subscription_id', true);
        if ($sub_id) {
            echo '<span style="color:green; font-weight:bold;">Managed (Sub #'.esc_html($sub_id).')</span>';
        } else {
            echo '<span style="color:#d63638; font-weight:bold;">Unmanaged (Discovered)</span>';
        }
    }
}

add_action('manage_posts_extra_tablenav', 'skyhshoso_add_sync_button_to_wp_sites');
function skyhshoso_add_sync_button_to_wp_sites($view) {
    global $typenow;
    if ($typenow == 'skyhshoso_wp_site' && $view === 'top') {
        ?>
        <div class="alignleft actions">
            <button type="button" id="skyhshoso-fleet-sync-btn" class="button button-primary">Discover WP Sites</button>
            <span id="skyhshoso-fleet-sync-status" style="margin-left:10px; font-weight:600;"></span>
        </div>
        <script>
        jQuery(document).ready(function($) {
            $('#skyhshoso-fleet-sync-btn').on('click', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var $status = $('#skyhshoso-fleet-sync-status');
                
                $btn.prop('disabled', true);
                $status.text('Fetching server list...').css('color', '#2271b1');

                $.post(ajaxurl, { action: 'skyhshoso_get_servers_for_sync' }, function(response) {
                    if (!response.success || response.data.servers.length === 0) {
                        $status.text('Failed to load servers.').css('color', '#d63638');
                        $btn.prop('disabled', false);
                        return;
                    }
                    
                    var servers = response.data.servers;
                    var currentIndex = 0;
                    var totalDiscovered = 0;

                    function syncNextServer() {
                        if (currentIndex >= servers.length) {
                            $status.text('Sync Complete! Discovered ' + totalDiscovered + ' new sites. Refreshing...').css('color', '#00a32a');
                            setTimeout(function(){ location.reload(); }, 1500);
                            return;
                        }

                        var server = servers[currentIndex];
                        $status.text('Syncing server: ' + server.name + ' (' + (currentIndex + 1) + '/' + servers.length + ')...');

                        $.post(ajaxurl, { 
                            action: 'skyhshoso_sync_single_server_wpsites', 
                            server_id: server.id 
                        }, function(res) {
                            if (res.success) totalDiscovered += res.data.imported;
                            currentIndex++;
                            syncNextServer(); 
                        }).fail(function() {
                            currentIndex++;
                            syncNextServer(); 
                        });
                    }
                    syncNextServer();
                });
            });
        });
        </script>
        <?php
    }
}

/**
 * =========================================================================
 * LIVE FLEET DASHBOARD (WP SITES PAGE OVERRIDE)
 * =========================================================================
 */
add_action('admin_footer-edit.php', 'skyhshoso_transform_wp_sites_page');
function skyhshoso_transform_wp_sites_page() {
    global $typenow;
    if ($typenow !== 'skyhshoso_wp_site') return;

    $servers = get_posts(['post_type' => 'skyhshoso_server', 'posts_per_page' => -1, 'order' => 'ASC', 'orderby' => 'title']);
    $server_data = [];
    foreach ($servers as $s) {
        $server_data[] = [
            'id' => $s->ID, 
            'name' => $s->post_title, 
            'type' => get_post_meta($s->ID, '_skyhshoso_server_type', true) ?: 'whm'
        ];
    }
    ?>
    <style>
        .wp-list-table, .tablenav, .search-box, .subsubsub { display: none !important; }
        #skyhs-fleet-dashboard { margin-top: 20px; max-width: 1200px; }
        .skyhs-fleet-server { border: 1px solid #ccd0d4; background: #fff; margin-bottom: 20px; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04); overflow: hidden; }
        .skyhs-fleet-server-header { background: #f6f7f7; padding: 15px 20px; border-bottom: 1px solid #ccd0d4; display: flex; justify-content: space-between; align-items: center; }
        .skyhs-fleet-server-header h2 { margin: 0; font-size: 16px; color: #1d2327; }
        .skyhs-fleet-server-body { padding: 10px 20px 20px 20px; }
        .skyhs-fleet-account-group { margin-top: 15px; border: 1px solid #e2e4e7; border-left: 4px solid #2271b1; padding: 0; border-radius: 3px; background: #fafafa;}
        .skyhs-fleet-account-header { padding: 10px 15px; border-bottom: 1px solid #e2e4e7; background: #fff; }
        .skyhs-fleet-account-header h3 { margin: 0; font-size: 14px; color: #1d2327; }
        .skyhs-fleet-site-list { list-style: none; margin: 0; padding: 0; }
        .skyhs-fleet-site-list li { padding: 10px 15px; border-bottom: 1px dashed #e2e4e7; display: flex; align-items: center; gap: 8px;}
        .skyhs-fleet-site-list li:last-child { border-bottom: none; }
        .skyhs-fleet-site-list a { text-decoration: none; font-weight: 600; font-size: 13px; }
    </style>
    <script>
    jQuery(document).ready(function($) {
        var servers = <?php echo json_encode($server_data); ?>;
        var container = $('<div id="skyhs-fleet-dashboard"></div>').insertAfter('.wrap h1');
        
        servers.forEach(function(server) {
            var sType = server.type === 'hestiacp' ? 'HestiaCP' : 'WHM';
            var html = '<div class="skyhs-fleet-server" id="fleet-server-'+server.id+'">';
            html += '<div class="skyhs-fleet-server-header"><h2><span class="dashicons dashicons-networking"></span> ' + server.name + '</h2><span style="font-size:11px; background:#e2e8f0; padding:3px 8px; border-radius:4px; font-weight:600;">' + sType + '</span></div>';
            html += '<div class="skyhs-fleet-server-body"><span class="spinner is-active" style="float:none; margin:0 5px 0 0;"></span> Scanning live server...</div>';
            html += '</div>';
            container.append(html);

            $.post(ajaxurl, { action: 'skyhshoso_scan_server_sites', server_id: server.id }, function(res) {
                var bodyDiv = $('#fleet-server-'+server.id+' .skyhs-fleet-server-body');
                
                if (!res.success) {
                    bodyDiv.html('<p style="color:#d63638;"><strong>Connection Error:</strong> ' + res.data.message + '</p>');
                    return;
                }

                if (res.data.sites.length === 0) {
                    bodyDiv.html('<p style="color:#646970;">No WordPress sites found on this server.</p>');
                    return;
                }

                var accounts = {};
                res.data.sites.forEach(function(site) {
                    if (!accounts[site.account]) {
                        accounts[site.account] = { type: site.type, sites: [] };
                    }
                    accounts[site.account].sites.push(site);
                });

                var renderHtml = '';
                for (var acc in accounts) {
                    renderHtml += '<div class="skyhs-fleet-account-group">';
                    renderHtml += '<div class="skyhs-fleet-account-header"><h3>Account: <code>' + acc + '</code> <span style="font-size:10px; color:#646970; text-transform:uppercase; margin-left:5px;">(' + accounts[acc].type + ')</span></h3></div>';
                    renderHtml += '<ul class="skyhs-fleet-site-list">';
                    
                    accounts[acc].sites.forEach(function(s) {
                        renderHtml += '<li><span class="dashicons dashicons-wordpress" style="color:#2271b1;"></span> <a href="'+s.url+'" target="_blank">' + s.domain + '</a></li>';
                    });
                    
                    renderHtml += '</ul></div>';
                }
                bodyDiv.html(renderHtml);
                
            }).fail(function() {
                $('#fleet-server-'+server.id+' .skyhs-fleet-server-body').html('<p style="color:#d63638;">Server Unreachable.</p>');
            });
        });

        if (servers.length === 0) {
            container.html('<div class="notice notice-warning inline"><p>No servers configured. Add a server in the Server Manager first.</p></div>');
        }
    });
    </script>
    <?php
}