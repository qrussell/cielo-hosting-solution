<?php
/**
 * Onboarding Wizard for SkyHS Hosting Solution
 *
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

class SkyHSHOSO_Onboarding_Wizard {

    /**
     * Singleton instance
     */
    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_wizard_page' ) );
        add_action( 'admin_notices', array( $this, 'display_setup_notice' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

        // AJAX handlers
        add_action( 'wp_ajax_skyhshoso_wizard_save_server', array( $this, 'ajax_save_server' ) );
        add_action( 'wp_ajax_skyhshoso_wizard_save_enom', array( $this, 'ajax_save_enom' ) );
        add_action( 'wp_ajax_skyhshoso_wizard_save_dashboard', array( $this, 'ajax_save_dashboard' ) );
    }

    /**
     * Add the wizard page to the admin menu (hidden)
     */
    public function add_wizard_page() {
        add_submenu_page(
            null, // Hide from menu
            __( 'SkyHS Setup Wizard', 'skyhs-hosting-solution' ),
            __( 'Setup Wizard', 'skyhs-hosting-solution' ),
            'manage_options',
            'skyhshoso-setup',
            array( $this, 'render_wizard_page' )
        );
    }

    /**
     * Display a notice if setup is not complete
     */
    public function display_setup_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( $screen && $screen->id === 'admin_page_skyhshoso-setup' ) {
            return;
        }

        if ( get_option( 'skyhshoso_setup_completed', false ) ) {
            return;
        }

        $setup_url = admin_url( 'admin.php?page=skyhshoso-setup' );
        ?>
        <div class="notice notice-info is-dismissible">
            <p><strong><?php esc_html_e( 'Welcome to SkyHS Hosting Solution!', 'skyhs-hosting-solution' ); ?></strong></p>
            <p><?php esc_html_e( 'Please run the setup wizard to configure your first server, connect your Enom account, and set up your dashboard.', 'skyhs-hosting-solution' ); ?></p>
            <p><a href="<?php echo esc_url( $setup_url ); ?>" class="button button-primary"><?php esc_html_e( 'Run Setup Wizard', 'skyhs-hosting-solution' ); ?></a></p>
        </div>
        <?php
    }

    /**
     * Enqueue scripts and styles for the wizard
     */
    public function enqueue_scripts( $hook ) {
        if ( $hook !== 'admin_page_skyhshoso-setup' ) {
            return;
        }

        wp_enqueue_style(
            'skyhshoso-wizard-css',
            SKYHSHOSO_PLUGIN_URL . 'assets/css/admin-wizard.css',
            array(),
            SKYHSHOSO_VERSION
        );

        wp_enqueue_script(
            'skyhshoso-wizard-js',
            SKYHSHOSO_PLUGIN_URL . 'assets/js/admin-wizard.js',
            array( 'jquery' ),
            SKYHSHOSO_VERSION,
            true
        );

        wp_localize_script(
            'skyhshoso-wizard-js',
            'skyhshoso_wizard_data',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'skyhshoso_wizard_nonce' ),
                'dashboard_url' => admin_url( 'admin.php?page=skyhshoso-dashboard' ),
                'strings'  => array(
                    'fill_all_fields' => __( 'Please fill in all required fields.', 'skyhs-hosting-solution' ),
                    'unknown_error'   => __( 'An unknown error occurred.', 'skyhs-hosting-solution' ),
                    'ajax_error'      => __( 'A communication error occurred. Please try again.', 'skyhs-hosting-solution' ),
                    'packages_found'  => __( 'Packages found with default feature list:', 'skyhs-hosting-solution' ),
                    'setup_complete'  => __( 'Setup Complete!', 'skyhs-hosting-solution' ),
                    'go_to_dashboard' => __( 'Go to SkyHS Dashboard', 'skyhs-hosting-solution' ),
                )
            )
        );
    }

    /**
     * Render the wizard page
     */
    public function render_wizard_page() {
        ?>
        <div class="skyhshoso-wizard-wrap">
            <div class="skyhshoso-wizard-header">
                <h1><?php esc_html_e( 'SkyHS Setup Wizard', 'skyhs-hosting-solution' ); ?></h1>
                <p><?php esc_html_e( 'Let\'s get your hosting solution up and running in a few quick steps.', 'skyhs-hosting-solution' ); ?></p>
            </div>

            <div class="skyhshoso-wizard-nav">
                <div class="skyhshoso-wizard-step-link active" data-step="1"><?php esc_html_e( '1. Server', 'skyhs-hosting-solution' ); ?></div>
                <div class="skyhshoso-wizard-step-link" data-step="2"><?php esc_html_e( '2. Enom', 'skyhs-hosting-solution' ); ?></div>
                <div class="skyhshoso-wizard-step-link" data-step="3"><?php esc_html_e( '3. Dashboard', 'skyhs-hosting-solution' ); ?></div>
            </div>

            <div class="skyhshoso-wizard-content">
                
                <!-- Step 1: Create Server -->
                <div id="step-1" class="skyhshoso-wizard-step active">
                    <h2><?php esc_html_e( 'Connect Your WHM Server', 'skyhs-hosting-solution' ); ?></h2>
                    <p><?php esc_html_e( 'Enter your WHM credentials. We will connect and fetch your packages automatically.', 'skyhs-hosting-solution' ); ?></p>
                    
                    <div class="skyhshoso-wizard-notice"></div>

                    <div class="skyhshoso-wizard-form-group">
                        <label for="server_name"><?php esc_html_e( 'Server Name', 'skyhs-hosting-solution' ); ?> <span style="color:red;">*</span></label>
                        <input type="text" id="server_name" name="server_name" placeholder="<?php esc_attr_e( 'e.g., US Server 1', 'skyhs-hosting-solution' ); ?>" />
                    </div>
                    <div class="skyhshoso-wizard-form-group">
                        <label for="whm_host"><?php esc_html_e( 'WHM Host', 'skyhs-hosting-solution' ); ?> <span style="color:red;">*</span></label>
                        <input type="text" id="whm_host" name="whm_host" placeholder="<?php esc_attr_e( 'e.g., node.example.com', 'skyhs-hosting-solution' ); ?>" />
                    </div>
                    <div class="skyhshoso-wizard-form-group">
                        <label for="whm_user_id"><?php esc_html_e( 'WHM User ID', 'skyhs-hosting-solution' ); ?> <span style="color:red;">*</span></label>
                        <input type="text" id="whm_user_id" name="whm_user_id" placeholder="<?php esc_attr_e( 'e.g., root', 'skyhs-hosting-solution' ); ?>" />
                    </div>
                    <div class="skyhshoso-wizard-form-group">
                        <label for="whm_token"><?php esc_html_e( 'WHM Token', 'skyhs-hosting-solution' ); ?> <span style="color:red;">*</span></label>
                        <input type="password" id="whm_token" name="whm_token" />
                    </div>

                    <div id="wizard-packages-container" class="skyhshoso-wizard-packages" style="display:none;"></div>

                    <div class="skyhshoso-wizard-actions">
                        <div></div> <!-- Empty for flex spacing -->
                        <div>
                            <span class="skyhshoso-loader"></span>
                            <button id="save-server-btn" class="skyhshoso-wizard-btn skyhshoso-wizard-btn-primary"><?php esc_html_e( 'Connect & Continue', 'skyhs-hosting-solution' ); ?></button>
                            <a href="#" class="skyhshoso-wizard-btn skyhshoso-wizard-btn-secondary skip-step" style="margin-left:10px;"><?php esc_html_e( 'Skip', 'skyhs-hosting-solution' ); ?></a>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Connect Enom -->
                <div id="step-2" class="skyhshoso-wizard-step">
                    <h2><?php esc_html_e( 'Connect Enom Reseller', 'skyhs-hosting-solution' ); ?></h2>
                    <p><?php esc_html_e( 'Connect your Enom account for domain registration services.', 'skyhs-hosting-solution' ); ?></p>
                    
                    <div class="skyhshoso-wizard-notice"></div>

                    <div class="skyhshoso-wizard-form-group">
                        <label for="enom_mode"><?php esc_html_e( 'Mode', 'skyhs-hosting-solution' ); ?></label>
                        <select id="enom_mode" name="enom_mode">
                            <option value="live"><?php esc_html_e( 'Live', 'skyhs-hosting-solution' ); ?></option>
                            <option value="test"><?php esc_html_e( 'Test', 'skyhs-hosting-solution' ); ?></option>
                        </select>
                    </div>
                    <div class="skyhshoso-wizard-form-group">
                        <label for="enom_live_username"><?php esc_html_e( 'Live Username', 'skyhs-hosting-solution' ); ?></label>
                        <input type="text" id="enom_live_username" name="enom_live_username" value="<?php echo esc_attr( get_option( 'skyhshoso_enom_live_username', '' ) ); ?>" />
                    </div>
                    <div class="skyhshoso-wizard-form-group">
                        <label for="enom_live_password"><?php esc_html_e( 'Live Password', 'skyhs-hosting-solution' ); ?></label>
                        <input type="password" id="enom_live_password" name="enom_live_password" value="<?php echo esc_attr( get_option( 'skyhshoso_enom_live_password', '' ) ); ?>" />
                    </div>
                    <div class="skyhshoso-wizard-form-group">
                        <label for="enom_test_username"><?php esc_html_e( 'Test Username', 'skyhs-hosting-solution' ); ?></label>
                        <input type="text" id="enom_test_username" name="enom_test_username" value="<?php echo esc_attr( get_option( 'skyhshoso_enom_test_username', '' ) ); ?>" />
                    </div>
                    <div class="skyhshoso-wizard-form-group">
                        <label for="enom_test_password"><?php esc_html_e( 'Test Password', 'skyhs-hosting-solution' ); ?></label>
                        <input type="password" id="enom_test_password" name="enom_test_password" value="<?php echo esc_attr( get_option( 'skyhshoso_enom_test_password', '' ) ); ?>" />
                    </div>

                    <div class="skyhshoso-wizard-actions">
                        <div></div>
                        <div>
                            <span class="skyhshoso-loader"></span>
                            <button id="save-enom-btn" class="skyhshoso-wizard-btn skyhshoso-wizard-btn-primary"><?php esc_html_e( 'Save & Continue', 'skyhs-hosting-solution' ); ?></button>
                            <a href="#" class="skyhshoso-wizard-btn skyhshoso-wizard-btn-secondary skip-step" style="margin-left:10px;"><?php esc_html_e( 'Skip', 'skyhs-hosting-solution' ); ?></a>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Setup Dashboard -->
                <div id="step-3" class="skyhshoso-wizard-step">
                    <h2><?php esc_html_e( 'Dashboard Setup', 'skyhs-hosting-solution' ); ?></h2>
                    <p><?php esc_html_e( 'We need a page to display the user dashboard using the shortcode [skyhshoso_dashboard].', 'skyhs-hosting-solution' ); ?></p>
                    
                    <div class="skyhshoso-wizard-notice"></div>

                    <div class="skyhshoso-wizard-form-group">
                        <label>
                            <input type="radio" name="dashboard_action" value="create" checked />
                            <?php esc_html_e( 'Create a new Dashboard page automatically', 'skyhs-hosting-solution' ); ?>
                        </label>
                    </div>
                    <div class="skyhshoso-wizard-form-group">
                        <label>
                            <input type="radio" name="dashboard_action" value="existing" />
                            <?php esc_html_e( 'Select an existing page', 'skyhs-hosting-solution' ); ?>
                        </label>
                    </div>

                    <div id="existing_page_container" class="skyhshoso-wizard-form-group" style="display:none; padding-left: 20px;">
                        <label for="existing_page_id"><?php esc_html_e( 'Choose Page', 'skyhs-hosting-solution' ); ?></label>
                        <?php
                        wp_dropdown_pages( array(
                            'name'             => 'existing_page_id',
                            'id'               => 'existing_page_id',
                            'show_option_none' => esc_html__( '— Select —', 'skyhs-hosting-solution' ),
                            'option_none_value' => '0',
                        ) );
                        ?>
                    </div>

                    <div class="skyhshoso-wizard-actions">
                        <div></div>
                        <div>
                            <span class="skyhshoso-loader"></span>
                            <button id="save-dashboard-btn" class="skyhshoso-wizard-btn skyhshoso-wizard-btn-primary"><?php esc_html_e( 'Complete Setup', 'skyhs-hosting-solution' ); ?></button>
                        </div>
                    </div>
                </div>

            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Save Server
     */
    public function ajax_save_server() {
        check_ajax_referer( 'skyhshoso_wizard_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Unauthorized', 'skyhs-hosting-solution' ) );
        }

        $server_name = isset( $_POST['server_name'] ) ? sanitize_text_field( wp_unslash( $_POST['server_name'] ) ) : '';
        $whm_user_id = isset( $_POST['whm_user_id'] ) ? sanitize_text_field( wp_unslash( $_POST['whm_user_id'] ) ) : '';
        $whm_token   = isset( $_POST['whm_token'] ) ? sanitize_text_field( wp_unslash( $_POST['whm_token'] ) ) : '';
        $whm_host    = isset( $_POST['whm_host'] ) ? sanitize_text_field( wp_unslash( $_POST['whm_host'] ) ) : '';

        if ( empty( $server_name ) || empty( $whm_user_id ) || empty( $whm_token ) || empty( $whm_host ) ) {
            wp_send_json_error( __( 'Missing required fields.', 'skyhs-hosting-solution' ) );
        }

        // Test connection first
        $whm = new SkyHSHOSO_WHM_Integration( $whm_user_id, $whm_token, $whm_host );
        $packages = $whm->get_packages();

        if ( is_wp_error( $packages ) ) {
            wp_send_json_error( $packages->get_error_message() );
        }

        // Connection successful, create server post
        $post_id = wp_insert_post( array(
            'post_title'  => $server_name,
            'post_type'   => 'skyhshoso_server',
            'post_status' => 'publish'
        ) );

        if ( is_wp_error( $post_id ) ) {
            wp_send_json_error( __( 'Failed to create server.', 'skyhs-hosting-solution' ) );
        }

        update_post_meta( $post_id, '_skyhshoso_whm_user_id', $whm_user_id );
        update_post_meta( $post_id, '_skyhshoso_whm_token', $whm_token );
        update_post_meta( $post_id, '_skyhshoso_whm_host', $whm_host );

        // Save packages
        $whm->save_packages( $post_id );

        // Get default packages to return
        $default_names = get_post_meta( $post_id, '_skyhshoso_whm_default_package_names', true );

        wp_send_json_success( array(
            'message' => __( 'Server connected and saved successfully!', 'skyhs-hosting-solution' ),
            'packages' => is_array($default_names) ? $default_names : array()
        ) );
    }

    /**
     * AJAX: Save Enom
     */
    public function ajax_save_enom() {
        check_ajax_referer( 'skyhshoso_wizard_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Unauthorized', 'skyhs-hosting-solution' ) );
        }

        $mode = isset( $_POST['enom_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['enom_mode'] ) ) : 'live';
        $live_user = isset( $_POST['enom_live_username'] ) ? sanitize_text_field( wp_unslash( $_POST['enom_live_username'] ) ) : '';
        $live_pass = isset( $_POST['enom_live_password'] ) ? sanitize_text_field( wp_unslash( $_POST['enom_live_password'] ) ) : '';
        $test_user = isset( $_POST['enom_test_username'] ) ? sanitize_text_field( wp_unslash( $_POST['enom_test_username'] ) ) : '';
        $test_pass = isset( $_POST['enom_test_password'] ) ? sanitize_text_field( wp_unslash( $_POST['enom_test_password'] ) ) : '';

        update_option( 'skyhshoso_enom_mode', in_array( $mode, array( 'live', 'test' ) ) ? $mode : 'live' );
        update_option( 'skyhshoso_enom_live_username', $live_user );
        update_option( 'skyhshoso_enom_live_password', $live_pass );
        update_option( 'skyhshoso_enom_test_username', $test_user );
        update_option( 'skyhshoso_enom_test_password', $test_pass );

        wp_send_json_success( array(
            'message' => __( 'Enom settings saved successfully!', 'skyhs-hosting-solution' )
        ) );
    }

    /**
     * AJAX: Save Dashboard
     */
    public function ajax_save_dashboard() {
        check_ajax_referer( 'skyhshoso_wizard_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Unauthorized', 'skyhs-hosting-solution' ) );
        }

        $action = isset( $_POST['dashboard_action'] ) ? sanitize_text_field( wp_unslash( $_POST['dashboard_action'] ) ) : 'create';
        $page_id = 0;

        if ( $action === 'create' ) {
            // Create a new page
            $post_id = wp_insert_post( array(
                'post_title'   => __( 'Client Dashboard', 'skyhs-hosting-solution' ),
                'post_content' => '[skyhshoso_dashboard]',
                'post_status' => 'publish',
                'post_type'    => 'page'
            ) );

            if ( is_wp_error( $post_id ) ) {
                wp_send_json_error( __( 'Failed to create dashboard page.', 'skyhs-hosting-solution' ) );
            }
            $page_id = $post_id;
        } else {
            // Use existing page
            $page_id = isset( $_POST['existing_page_id'] ) ? intval( wp_unslash( $_POST['existing_page_id'] ) ) : 0;
            if ( ! $page_id ) {
                wp_send_json_error( __( 'Please select a valid page.', 'skyhs-hosting-solution' ) );
            }

            // Optional: check if shortcode exists, if not append it
            $page = get_post( $page_id );
            if ( $page && strpos( $page->post_content, '[skyhshoso_dashboard]' ) === false ) {
                $updated_content = $page->post_content . "\n\n[skyhshoso_dashboard]";
                wp_update_post( array(
                    'ID'           => $page_id,
                    'post_content' => $updated_content
                ) );
            }
        }

        // Save page ID to settings
        $options = get_option( 'skyhshoso_settings_group', array() );
        $options['dashboard_page'] = $page_id;
        update_option( 'skyhshoso_settings_group', $options );

        // Mark setup as complete
        update_option( 'skyhshoso_setup_completed', true );
        update_option( 'skyhshoso_setup_completed_time', time() );

        wp_send_json_success( array(
            'message' => __( 'Dashboard configured successfully! Setup is now complete.', 'skyhs-hosting-solution' )
        ) );
    }

}

// Initialize Wizard
function SkyHSHOSO_Onboarding_Wizard() {
    return SkyHSHOSO_Onboarding_Wizard::instance();
}
SkyHSHOSO_Onboarding_Wizard();
