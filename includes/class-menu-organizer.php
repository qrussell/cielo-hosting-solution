<?php
/**
 * Menu Organizer for Hosting Solution
 *
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SkyHSHOSO_Menu_Organizer
 */
class SkyHSHOSO_Menu_Organizer {

    /**
     * Constructor
     */
    public function __construct() {
        // Hook into admin_menu with a later priority to ensure we run after other menu registrations
        add_action( 'admin_menu', array( $this, 'organize_menus' ), 999 );
        
        // Add admin scripts
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts() {
        wp_enqueue_script(
            'hosting-solution-admin-menu',
            SKYHSHOSO_PLUGIN_URL . 'assets/js/admin-menu.js',
            array('jquery'),
            SKYHSHOSO_VERSION,
            true
        );
    }

    /**
     * Organize admin menus
     */
    public function organize_menus() {
        global $menu, $submenu;

        // Remove default post type menu items we'll be reorganizing
        $this->remove_default_menus();

        // Add main parent menu "SKYHS"
        add_menu_page(
            __( 'SKYHS', 'skyhs-hosting-solution' ),
            __( 'SKYHS', 'skyhs-hosting-solution' ),
            'skyhshoso_view_dashboard',
            'skyhshoso-dashboard',
            array( $this, 'render_dashboard' ),
            'dashicons-admin-generic'
        );

        // Add Dashboard submenu
        add_submenu_page(
            'skyhshoso-dashboard',
            __( 'Dashboard', 'skyhs-hosting-solution' ),
            __( 'Dashboard', 'skyhs-hosting-solution' ),
            'skyhshoso_view_dashboard',
            'skyhshoso-dashboard',
            array( $this, 'render_dashboard' )
        );

        // Add Server submenu (custom page — no native WP post screen)
        add_submenu_page(
            'skyhshoso-dashboard',
            __( 'Servers', 'skyhs-hosting-solution' ),
            __( 'Servers', 'skyhs-hosting-solution' ),
            'skyhshoso_manage_servers',
            'skyhshoso-servers',
            array( SkyHSHOSO_Server_Manager::instance(), 'render_page' )
        );

        // Add Products submenu
        add_submenu_page(
            'skyhshoso-dashboard',
            __( 'Products', 'skyhs-hosting-solution' ),
            __( 'Products', 'skyhs-hosting-solution' ),
            'skyhshoso_manage_products',
            'skyhshoso-products',
            array( SkyHSHOSO_Product_Manager::instance(), 'render_page' )
        );

        // Add Hosting submenu (Custom unified Manager Page)
        add_submenu_page(
            'skyhshoso-dashboard',
            __( 'Hosting', 'skyhs-hosting-solution' ),
            __( 'Hosting', 'skyhs-hosting-solution' ),
            'skyhshoso_manage_hosting',
            'skyhshoso-hosting',
            array( SkyHSHOSO_Hosting_Manager::instance(), 'render_page' )
        );

        // Add WP Sites submenu
        add_submenu_page(
            'skyhshoso-dashboard',
            __( 'WordPress Sites', 'skyhs-hosting-solution' ),
            __( 'WP Sites', 'skyhs-hosting-solution' ),
            'skyhshoso_manage_hosting',
            'skyhshoso-wp-sites',
            array( SkyHSHOSO_WP_Site_Manager::instance(), 'render_page' )
        );

        // Add Domain submenu (Custom unified Manager Page)
        add_submenu_page(
            'skyhshoso-dashboard',
            __( 'Domains', 'skyhs-hosting-solution' ),
            __( 'Domains', 'skyhs-hosting-solution' ),
            'skyhshoso_manage_domains',
            'skyhshoso-domains',
            array( SkyHSHOSO_Domain_Manager::instance(), 'render_page' )
        );

        // Add Subscriptions submenu
        add_submenu_page(
            'skyhshoso-dashboard',
            __( 'Subscriptions', 'skyhs-hosting-solution' ),
            __( 'Subscriptions', 'skyhs-hosting-solution' ),
            'skyhshoso_manage_subscriptions',
            'skyhshoso-subscriptions',
            array( SkyHSHOSO_Subscription_Admin::instance(), 'render_page' )
        );

        // Add cPanel Sync submenu
        add_submenu_page(
            'skyhshoso-dashboard',
            __( 'cPanel Sync', 'skyhs-hosting-solution' ),
            __( 'cPanel Sync', 'skyhs-hosting-solution' ),
            'skyhshoso_manage_servers',
            'skyhshoso-cpanel-sync',
            array( SkyHSHOSO_CPanel_Sync::instance(), 'render_page' )
        );

        // Add ENOM Manager submenu
        add_submenu_page(
            'skyhshoso-dashboard',
            __( 'Enom Manager', 'skyhs-hosting-solution' ),
            __( 'Enom Manager', 'skyhs-hosting-solution' ),
            'skyhshoso_manage_enom_manager',
            'skyhshoso-enom-sync',
            array( SkyHSHOSO_Enom_Domain_Sync::instance(), 'render_page' )
        );

        // Add ENOM Settings submenu
        add_submenu_page(
            'skyhshoso-dashboard',
            __( 'Enom Settings', 'skyhs-hosting-solution' ),
            __( 'Enom Settings', 'skyhs-hosting-solution' ),
            'skyhshoso_manage_enom_settings',
            'skyhshoso-enom-settings',
            array(SkyHSHOSO_Enom_Integration::instance(), 'skyhshoso_render_enom_settings_page')
        );

        // Add Backup submenu
        add_submenu_page(
            'skyhshoso-dashboard',
            __( 'Backup', 'skyhs-hosting-solution' ),
            __( 'Backup', 'skyhs-hosting-solution' ),
            'skyhshoso_manage_backups',
            'skyhshoso-backups',
            array( 'SkyHSHOSO_Backup_Manager', 'render_page' )
        );

        // Add Customize submenu
        add_submenu_page(
            'skyhshoso-dashboard',
            __( 'Customize', 'skyhs-hosting-solution' ),
            __( 'Customize', 'skyhs-hosting-solution' ),
            'skyhshoso_manage_settings',
            'skyhshoso-customize',
            array( SkyHSHOSO_Customize::instance(), 'render_page' )
        );

        // Add SKYHS Settings submenu
        add_submenu_page(
            'skyhshoso-dashboard',
            __( 'Skyhs Settings', 'skyhs-hosting-solution' ),
            __( 'Skyhs Settings', 'skyhs-hosting-solution' ),
            'skyhshoso_manage_settings',
            'skyhshoso-settings',
            array( $this, 'render_skyhshoso_settings' )
        );

        // Add Import/Export submenu
        add_submenu_page(
            'skyhshoso-dashboard',
            __( 'Import/Export', 'skyhs-hosting-solution' ),
            __( 'Import/Export', 'skyhs-hosting-solution' ),
            'skyhshoso_manage_import_export',
            'skyhshoso-import-export',
            array( SkyHSHOSO_Import_Export_Admin::instance(), 'render_page' )
        );

        // Add Email Campaigns submenu
        add_submenu_page(
            'skyhshoso-dashboard',
            __( 'Email Campaigns', 'skyhs-hosting-solution' ),
            __( 'Email Campaigns', 'skyhs-hosting-solution' ),
            'skyhshoso_manage_email_campaigns',
            'skyhshoso-email-campaigns',
            array( SkyHSHOSO_Email_Campaign_Admin::instance(), 'render_page' )
        );
    }

    /**
     * Remove default post type menu items
     */
    private function remove_default_menus() {
        global $menu;

        // Remove original Hosting Solution Settings from WooCommerce menu
        remove_submenu_page('woocommerce', 'hosting-solution-settings');
    }

    /**
     * Render dashboard page
     */
    public function render_dashboard() {
        $server_count = wp_count_posts('skyhshoso_server')->publish ?? 0;
        $hosting_count = wp_count_posts('skyhshoso_hosting')->publish ?? 0;
        $domain_count = wp_count_posts('skyhshoso_domain')->publish ?? 0;
        
        ?>
        <div class="wrap skyhshoso-admin-dashboard">
            <h1><?php echo esc_html__('SKYHS Dashboard', 'skyhs-hosting-solution'); ?></h1>
            <p><?php echo esc_html__('Welcome to the SKYHS Hosting Solution dashboard. Here is a quick overview of your hosting environment.', 'skyhs-hosting-solution'); ?></p>
            
            <div class="skyhshoso-dashboard-widgets" style="display: flex; gap: 20px; margin-top: 30px; flex-wrap: wrap;">
                
                <!-- Servers Widget -->
                <div class="skyhshoso-widget" style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; flex: 1; min-width: 250px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                    <h3 style="margin-top: 0; color: #1d2327; font-size: 16px; border-bottom: 1px solid #f0f0f1; padding-bottom: 10px;"><?php esc_html_e('Servers', 'skyhs-hosting-solution'); ?></h3>
                    <div style="font-size: 36px; font-weight: 600; color: #2271b1; margin: 15px 0;"><?php echo intval($server_count); ?></div>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=skyhshoso-servers')); ?>" class="button"><?php esc_html_e('Manage Servers', 'skyhs-hosting-solution'); ?></a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=skyhshoso-servers')); ?>" class="button button-primary"><?php esc_html_e('Add New', 'skyhs-hosting-solution'); ?></a>
                </div>

                <!-- Hosting Accounts Widget -->
                <div class="skyhshoso-widget" style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; flex: 1; min-width: 250px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                    <h3 style="margin-top: 0; color: #1d2327; font-size: 16px; border-bottom: 1px solid #f0f0f1; padding-bottom: 10px;"><?php esc_html_e('Hosting Accounts', 'skyhs-hosting-solution'); ?></h3>
                    <div style="font-size: 36px; font-weight: 600; color: #2271b1; margin: 15px 0;"><?php echo intval($hosting_count); ?></div>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=skyhshoso-hosting')); ?>" class="button button-primary"><?php esc_html_e('Manage Hosting', 'skyhs-hosting-solution'); ?></a>
                </div>

                <!-- Domains Widget -->
                <div class="skyhshoso-widget" style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; flex: 1; min-width: 250px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                    <h3 style="margin-top: 0; color: #1d2327; font-size: 16px; border-bottom: 1px solid #f0f0f1; padding-bottom: 10px;"><?php esc_html_e('Registered Domains', 'skyhs-hosting-solution'); ?></h3>
                    <div style="font-size: 36px; font-weight: 600; color: #2271b1; margin: 15px 0;"><?php echo intval($domain_count); ?></div>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=skyhshoso-domains')); ?>" class="button button-primary"><?php esc_html_e('Manage Domains', 'skyhs-hosting-solution'); ?></a>
                </div>

            </div>
            
            <div style="margin-top: 30px;">
                <h2 style="font-size: 18px; color: #1d2327;"><?php esc_html_e('Quick Links', 'skyhs-hosting-solution'); ?></h2>
                <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                    <ul style="margin: 0; padding-left: 20px; font-size: 14px;">
                        <li style="margin-bottom: 10px;"><a href="<?php echo esc_url(admin_url('admin.php?page=skyhshoso-settings')); ?>"><?php esc_html_e('General Settings', 'skyhs-hosting-solution'); ?></a> - <?php esc_html_e('Configure your dashboard page and test mode.', 'skyhs-hosting-solution'); ?></li>
                        <li style="margin-bottom: 10px;"><a href="<?php echo esc_url(admin_url('admin.php?page=skyhshoso-enom-settings')); ?>"><?php esc_html_e('eNom Settings', 'skyhs-hosting-solution'); ?></a> - <?php esc_html_e('Update your domain registrar API credentials and markup pricing.', 'skyhs-hosting-solution'); ?></li>
                        <?php if ( ! get_option( 'skyhshoso_setup_completed', false ) ) : ?>
                        <li style="margin-bottom: 10px;"><a href="<?php echo esc_url(admin_url('admin.php?page=skyhshoso-setup')); ?>"><?php esc_html_e('Run Setup Wizard', 'skyhs-hosting-solution'); ?></a> - <?php esc_html_e('Relaunch the initial onboarding wizard.', 'skyhs-hosting-solution'); ?></li>
                        <?php endif; ?>
                        <li><a href="<?php echo esc_url(admin_url('admin.php?page=skyhshoso-products')); ?>"><?php esc_html_e('Create a Hosting Product', 'skyhs-hosting-solution'); ?></a> - <?php esc_html_e('Use the guided product creator — no WooCommerce knowledge needed.', 'skyhs-hosting-solution'); ?></li>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render SKYHS settings page (incorporates test mode settings)
     */
    public function render_skyhshoso_settings() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'general';
        $tabs = array(
            'general' => __( 'General', 'skyhs-hosting-solution' ),
        );

        // Allow other tabs to be added via filter
        $tabs = apply_filters( 'skyhshoso_settings_tabs', $tabs );

        ?>
        <div class="skyhshoso-wizard-wrap">
            <div class="skyhshoso-wizard-header">
                <h1><?php echo esc_html__('Skyhs Settings', 'skyhs-hosting-solution'); ?></h1>
                <p><?php esc_html_e( 'Configure general plugin settings like test mode and dashboard page.', 'skyhs-hosting-solution' ); ?></p>
            </div>

            <div class="skyhshoso-wizard-nav">
                <?php foreach ( $tabs as $tab_id => $tab_name ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=skyhshoso-settings&tab=' . $tab_id ) ); ?>" class="skyhshoso-wizard-step-link <?php echo $current_tab === $tab_id ? 'active' : ''; ?>" style="text-decoration:none;"><?php echo esc_html( $tab_name ); ?></a>
                <?php endforeach; ?>
            </div>

            <div class="skyhshoso-wizard-content">
                <?php if ( $current_tab === 'general' ) : ?>
                    <?php $options = get_option( 'skyhshoso_settings_group', array() ); ?>
                    <form method="post" action="options.php">
                        <?php settings_fields('skyhshoso_settings_general'); ?>

                        <div class="skyhshoso-wizard-form-group">
                            <label><?php esc_html_e( 'Test Mode', 'skyhs-hosting-solution' ); ?></label>
                            <label style="font-weight:400; display:block; margin-bottom:8px;">
                                <input type="hidden" name="skyhshoso_settings_group[test_mode]" value="0">
                                <input type="checkbox" name="skyhshoso_settings_group[test_mode]" value="1" <?php checked( 1, ! empty( $options['test_mode'] ) ); ?>>
                                <?php esc_html_e( 'Disable outgoing emails (test mode for emails)', 'skyhs-hosting-solution' ); ?>
                            </label>
                            <label style="font-weight:400; display:block;">
                                <input type="hidden" name="skyhshoso_settings_group[disable_subscription_processing]" value="0">
                                <input type="checkbox" name="skyhshoso_settings_group[disable_subscription_processing]" value="1" <?php checked( 1, ! empty( $options['disable_subscription_processing'] ) ); ?>>
                                <?php esc_html_e( 'Disable subscription processing (hosting and domain webhooks will not run)', 'skyhs-hosting-solution' ); ?>
                            </label>
                        </div>

                        <div class="skyhshoso-wizard-form-group">
                            <label><?php esc_html_e( 'Disable Domain Registration', 'skyhs-hosting-solution' ); ?></label>
                            <label style="font-weight:400;">
                                <input type="hidden" name="skyhshoso_settings_group[disable_domain_registration]" value="0">
                                <input type="checkbox" name="skyhshoso_settings_group[disable_domain_registration]" value="1" <?php checked( 1, ! empty( $options['disable_domain_registration'] ) ); ?>>
                                <?php esc_html_e( 'Disable domain registration from both admin backend and frontend completely', 'skyhs-hosting-solution' ); ?>
                            </label>
                        </div>

                        <div class="skyhshoso-wizard-form-group">
                            <label><?php esc_html_e( 'Dashboard Page', 'skyhs-hosting-solution' ); ?></label>
                            <?php
                            wp_dropdown_pages( array(
                                'name'              => 'skyhshoso_settings_group[dashboard_page]',
                                'id'                => 'dashboard_page',
                                'echo'              => 1,
                                'show_option_none'  => esc_html__( '— Select —', 'skyhs-hosting-solution' ),
                                'option_none_value' => '0',
                                'selected'          => ! empty( $options['dashboard_page'] ) ? absint( $options['dashboard_page'] ) : 0,
                            ) );
                            ?>
                            <p style="font-size:12px;color:#646970;margin:8px 0 0 0;"><?php esc_html_e( 'Select the page where your SkyHS Dashboard shortcode [skyhshoso_dashboard] is displayed.', 'skyhs-hosting-solution' ); ?></p>
                        </div>

                        <div class="skyhshoso-wizard-form-group">
                            <label><?php esc_html_e( 'Enable WC Log', 'skyhs-hosting-solution' ); ?></label>
                            <label style="font-weight:400;">
                                <input type="hidden" name="skyhshoso_settings_group[enable_wc_log]" value="0">
                                <input type="checkbox" name="skyhshoso_settings_group[enable_wc_log]" value="1" <?php checked( 1, ! empty( $options['enable_wc_log'] ) ); ?>>
                                <?php esc_html_e( 'Log server, hosting, domain, WordPress, and subscription creation failures to WooCommerce logs (WooCommerce > Status > Logs)', 'skyhs-hosting-solution' ); ?>
                            </label>
                        </div>

                        <div class="skyhshoso-wizard-actions">
                            <div></div>
                            <div>
                                <button type="submit" class="skyhshoso-wizard-btn skyhshoso-wizard-btn-primary"><?php esc_html_e( 'Save Settings', 'skyhs-hosting-solution' ); ?></button>
                            </div>
                        </div>
                    </form>
                <?php else : ?>
                    <?php do_action( 'skyhshoso_settings_tab_' . $current_tab ); ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

}

// Initialize the menu organizer
new SkyHSHOSO_Menu_Organizer();
