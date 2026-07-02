<?php
/**
 * Hosting Solution Settings
 *
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SkyHSHOSO_Settings
 */
class SkyHSHOSO_Settings {

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

        // Filter for controlling emails
        add_filter( 'wp_mail', array( $this, 'maybe_disable_emails' ), 999 );

        // Register settings tabs and content.
        add_filter( 'skyhshoso_settings_tabs', array( $this, 'add_settings_tabs' ) );
        add_action( 'skyhshoso_settings_tab_billing',  array( $this, 'render_billing_tab' ) );
        add_action( 'skyhshoso_settings_tab_emails',   array( $this, 'render_emails_tab' ) );
        add_action( 'skyhshoso_settings_tab_invoice',  array( $this, 'render_invoice_tab' ) );
        add_action( 'skyhshoso_settings_tab_email_templates',  array( $this, 'render_email_templates_tab' ) );
        add_action( 'skyhshoso_settings_tab_logs',             array( $this, 'render_logs_tab' ) );

        // Register AJAX handlers for test emails and preview
        add_action( 'wp_ajax_skyhshoso_test_email', array( $this, 'ajax_send_test_email' ) );
        add_action( 'wp_ajax_skyhshoso_preview_email', array( $this, 'ajax_preview_email' ) );

        // Initialize hooks
        $this->init_hooks();

        // Add redirect for product pages
        add_action( 'template_redirect', array( $this, 'redirect_products_to_dashboard' ) );
        add_action( 'template_redirect', array( $this, 'redirect_post_types_to_dashboard' ) );
    }

    /**
     * Add settings tabs.
     *
     * @param array $tabs
     * @return array
     */
    public function add_settings_tabs( $tabs ) {
        $tabs['billing']          = __( 'Billing/Subscription', 'skyhs-hosting-solution' );
        $tabs['emails']           = __( 'Emails', 'skyhs-hosting-solution' );
        $tabs['invoice']          = __( 'Invoice', 'skyhs-hosting-solution' );
        $tabs['email_templates']  = __( 'Email Templates', 'skyhs-hosting-solution' );
        $tabs['logs']             = __( 'Activity Log', 'skyhs-hosting-solution' );
        return $tabs;
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Add admin notice when test mode is disabled
        add_action( 'admin_notices', array( $this, 'maybe_show_test_mode_notice' ) );
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        // Each tab gets its OWN settings group so WordPress only processes
        // the current tab's registered options when its form is saved.
        register_setting(
            'skyhshoso_settings_general',
            'skyhshoso_settings_group',
            array( $this, 'sanitize_settings' )
        );

        register_setting(
            'skyhshoso_settings_billing',
            'skyhshoso_settings_group',
            array( $this, 'sanitize_settings' )
        );
        register_setting(
            'skyhshoso_settings_billing',
            'skyhs_hosting_solution_enable_early_renewal',
            array( 'sanitize_callback' => 'sanitize_text_field' )
        );
        register_setting(
            'skyhshoso_settings_billing',
            'skyhshoso_drip_downloadable_content_on_renewal',
            array( 'sanitize_callback' => 'sanitize_text_field' )
        );
        register_setting(
            'skyhshoso_settings_billing',
            'skyhshoso_zero_initial_payment_requires_payment',
            array( 'sanitize_callback' => 'sanitize_text_field' )
        );
        register_setting(
            'skyhshoso_settings_billing',
            'skyhshoso_accept_manual_renewals',
            array( 'sanitize_callback' => 'sanitize_text_field' )
        );
        register_setting(
            'skyhshoso_settings_billing',
            'skyhshoso_turn_off_automatic_payments',
            array( 'sanitize_callback' => 'sanitize_text_field' )
        );
        register_setting(
            'skyhshoso_settings_billing',
            'skyhshoso_allow_switching',
            array( 'sanitize_callback' => array( $this, 'sanitize_allow_switching' ) )
        );
        register_setting(
            'skyhshoso_settings_billing',
            'skyhshoso_apportion_recurring_price',
            array( 'sanitize_callback' => 'sanitize_text_field' )
        );
        register_setting(
            'skyhshoso_settings_billing',
            'skyhshoso_apportion_sign_up_fee',
            array( 'sanitize_callback' => 'sanitize_text_field' )
        );
        register_setting(
            'skyhshoso_settings_billing',
            'skyhshoso_apportion_length',
            array( 'sanitize_callback' => 'sanitize_text_field' )
        );

        register_setting(
            'skyhshoso_settings_emails',
            'skyhshoso_settings_group',
            array( $this, 'sanitize_settings' )
        );

        register_setting(
            'skyhshoso_settings_invoice',
            'skyhshoso_settings_group',
            array( $this, 'sanitize_settings' )
        );

        register_setting(
            'skyhshoso_settings_customize',
            'skyhshoso_settings_group',
            array( $this, 'sanitize_settings' )
        );

        register_setting(
            'skyhshoso_settings_email_templates',
            'skyhshoso_settings_group',
            array( $this, 'sanitize_settings' )
        );

        add_settings_section(
            'hosting_solution_general_section',
            __( 'General Settings', 'skyhs-hosting-solution' ),
            array( $this, 'render_general_section' ),
            'skyhshoso_settings_group'
        );

        add_settings_field(
            'test_mode',
            __( 'Test Mode', 'skyhs-hosting-solution' ),
            array( $this, 'render_test_mode_field' ),
            'skyhshoso_settings_group',
            'hosting_solution_general_section'
        );

        add_settings_field(
            'disable_subscription_processing',
            __( 'Disable Subscription Processing', 'skyhs-hosting-solution' ),
            array( $this, 'render_disable_subscription_processing_field' ),
            'skyhshoso_settings_group',
            'hosting_solution_general_section'
        );
        
        add_settings_field(
            'disable_domain_registration',
            __( 'Disable Domain Registration', 'skyhs-hosting-solution' ),
            array( $this, 'render_disable_domain_registration_field' ),
            'skyhshoso_settings_group',
            'hosting_solution_general_section'
        );
        
		add_settings_field(
			'dashboard_page',
			__( 'Dashboard Page', 'skyhs-hosting-solution' ),
			array( $this, 'render_dashboard_page_field' ),
			'skyhshoso_settings_group',
			'hosting_solution_general_section'
		);

		add_settings_field(
			'enable_wc_log',
			__( 'Enable WC Log', 'skyhs-hosting-solution' ),
			array( $this, 'render_enable_wc_log_field' ),
			'skyhshoso_settings_group',
			'hosting_solution_general_section'
		);

	}

    /**
     * Sanitize settings
     *
     * @param array $input The settings input.
     * @return array
     */
    public function sanitize_settings( $input ) {
        $existing = get_option( 'skyhshoso_settings_group', array() );
        if ( ! is_array( $existing ) ) {
            $existing = array();
        }

        $sanitized_input = $existing;

        // Only process fields that are present in the submitted form.
        // Each tab's form only sends its own fields and is registered under
        // its own settings group, so cross-tab interference is impossible.

        // --- General settings ---
        if ( isset( $input['test_mode'] ) ) {
            $sanitized_input['test_mode'] = ! empty( $input['test_mode'] ) ? 1 : 0;
        }
        if ( isset( $input['disable_subscription_processing'] ) ) {
            $sanitized_input['disable_subscription_processing'] = ! empty( $input['disable_subscription_processing'] ) ? 1 : 0;
        }
        if ( isset( $input['disable_domain_registration'] ) ) {
            $sanitized_input['disable_domain_registration'] = ! empty( $input['disable_domain_registration'] ) ? 1 : 0;
        }
		if ( isset( $input['dashboard_page'] ) ) {
			$sanitized_input['dashboard_page'] = absint( $input['dashboard_page'] );
		}
		if ( isset( $input['enable_wc_log'] ) ) {
			$sanitized_input['enable_wc_log'] = ! empty( $input['enable_wc_log'] ) ? 1 : 0;
		}
        // --- NEW: Sanitize WP Base Domains ---
        if ( isset( $input['wp_base_domains'] ) ) {
            $sanitized_input['wp_base_domains'] = sanitize_text_field( $input['wp_base_domains'] );
        }

		// --- Billing settings ---
        if ( isset( $input['grace_period_days'] ) ) {
            $sanitized_input['grace_period_days'] = absint( $input['grace_period_days'] );
        }
        if ( isset( $input['failed_payment_threshold'] ) ) {
            $sanitized_input['failed_payment_threshold'] = absint( $input['failed_payment_threshold'] );
        }
        if ( isset( $input['reminder_days'] ) ) {
            $sanitized_input['reminder_days'] = absint( $input['reminder_days'] );
        }
        if ( isset( $input['deletion_reminder_days'] ) ) {
            $sanitized_input['deletion_reminder_days'] = absint( $input['deletion_reminder_days'] );
        }

        // --- Email settings ---
        if ( isset( $input['email_provisioning'] ) ) {
            $sanitized_input['email_provisioning'] = ! empty( $input['email_provisioning'] ) ? 1 : 0;
        }
        if ( isset( $input['email_suspension'] ) ) {
            $sanitized_input['email_suspension'] = ! empty( $input['email_suspension'] ) ? 1 : 0;
        }
        if ( isset( $input['email_termination_notice'] ) ) {
            $sanitized_input['email_termination_notice'] = ! empty( $input['email_termination_notice'] ) ? 1 : 0;
        }
        if ( isset( $input['email_terminated'] ) ) {
            $sanitized_input['email_terminated'] = ! empty( $input['email_terminated'] ) ? 1 : 0;
        }
        if ( isset( $input['email_reminder'] ) ) {
            $sanitized_input['email_reminder'] = ! empty( $input['email_reminder'] ) ? 1 : 0;
        }
        if ( isset( $input['email_deletion_warning'] ) ) {
            $sanitized_input['email_deletion_warning'] = ! empty( $input['email_deletion_warning'] ) ? 1 : 0;
        }

        // --- Invoice settings ---
        if ( isset( $input['invoice_company_name'] ) ) {
            $sanitized_input['invoice_company_name'] = sanitize_text_field( $input['invoice_company_name'] );
        }
        if ( isset( $input['invoice_address'] ) ) {
            $sanitized_input['invoice_address'] = sanitize_textarea_field( $input['invoice_address'] );
        }
        if ( isset( $input['invoice_footer'] ) ) {
            $sanitized_input['invoice_footer'] = sanitize_text_field( $input['invoice_footer'] );
        }

        // --- Customize settings ---
        if ( isset( $input['custom_logo'] ) ) {
            $sanitized_input['custom_logo'] = esc_url_raw( $input['custom_logo'] );
        }
        if ( isset( $input['custom_sitename'] ) ) {
            $sanitized_input['custom_sitename'] = sanitize_text_field( $input['custom_sitename'] );
        }
        if ( isset( $input['show_only_logo'] ) ) {
            $sanitized_input['show_only_logo'] = ! empty( $input['show_only_logo'] ) ? 1 : 0;
        }
        if ( isset( $input['guest_welcome_title'] ) ) {
            $sanitized_input['guest_welcome_title'] = sanitize_text_field( $input['guest_welcome_title'] );
        }
        if ( isset( $input['guest_welcome_subtitle'] ) ) {
            $sanitized_input['guest_welcome_subtitle'] = sanitize_textarea_field( $input['guest_welcome_subtitle'] );
        }
        if ( isset( $input['enable_guest_dashboard'] ) ) {
            $sanitized_input['enable_guest_dashboard'] = ! empty( $input['enable_guest_dashboard'] ) ? 1 : 0;
        }
        if ( isset( $input['back_to_site_url'] ) ) {
            $sanitized_input['back_to_site_url'] = esc_url_raw( $input['back_to_site_url'] );
        }
        if ( isset( $input['guest_welcome_btn_text'] ) ) {
            $sanitized_input['guest_welcome_btn_text'] = sanitize_text_field( $input['guest_welcome_btn_text'] );
        }
        if ( isset( $input['guest_welcome_btn_url'] ) ) {
            $sanitized_input['guest_welcome_btn_url'] = esc_url_raw( $input['guest_welcome_btn_url'] );
        }
        if ( isset( $input['header_menu_id'] ) ) {
            $sanitized_input['header_menu_id'] = sanitize_text_field( $input['header_menu_id'] );
        }

        // --- Email templates ---
        $email_types = array( 'provisioning', 'suspension', 'termination_notice', 'terminated', 'reminder', 'deletion_warning' );
        foreach ( $email_types as $etype ) {
            if ( isset( $input[ "email_subject_{$etype}" ] ) ) {
                $sanitized_input[ "email_subject_{$etype}" ] = sanitize_text_field( $input[ "email_subject_{$etype}" ] );
            }
            if ( isset( $input[ "email_body_{$etype}" ] ) ) {
                $sanitized_input[ "email_body_{$etype}" ] = wp_kses_post( $input[ "email_body_{$etype}" ] );
            }
        }

        return $sanitized_input;
    }

    /**
     * Sanitize allow switching option from checkboxes.
     */
    public function sanitize_allow_switching( $input ) {
        $allow_variable = isset( $_POST['skyhshoso_allow_switching_variable'] ) ? 'variable' : '';
        $allow_grouped  = isset( $_POST['skyhshoso_allow_switching_grouped'] ) ? 'grouped' : '';

        if ( $allow_variable && $allow_grouped ) {
            return 'variable_grouped';
        } elseif ( $allow_variable ) {
            return 'variable';
        } elseif ( $allow_grouped ) {
            return 'grouped';
        } else {
            return 'no';
        }
    }

    /**
     * Render general section
     */
    public function render_general_section() {
        echo '<p>' . esc_html__( 'Configure general settings for Hosting Solution plugin.', 'skyhs-hosting-solution' ) . '</p>';
    }

    // -------------------------------------------------------------------------
    // Billing tab
    // -------------------------------------------------------------------------

    /**
     * Render billing settings tab.
     */
    public function render_billing_tab() {
        $options = get_option( 'skyhshoso_settings_group', array() );
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'skyhshoso_settings_billing' ); ?>

            <div class="skyhshoso-wizard-form-group">
                <label><?php esc_html_e( 'Grace Period (Days)', 'skyhs-hosting-solution' ); ?></label>
                <input type="number" name="skyhshoso_settings_group[grace_period_days]" value="<?php echo esc_attr( isset( $options['grace_period_days'] ) ? absint( $options['grace_period_days'] ) : 30 ); ?>" min="0" max="365" style="width:100px;">
                <p style="font-size:12px;color:#646970;margin:8px 0 0 0;"><?php esc_html_e( 'Days after cancellation before the hosting account is terminated.', 'skyhs-hosting-solution' ); ?></p>
            </div>

            <div class="skyhshoso-wizard-form-group">
                <label><?php esc_html_e( 'Failed Payment Threshold', 'skyhs-hosting-solution' ); ?></label>
                <input type="number" name="skyhshoso_settings_group[failed_payment_threshold]" value="<?php echo esc_attr( isset( $options['failed_payment_threshold'] ) ? absint( $options['failed_payment_threshold'] ) : 3 ); ?>" min="1" max="20" style="width:100px;">
                <p style="font-size:12px;color:#646970;margin:8px 0 0 0;"><?php esc_html_e( 'Consecutive failed payments before the subscription is suspended.', 'skyhs-hosting-solution' ); ?></p>
            </div>

            <div class="skyhshoso-wizard-form-group">
                <label><?php esc_html_e( 'Renewal Reminder (Days Before)', 'skyhs-hosting-solution' ); ?></label>
                <input type="number" name="skyhshoso_settings_group[reminder_days]" value="<?php echo esc_attr( isset( $options['reminder_days'] ) ? absint( $options['reminder_days'] ) : 3 ); ?>" min="0" max="60" style="width:100px;">
                <p style="font-size:12px;color:#646970;margin:8px 0 0 0;"><?php esc_html_e( 'Days before the next payment date to send a renewal reminder email.', 'skyhs-hosting-solution' ); ?></p>
            </div>

            <div class="skyhshoso-wizard-form-group">
                <label><?php esc_html_e( 'Deletion Warning (Days Before)', 'skyhs-hosting-solution' ); ?></label>
                <input type="number" name="skyhshoso_settings_group[deletion_reminder_days]" value="<?php echo esc_attr( isset( $options['deletion_reminder_days'] ) ? absint( $options['deletion_reminder_days'] ) : 3 ); ?>" min="0" max="60" style="width:100px;">
                <p style="font-size:12px;color:#646970;margin:8px 0 0 0;"><?php esc_html_e( 'Days before the scheduled termination date to send a final deletion reminder email.', 'skyhs-hosting-solution' ); ?></p>
            </div>

            <div class="skyhshoso-wizard-form-group">
                <label><?php esc_html_e( 'Early Renewal', 'skyhs-hosting-solution' ); ?></label>
                <label style="font-weight:400;">
                    <input type="hidden" name="skyhs_hosting_solution_enable_early_renewal" value="no">
                    <input type="checkbox" name="skyhs_hosting_solution_enable_early_renewal" value="yes" <?php checked( 'yes', get_option( 'skyhs_hosting_solution_enable_early_renewal', 'no' ) ); ?>>
                    <?php esc_html_e( 'Allow customers to renew their subscriptions early.', 'skyhs-hosting-solution' ); ?>
                </label>
            </div>

            <div class="skyhshoso-wizard-form-group">
                <label><?php esc_html_e( 'Drip Downloadable Content', 'skyhs-hosting-solution' ); ?></label>
                <label style="font-weight:400;">
                    <input type="hidden" name="skyhshoso_drip_downloadable_content_on_renewal" value="no">
                    <input type="checkbox" name="skyhshoso_drip_downloadable_content_on_renewal" value="yes" <?php checked( 'yes', get_option( 'skyhshoso_drip_downloadable_content_on_renewal', 'no' ) ); ?>>
                    <?php esc_html_e( 'Drip downloadable content on renewal (grants access to new files only after next renewal payment).', 'skyhs-hosting-solution' ); ?>
                </label>
            </div>

            <div class="skyhshoso-wizard-form-group">
                <label><?php esc_html_e( '$0 Initial Checkout', 'skyhs-hosting-solution' ); ?></label>
                <label style="font-weight:400;">
                    <input type="hidden" name="skyhshoso_zero_initial_payment_requires_payment" value="yes">
                    <input type="checkbox" name="skyhshoso_zero_initial_payment_requires_payment" value="no" <?php checked( 'no', get_option( 'skyhshoso_zero_initial_payment_requires_payment', 'no' ) ); ?>>
                    <?php esc_html_e( 'Allow $0 initial checkout without a payment method (customers will only need a payment method at the end of the initial period).', 'skyhs-hosting-solution' ); ?>
                </label>
            </div>

            <div class="skyhshoso-wizard-form-group">
                <label><?php esc_html_e( 'Manual Renewal Payments', 'skyhs-hosting-solution' ); ?></label>
                <label style="font-weight:400; display:block; margin-bottom:8px;">
                    <input type="hidden" name="skyhshoso_accept_manual_renewals" value="no">
                    <input type="checkbox" name="skyhshoso_accept_manual_renewals" value="yes" <?php checked( 'yes', get_option( 'skyhshoso_accept_manual_renewals', 'no' ) ); ?>>
                    <?php esc_html_e( 'Accept Manual Renewals (puts subscriptions on-hold until the customer logs in and pays to renew).', 'skyhs-hosting-solution' ); ?>
                </label>
                <label style="font-weight:400; display:block;">
                    <input type="hidden" name="skyhshoso_turn_off_automatic_payments" value="no">
                    <input type="checkbox" name="skyhshoso_turn_off_automatic_payments" value="yes" <?php checked( 'yes', get_option( 'skyhshoso_turn_off_automatic_payments', 'no' ) ); ?>>
                    <?php esc_html_e( 'Turn off Automatic Payments (forces all new subscription purchases to use manual renewals).', 'skyhs-hosting-solution' ); ?>
                </label>
            </div>

            <h3 style="margin-top: 30px; border-top: 1px solid #dcdcde; padding-top: 20px;"><?php esc_html_e( 'Subscription Switching Settings', 'skyhs-hosting-solution' ); ?></h3>
            <p style="margin:0 0 16px 0;color:#646970;"><?php esc_html_e( 'Allow customers to upgrade, downgrade, or change their subscription plans.', 'skyhs-hosting-solution' ); ?></p>

            <?php
            $allow_switching = get_option( 'skyhshoso_allow_switching', 'no' );
            if ( ! in_array( $allow_switching, array( 'no', 'variable', 'grouped', 'variable_grouped' ) ) ) {
                $allow_switching = 'no';
            }
            $allow_variable_checked = strpos( $allow_switching, 'variable' ) !== false;
            $allow_grouped_checked  = strpos( $allow_switching, 'grouped' ) !== false;

            $apportion_recurring = get_option( 'skyhshoso_apportion_recurring_price', 'no' );
            $apportion_sign_up   = get_option( 'skyhshoso_apportion_sign_up_fee', 'no' );
            $apportion_length    = get_option( 'skyhshoso_apportion_length', 'no' );
            ?>

            <div class="skyhshoso-wizard-form-group">
                <label><?php esc_html_e( 'Allow Switching', 'skyhs-hosting-solution' ); ?></label>
                <input type="hidden" name="skyhshoso_allow_switching" value="1">
                <label style="display:block;margin-bottom:6px;font-weight:400;">
                    <input type="checkbox" name="skyhshoso_allow_switching_variable" value="1" <?php checked( $allow_variable_checked ); ?>>
                    <?php esc_html_e( 'Between Subscription Variations', 'skyhs-hosting-solution' ); ?>
                </label>
                <label style="display:block;font-weight:400;">
                    <input type="checkbox" name="skyhshoso_allow_switching_grouped" value="1" <?php checked( $allow_grouped_checked ); ?>>
                    <?php esc_html_e( 'Between Grouped Subscriptions', 'skyhs-hosting-solution' ); ?>
                </label>
                <p style="font-size:12px;color:#646970;margin:8px 0 0 0;"><?php esc_html_e( 'Enable the ability for customers to switch between different subscription products or variations.', 'skyhs-hosting-solution' ); ?></p>
            </div>

            <div class="skyhshoso-wizard-form-group">
                <label for="skyhshoso_apportion_recurring_price"><?php esc_html_e( 'Prorate Recurring Payment', 'skyhs-hosting-solution' ); ?></label>
                <select id="skyhshoso_apportion_recurring_price" name="skyhshoso_apportion_recurring_price" style="min-width:250px;">
                    <option value="no" <?php selected( $apportion_recurring, 'no' ); ?>><?php esc_html_e( 'Never', 'skyhs-hosting-solution' ); ?></option>
                    <option value="virtual-upgrade" <?php selected( $apportion_recurring, 'virtual-upgrade' ); ?>><?php esc_html_e( 'For Upgrades of Virtual Subscription Products Only', 'skyhs-hosting-solution' ); ?></option>
                    <option value="yes-upgrade" <?php selected( $apportion_recurring, 'yes-upgrade' ); ?>><?php esc_html_e( 'For Upgrades of All Subscription Products', 'skyhs-hosting-solution' ); ?></option>
                    <option value="virtual" <?php selected( $apportion_recurring, 'virtual' ); ?>><?php esc_html_e( 'For Upgrades & Downgrades of Virtual Subscription Products Only', 'skyhs-hosting-solution' ); ?></option>
                    <option value="yes" <?php selected( $apportion_recurring, 'yes' ); ?>><?php esc_html_e( 'For Upgrades & Downgrades of All Subscription Products', 'skyhs-hosting-solution' ); ?></option>
                </select>
                <p style="font-size:12px;color:#646970;margin:8px 0 0 0;"><?php esc_html_e( 'When switching, should the price paid for the existing billing period be prorated?', 'skyhs-hosting-solution' ); ?></p>
            </div>

            <div class="skyhshoso-wizard-form-group">
                <label for="skyhshoso_apportion_sign_up_fee"><?php esc_html_e( 'Prorate Sign up Fee', 'skyhs-hosting-solution' ); ?></label>
                <select id="skyhshoso_apportion_sign_up_fee" name="skyhshoso_apportion_sign_up_fee" style="min-width:250px;">
                    <option value="no" <?php selected( $apportion_sign_up, 'no' ); ?>><?php esc_html_e( 'Never (do not charge a sign up fee)', 'skyhs-hosting-solution' ); ?></option>
                    <option value="full" <?php selected( $apportion_sign_up, 'full' ); ?>><?php esc_html_e( 'Never (charge the full sign up fee)', 'skyhs-hosting-solution' ); ?></option>
                    <option value="yes" <?php selected( $apportion_sign_up, 'yes' ); ?>><?php esc_html_e( 'Always', 'skyhs-hosting-solution' ); ?></option>
                </select>
                <p style="font-size:12px;color:#646970;margin:8px 0 0 0;"><?php esc_html_e( 'When switching to a subscription with a sign up fee, should the customer pay only the gap?', 'skyhs-hosting-solution' ); ?></p>
            </div>

            <div class="skyhshoso-wizard-form-group">
                <label for="skyhshoso_apportion_length"><?php esc_html_e( 'Prorate Subscription Length', 'skyhs-hosting-solution' ); ?></label>
                <select id="skyhshoso_apportion_length" name="skyhshoso_apportion_length" style="min-width:250px;">
                    <option value="no" <?php selected( $apportion_length, 'no' ); ?>><?php esc_html_e( 'Never', 'skyhs-hosting-solution' ); ?></option>
                    <option value="virtual" <?php selected( $apportion_length, 'virtual' ); ?>><?php esc_html_e( 'For Virtual Subscription Products Only', 'skyhs-hosting-solution' ); ?></option>
                    <option value="yes" <?php selected( $apportion_length, 'yes' ); ?>><?php esc_html_e( 'For All Subscription Products', 'skyhs-hosting-solution' ); ?></option>
                </select>
                <p style="font-size:12px;color:#646970;margin:8px 0 0 0;"><?php esc_html_e( 'Take into account completed payments when determining remaining payments for the new subscription.', 'skyhs-hosting-solution' ); ?></p>
            </div>

            <div class="skyhshoso-wizard-actions">
                <div></div>
                <div>
                    <button type="submit" class="skyhshoso-wizard-btn skyhshoso-wizard-btn-primary"><?php esc_html_e( 'Save Settings', 'skyhs-hosting-solution' ); ?></button>
                </div>
            </div>
        </form>
        <?php
    }

    // -------------------------------------------------------------------------
    // Emails tab
    // -------------------------------------------------------------------------

    /**
     * Render email settings tab.
     */
    public function render_emails_tab() {
        $options = get_option( 'skyhshoso_settings_group', array() );
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'skyhshoso_settings_emails' ); ?>

            <p style="margin:0 0 16px 0;font-size:13px;color:#50575e;"><?php esc_html_e( 'Enable or disable individual email notifications. All emails are blocked when test mode is enabled.', 'skyhs-hosting-solution' ); ?></p>

            <div class="skyhshoso-wizard-form-group">
                <label><?php esc_html_e( 'Provisioning Email', 'skyhs-hosting-solution' ); ?></label>
                <label style="font-weight:400;">
                    <input type="hidden" name="skyhshoso_settings_group[email_provisioning]" value="0">
                    <input type="checkbox" name="skyhshoso_settings_group[email_provisioning]" value="1" <?php checked( 1, ! empty( $options['email_provisioning'] ) ); ?>>
                    <?php esc_html_e( 'Send welcome email with cPanel login details when hosting is created.', 'skyhs-hosting-solution' ); ?>
                </label>
            </div>

            <div class="skyhshoso-wizard-form-group">
                <label><?php esc_html_e( 'Suspension Email', 'skyhs-hosting-solution' ); ?></label>
                <label style="font-weight:400;">
                    <input type="hidden" name="skyhshoso_settings_group[email_suspension]" value="0">
                    <input type="checkbox" name="skyhshoso_settings_group[email_suspension]" value="1" <?php checked( 1, ! empty( $options['email_suspension'] ) ); ?>>
                    <?php esc_html_e( 'Send notification when subscription is suspended due to failed payments.', 'skyhs-hosting-solution' ); ?>
                </label>
            </div>

            <div class="skyhshoso-wizard-form-group">
                <label><?php esc_html_e( 'Renewal Reminder Email', 'skyhs-hosting-solution' ); ?></label>
                <label style="font-weight:400;">
                    <input type="hidden" name="skyhshoso_settings_group[email_reminder]" value="0">
                    <input type="checkbox" name="skyhshoso_settings_group[email_reminder]" value="1" <?php checked( 1, ! empty( $options['email_reminder'] ) ); ?>>
                    <?php esc_html_e( 'Send reminder email before each renewal payment.', 'skyhs-hosting-solution' ); ?>
                </label>
            </div>

            <div class="skyhshoso-wizard-form-group">
                <label><?php esc_html_e( 'Termination Notice Email', 'skyhs-hosting-solution' ); ?></label>
                <label style="font-weight:400;">
                    <input type="hidden" name="skyhshoso_settings_group[email_termination_notice]" value="0">
                    <input type="checkbox" name="skyhshoso_settings_group[email_termination_notice]" value="1" <?php checked( 1, ! empty( $options['email_termination_notice'] ) ); ?>>
                    <?php esc_html_e( 'Send 30-day warning when subscription is cancelled.', 'skyhs-hosting-solution' ); ?>
                </label>
            </div>

            <div class="skyhshoso-wizard-form-group">
                <label><?php esc_html_e( 'Terminated Email', 'skyhs-hosting-solution' ); ?></label>
                <label style="font-weight:400;">
                    <input type="hidden" name="skyhshoso_settings_group[email_terminated]" value="0">
                    <input type="checkbox" name="skyhshoso_settings_group[email_terminated]" value="1" <?php checked( 1, ! empty( $options['email_terminated'] ) ); ?>>
                    <?php esc_html_e( 'Send notification when hosting account is fully terminated.', 'skyhs-hosting-solution' ); ?>
                </label>
            </div>

            <div class="skyhshoso-wizard-form-group">
                <label><?php esc_html_e( 'Deletion Warning Email', 'skyhs-hosting-solution' ); ?></label>
                <label style="font-weight:400;">
                    <input type="hidden" name="skyhshoso_settings_group[email_deletion_warning]" value="0">
                    <input type="checkbox" name="skyhshoso_settings_group[email_deletion_warning]" value="1" <?php checked( 1, ! empty( $options['email_deletion_warning'] ) ); ?>>
                    <?php esc_html_e( 'Send a final deletion reminder warning that their hosting will be expired and permanently deleted.', 'skyhs-hosting-solution' ); ?>
                </label>
            </div>

            <div class="skyhshoso-wizard-actions">
                <div></div>
                <div>
                    <button type="submit" class="skyhshoso-wizard-btn skyhshoso-wizard-btn-primary"><?php esc_html_e( 'Save Settings', 'skyhs-hosting-solution' ); ?></button>
                </div>
            </div>
        </form>
        <?php
    }

    // -------------------------------------------------------------------------
    // Invoice tab
    // -------------------------------------------------------------------------

    /**
     * Render invoice settings tab.
     */
    public function render_invoice_tab() {
        $options = get_option( 'skyhshoso_settings_group', array() );
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'skyhshoso_settings_invoice' ); ?>

            <div class="skyhshoso-wizard-form-group">
                <label><?php esc_html_e( 'Company Name', 'skyhs-hosting-solution' ); ?></label>
                <input type="text" name="skyhshoso_settings_group[invoice_company_name]" value="<?php echo esc_attr( isset( $options['invoice_company_name'] ) ? $options['invoice_company_name'] : get_bloginfo( 'name' ) ); ?>" style="width:100%;max-width:400px;">
                <p style="font-size:12px;color:#646970;margin:8px 0 0 0;"><?php esc_html_e( 'Appears at the top of each invoice. Defaults to site name.', 'skyhs-hosting-solution' ); ?></p>
            </div>

            <div class="skyhshoso-wizard-form-group">
                <label><?php esc_html_e( 'Company Address', 'skyhs-hosting-solution' ); ?></label>
                <textarea name="skyhshoso_settings_group[invoice_address]" rows="3" style="width:100%;max-width:400px;"><?php echo esc_textarea( isset( $options['invoice_address'] ) ? $options['invoice_address'] : '' ); ?></textarea>
                <p style="font-size:12px;color:#646970;margin:8px 0 0 0;"><?php esc_html_e( 'Your business address displayed on invoices.', 'skyhs-hosting-solution' ); ?></p>
            </div>

            <div class="skyhshoso-wizard-form-group">
                <label><?php esc_html_e( 'Footer Text', 'skyhs-hosting-solution' ); ?></label>
                <input type="text" name="skyhshoso_settings_group[invoice_footer]" value="<?php echo esc_attr( isset( $options['invoice_footer'] ) ? $options['invoice_footer'] : '' ); ?>" style="width:100%;max-width:400px;">
                <p style="font-size:12px;color:#646970;margin:8px 0 0 0;"><?php esc_html_e( 'Optional thank-you message or notes at the bottom of invoices.', 'skyhs-hosting-solution' ); ?></p>
            </div>

            <div class="skyhshoso-wizard-actions">
                <div></div>
                <div>
                    <button type="submit" class="skyhshoso-wizard-btn skyhshoso-wizard-btn-primary"><?php esc_html_e( 'Save Settings', 'skyhs-hosting-solution' ); ?></button>
                </div>
            </div>
        </form>
        <?php
    }

    /**
     * Render test mode field
     */
    public function render_test_mode_field() {
        $options = get_option( 'skyhshoso_settings_group', array( 'test_mode' => 1 ) );
        $checked = isset( $options['test_mode'] ) ? (int) $options['test_mode'] : 0;
        echo '<input type="checkbox" id="test_mode" name="skyhshoso_settings_group[test_mode]" value="1" ' . checked( 1, $checked, false ) . ' />';
        echo '<label for="test_mode">' . esc_html__( 'Disable outgoing emails (test mode for emails)', 'skyhs-hosting-solution' ) . '</label>';
    }

    /**
     * Render disable subscription processing field
     */
    public function render_disable_subscription_processing_field() {
        $options = get_option( 'skyhshoso_settings_group', array() );
        $checked = isset( $options['disable_subscription_processing'] ) ? (int) $options['disable_subscription_processing'] : 0;
        echo '<input type="checkbox" id="disable_subscription_processing" name="skyhshoso_settings_group[disable_subscription_processing]" value="1" ' . checked( 1, $checked, false ) . ' />';
        echo '<label for="disable_subscription_processing">' . esc_html__( 'Disable subscription processing (hosting and domain webhooks will not run)', 'skyhs-hosting-solution' ) . '</label>';
    }
    
    /**
     * Render disable domain registration field
     */
    public function render_disable_domain_registration_field() {
        $options = get_option( 'skyhshoso_settings_group', array() );
        $checked = isset( $options['disable_domain_registration'] ) ? (int) $options['disable_domain_registration'] : 0;
        echo '<input type="checkbox" id="disable_domain_registration" name="skyhshoso_settings_group[disable_domain_registration]" value="1" ' . checked( 1, $checked, false ) . ' />';
        echo '<label for="disable_domain_registration">' . esc_html__( 'Disable domain registration from both admin backend and frontend completely', 'skyhs-hosting-solution' ) . '</label>';
    }
    
    /**
     * Render dashboard page field
     */
    public function render_dashboard_page_field() {
        $options = get_option( 'skyhshoso_settings_group', array( 'dashboard_page' => 0 ) );
        $dashboard_page = isset( $options['dashboard_page'] ) ? absint( $options['dashboard_page'] ) : 0;
        
        wp_dropdown_pages( array(
            'name'              => 'skyhshoso_settings_group[dashboard_page]',
            'id'                => 'dashboard_page',
            'echo'              => 1,
            'show_option_none'  => esc_html__( '— Select —', 'skyhs-hosting-solution' ),
            'option_none_value' => '0',
            'selected'          => absint( $dashboard_page ),
        ) );
        
		echo '<p class="description">' . esc_html__( 'Select the page where your SkyHS Dashboard shortcode [skyhshoso_dashboard] is displayed. Users will be redirected to this page when visiting hosting product pages.', 'skyhs-hosting-solution' ) . '</p>';
	}

	/**
	 * Render enable WC log field
	 */
	public function render_enable_wc_log_field() {
		$options = get_option( 'skyhshoso_settings_group', array() );
		$checked = isset( $options['enable_wc_log'] ) ? (int) $options['enable_wc_log'] : 0;
		echo '<input type="checkbox" id="enable_wc_log" name="skyhshoso_settings_group[enable_wc_log]" value="1" ' . checked( 1, $checked, false ) . ' />';
		echo '<label for="enable_wc_log">' . esc_html__( 'Log server, hosting, domain, WordPress, and subscription creation failures to WooCommerce logs (WooCommerce > Status > Logs)', 'skyhs-hosting-solution' ) . '</label>';
	}

	/**
	 * Enqueue admin scripts
	 */
	public function enqueue_admin_scripts( $hook ) {
        // Only load on our settings page
        if ( false !== strpos( $hook, 'skyhshoso-settings' ) || false !== strpos( $hook, 'hosting-solution-settings' ) ) {
            wp_enqueue_media();
            wp_enqueue_style(
                'hosting-solution-admin-styles',
                SKYHSHOSO_PLUGIN_URL . 'assets/css/admin.css',
                array(),
                SKYHSHOSO_VERSION
            );

            wp_enqueue_style(
                'hosting-solution-admin-wizard-styles',
                SKYHSHOSO_PLUGIN_URL . 'assets/css/admin-wizard.css',
                array(),
                SKYHSHOSO_VERSION
            );
            
            // Enqueue admin script
            wp_enqueue_script(
                'hosting-solution-admin-script',
                SKYHSHOSO_PLUGIN_URL . 'assets/js/admin.js',
                array( 'jquery' ),
                SKYHSHOSO_VERSION,
                true
            );
            
            // Add localization for admin script
            wp_localize_script(
                'hosting-solution-admin-script',
                'skyhshoso_admin_l10n',
                array(
                    'preview_title' => __( 'Preview', 'skyhs-hosting-solution' ),
                )
            );
        }
    }

    /**
     * Maybe disable emails based on test mode setting
     *
     * @param array $args Email arguments.
     * @return array|false
     */
    public function maybe_disable_emails( $args ) {
        $options = get_option( 'skyhshoso_settings_group', array( 'test_mode' => 1 ) );

        // Allow test emails through even when test mode is on.
        if ( apply_filters( 'skyhshoso_force_send_test', false ) ) {
            return $args;
        }

        // If test mode is ON (1), block all emails
        if ( ! empty( $options['test_mode'] ) ) {
            return false;
        }

        return $args;
    }

    /**
     * Check if test mode is enabled
     *
     * @return bool
     */
    public static function is_test_mode() {
        $options = get_option( 'skyhshoso_settings_group', array( 'test_mode' => 1 ) );
        return ! empty( $options['test_mode'] );
    }

    /**
     * Check if subscription processing is disabled
     *
     * @return bool
     */
    public static function is_subscription_processing_disabled() {
        $options = get_option( 'skyhshoso_settings_group', array() );
        return ! empty( $options['disable_subscription_processing'] );
    }

    /**
     * Check if domain registration is disabled
     *
     * @return bool
     */
    public static function is_domain_registration_disabled() {
        $options = get_option( 'skyhshoso_settings_group', array() );
        return ! empty( $options['disable_domain_registration'] );
    }

    /**
     * Get guest welcome title.
     *
     * @return string
     */
    public static function get_guest_welcome_title() {
        $options = get_option( 'skyhshoso_settings_group', array() );
        return isset( $options['guest_welcome_title'] ) && '' !== $options['guest_welcome_title']
            ? $options['guest_welcome_title']
            : __( 'Welcome', 'skyhs-hosting-solution' );
    }

    /**
     * Get guest welcome subtitle.
     *
     * @return string
     */
    public static function get_guest_welcome_subtitle() {
        $options = get_option( 'skyhshoso_settings_group', array() );
        return isset( $options['guest_welcome_subtitle'] ) && '' !== $options['guest_welcome_subtitle']
            ? $options['guest_welcome_subtitle']
            : __( 'Explore our hosting plans and domain services. Sign in to manage your existing services.', 'skyhs-hosting-solution' );
    }

    /**
     * Get guest welcome button text.
     *
     * @return string
     */
    public static function get_guest_welcome_btn_text() {
        $options = get_option( 'skyhshoso_settings_group', array() );
        return isset( $options['guest_welcome_btn_text'] ) && '' !== $options['guest_welcome_btn_text']
            ? $options['guest_welcome_btn_text']
            : '';
    }

    /**
     * Get guest welcome button URL.
     *
     * @return string
     */
    public static function get_guest_welcome_btn_url() {
        $options = get_option( 'skyhshoso_settings_group', array() );
        return isset( $options['guest_welcome_btn_url'] ) && '' !== $options['guest_welcome_btn_url']
            ? $options['guest_welcome_btn_url']
            : '';
    }

    /**
     * Check if guest dashboard access is enabled.
     *
     * @return bool
     */
    public static function is_guest_dashboard_enabled() {
        $options = get_option( 'skyhshoso_settings_group', array() );
        return ! empty( $options['enable_guest_dashboard'] );
    }

    /**
     * Get dashboard page URL
     *
     * @return string|false Dashboard page URL or false if not set
     */
    public static function get_dashboard_url() {
        $options = get_option( 'skyhshoso_settings_group', array( 'dashboard_page' => 0 ) );
        $dashboard_page = isset( $options['dashboard_page'] ) ? absint( $options['dashboard_page'] ) : 0;

        if ( $dashboard_page > 0 ) {
            return get_permalink( $dashboard_page );
        }

        return false;
    }

    /**
     * Get back to site URL.
     *
     * @return string
     */
    public static function get_back_to_site_url() {
        $options = get_option( 'skyhshoso_settings_group', array() );
        return isset( $options['back_to_site_url'] ) && '' !== $options['back_to_site_url']
            ? esc_url( $options['back_to_site_url'] )
            : home_url( '/' );
    }

    // -------------------------------------------------------------------------
    // Billing setting helpers
    // -------------------------------------------------------------------------

    /**
     * Get grace period days before termination.
     *
     * @return int
     */
    public static function get_grace_period_days() {
        $options = get_option( 'skyhshoso_settings_group', array() );
        return isset( $options['grace_period_days'] ) ? absint( $options['grace_period_days'] ) : 30;
    }

    /**
     * Get failed payment threshold before suspension.
     *
     * @return int
     */
    public static function get_failed_payment_threshold() {
        $options = get_option( 'skyhshoso_settings_group', array() );
        return isset( $options['failed_payment_threshold'] ) ? absint( $options['failed_payment_threshold'] ) : 3;
    }

    /**
     * Get renewal reminder days before next payment.
     *
     * @return int
     */
    public static function get_reminder_days() {
        $options = get_option( 'skyhshoso_settings_group', array() );
        return isset( $options['reminder_days'] ) ? absint( $options['reminder_days'] ) : 3;
    }

    /**
     * Get deletion reminder days before termination.
     *
     * @return int
     */
    public static function get_deletion_reminder_days() {
        $options = get_option( 'skyhshoso_settings_group', array() );
        return isset( $options['deletion_reminder_days'] ) ? absint( $options['deletion_reminder_days'] ) : 3;
    }

    // -------------------------------------------------------------------------
    // Email setting helpers
    // -------------------------------------------------------------------------

    /**
     * Check if a specific email type is enabled.
     *
     * @param string $type  provisioning|suspension|termination_notice|terminated|reminder|deletion_warning
     * @return bool
     */
    public static function is_email_enabled( $type ) {
        $key = 'email_' . $type;
        $options = get_option( 'skyhshoso_settings_group', array() );
        return ! empty( $options[ $key ] );
    }

    // -------------------------------------------------------------------------
    // Invoice setting helpers
    // -------------------------------------------------------------------------

    /**
     * Get company name for invoices.
     *
     * @return string
     */
    public static function get_invoice_company_name() {
        $options = get_option( 'skyhshoso_settings_group', array() );
        return isset( $options['invoice_company_name'] ) ? $options['invoice_company_name'] : get_bloginfo( 'name' );
    }

    /**
     * Get company address for invoices.
     *
     * @return string
     */
    public static function get_invoice_address() {
        $options = get_option( 'skyhshoso_settings_group', array() );
        return isset( $options['invoice_address'] ) ? $options['invoice_address'] : '';
    }

    /**
     * Get footer text for invoices.
     *
     * @return string
     */
    public static function get_invoice_footer() {
        $options = get_option( 'skyhshoso_settings_group', array() );
        return isset( $options['invoice_footer'] ) ? $options['invoice_footer'] : '';
    }

    /**
     * Show test mode notice
     */
    public function maybe_show_test_mode_notice() {
        $options = get_option( 'skyhshoso_settings_group', array( 'test_mode' => 1 ) );
        $settings_url = admin_url( 'admin.php?page=skyhshoso-settings' );
        $notices = array();

        if ( ! empty( $options['test_mode'] ) ) {
            $notices[] = __( 'All outgoing emails are currently blocked.', 'skyhs-hosting-solution' );
        }

        if ( ! empty( $options['disable_subscription_processing'] ) ) {
            $notices[] = __( 'Subscription processing (hosting/domain webhooks) is disabled.', 'skyhs-hosting-solution' );
        }

        if ( ! empty( $notices ) ) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>' . esc_html__( 'Hosting Solution: Test Mode is enabled', 'skyhs-hosting-solution' ) . '</strong></p>';
            echo '<p>' . esc_html( implode( ' ', $notices ) ) . ' ';
            echo '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Go to settings', 'skyhs-hosting-solution' ) . '</a></p>';
            echo '</div>';
        }
    }
    
    /**
     * Redirect products to dashboard based on product type
     */
    public function redirect_products_to_dashboard() {
        // Only redirect on frontend single product pages for logged-in users
        if ( ! is_product() || ! is_user_logged_in() ) {
            return;
        }
        
        global $post;
        $product = wc_get_product( $post->ID );
        
        // Ensure we have a valid product
        if ( ! $product ) {
            return;
        }
        
        // Get product type from meta
        $product_type = get_post_meta( $product->get_id(), '_skyhshoso_product_type', true );
        
        // Get dashboard URL
        $dashboard_url = self::get_dashboard_url();
        
        // Only proceed if we have a dashboard URL
        if ( ! $dashboard_url ) {
            return;
        }
        
        // Handle redirect based on product type
        if ( 'skyhshoso_hosting' === $product_type ) {
            // Redirect to hosting tab with new hosting form and product ID
            $redirect_url = add_query_arg( 
                array(
                    'tab' => 'skyhshoso_hosting',
                    'new_hosting' => 1,
                    'product_id' => $product->get_id()
                ), 
                $dashboard_url 
            );
            
            wp_safe_redirect( $redirect_url );
            exit;
        } elseif ( 'skyhshoso_domain' === $product_type ) {
            // Redirect to domains tab
            $redirect_url = add_query_arg( 'tab', 'domains', $dashboard_url );
            
            wp_safe_redirect( $redirect_url );
            exit;
        }
    }

    /**
     * Redirect hosting and domain post types to the dashboard.
     */
    public function redirect_post_types_to_dashboard() {
        // Only redirect on frontend single post pages for logged-in users
        if ( ! is_singular() || ! is_user_logged_in() ) {
            return;
        }

        global $post;

        // Ensure we have a valid post
        if ( ! $post ) {
            return;
        }

        // Get dashboard URL
        $dashboard_url = self::get_dashboard_url();

        // Only proceed if we have a dashboard URL
        if ( ! $dashboard_url ) {
            return;
        }

        $post_type = get_post_type( $post );

        if ( 'skyhshoso_hosting' === $post_type ) {
            $redirect_url = add_query_arg(
                array(
                    'tab'        => 'skyhshoso_hosting',
                    'hosting_id' => $post->ID,
                ),
                $dashboard_url
            );
            wp_safe_redirect( $redirect_url );
            exit;
        } elseif ( 'skyhshoso_domain' === $post_type ) {
            $redirect_url = add_query_arg(
                array(
                    'tab'       => 'domains',
                    'domain_id' => $post->ID,
                    'dns'       => 'manage',
                ),
                $dashboard_url
            );
            wp_safe_redirect( $redirect_url );
            exit;
        }
    }

    // -------------------------------------------------------------------------
    // Email Templates tab
    // -------------------------------------------------------------------------

    /**
     * Render email templates editor tab.
     */
    public function render_email_templates_tab() {
        $options = get_option( 'skyhshoso_settings_group', array() );
        $email_types = array(
            'provisioning'        => __( 'Provisioning Email', 'skyhs-hosting-solution' ),
            'suspension'          => __( 'Suspension Email', 'skyhs-hosting-solution' ),
            'reminder'            => __( 'Renewal Reminder', 'skyhs-hosting-solution' ),
            'termination_notice'  => __( 'Termination Notice', 'skyhs-hosting-solution' ),
            'terminated'          => __( 'Terminated Email', 'skyhs-hosting-solution' ),
            'deletion_warning'    => __( 'Deletion Warning', 'skyhs-hosting-solution' ),
        );

        $variables = array(
            'provisioning' => array( 'site_name', 'domain', 'plan', 'server_name', 'server_ip', 'cpanel_url', 'username', 'password', 'nameservers' ),
            'suspension'   => array( 'site_name', 'subscription_id' ),
            'reminder'     => array( 'site_name', 'display_name', 'renewal_date', 'amount' ),
            'termination_notice' => array( 'site_name', 'end_date' ),
            'terminated'   => array( 'site_name' ),
            'deletion_warning' => array( 'site_name', 'display_name', 'termination_date', 'days_left' ),
        );

        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'skyhshoso_settings_email_templates' ); ?>

            <p style="margin:0 0 16px 0;font-size:13px;color:#50575e;">
                <?php esc_html_e( 'Customize the subject and body for each email. Leave a field empty to use the default template. Use the variables listed below each editor — they will be replaced with real values when sent.', 'skyhs-hosting-solution' ); ?>
            </p>

            <?php foreach ( $email_types as $etype => $elabel ) :
                $saved_subject = isset( $options[ "email_subject_{$etype}" ] ) && '' !== $options[ "email_subject_{$etype}" ] ? $options[ "email_subject_{$etype}" ] : SkyHSHOSO_Emails::get_default_subject( $etype );
                $saved_body    = isset( $options[ "email_body_{$etype}" ] ) && '' !== $options[ "email_body_{$etype}" ] ? $options[ "email_body_{$etype}" ] : SkyHSHOSO_Emails::get_default_body( $etype );
                $field_subject = "skyhshoso_settings_group[email_subject_{$etype}]";
                $field_body    = "skyhshoso_settings_group[email_body_{$etype}]";
                $vars = $variables[ $etype ];
            ?>
            <div class="skyhshoso-email-template-wrapper" style="margin-bottom:32px;padding:20px;background:#f6f7f7;border-radius:6px;border:1px solid #dcdcde;">
                <h3 style="margin:0 0 12px 0;font-size:14px;"><?php echo esc_html( $elabel ); ?></h3>

                <div style="margin-bottom:12px;">
                    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#50575e;"><?php esc_html_e( 'Subject', 'skyhs-hosting-solution' ); ?></label>
                    <input type="text" name="<?php echo esc_attr( $field_subject ); ?>" value="<?php echo esc_attr( $saved_subject ); ?>" placeholder="<?php esc_attr_e( 'Default subject — leave blank', 'skyhs-hosting-solution' ); ?>" style="width:100%;max-width:600px;">
                </div>

                <div style="margin-bottom:8px;">
                    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#50575e;"><?php esc_html_e( 'Body (HTML)', 'skyhs-hosting-solution' ); ?></label>
                    <textarea name="<?php echo esc_attr( $field_body ); ?>" rows="12" style="width:100%;max-width:800px;font-family:monospace;font-size:13px;" placeholder="<?php esc_attr_e( 'Leave blank for default template', 'skyhs-hosting-solution' ); ?>"><?php echo esc_textarea( $saved_body ); ?></textarea>
                </div>

                <div style="font-size:12px;color:#646970;">
                    <strong><?php esc_html_e( 'Available variables:', 'skyhs-hosting-solution' ); ?></strong>
                    <?php foreach ( $vars as $var ) : ?>
                        <code style="background:#fff;padding:2px 6px;border-radius:3px;margin:2px;">{{<?php echo esc_html( $var ); ?>}}</code>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top:12px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <input type="email" class="skyhshoso-test-email-input" value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" placeholder="<?php esc_attr_e( 'Recipient email', 'skyhs-hosting-solution' ); ?>" style="width:220px;font-size:13px;">
                    <button type="button" class="button skyhshoso-test-email-btn" data-email-type="<?php echo esc_attr( $etype ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'skyhshoso_test_email' ) ); ?>">
                        <?php esc_html_e( 'Send Test Email', 'skyhs-hosting-solution' ); ?>
                    </button>
                    <button type="button" class="button skyhshoso-preview-email-btn" data-email-type="<?php echo esc_attr( $etype ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'skyhshoso_preview_email' ) ); ?>">
                        <?php esc_html_e( 'Preview', 'skyhs-hosting-solution' ); ?>
                    </button>
                    <span class="skyhshoso-test-email-result" style="font-size:12px;"></span>
                </div>
                <div class="skyhshoso-preview-container" style="display:none;margin-top:12px;border:1px solid #dcdcde;border-radius:6px;overflow:hidden;background:#fff;">
                    <div class="skyhshoso-preview-header" style="padding:10px 14px;background:#f0f0f1;font-size:13px;font-weight:600;border-bottom:1px solid #dcdcde;display:flex;justify-content:space-between;align-items:center;">
                        <span class="skyhshoso-preview-subject"></span>
                        <button type="button" class="skyhshoso-preview-close button-small" style="font-size:11px;"><?php esc_html_e( 'Close Preview', 'skyhs-hosting-solution' ); ?></button>
                    </div>
                    <div class="skyhshoso-preview-body" style="padding:0;"></div>
                </div>
            </div>
            <?php endforeach; ?>

            <div class="skyhshoso-wizard-actions">
                <div></div>
                <div>
                    <button type="submit" class="skyhshoso-wizard-btn skyhshoso-wizard-btn-primary"><?php esc_html_e( 'Save Settings', 'skyhs-hosting-solution' ); ?></button>
                </div>
            </div>
        </form>

        <script>
        jQuery(function($) {
            // Send test email using sibling input
            $('.skyhshoso-test-email-btn').on('click', function() {
                var btn = $(this);
                var wrapper = btn.closest('.skyhshoso-email-template-wrapper');
                var input = wrapper.find('.skyhshoso-test-email-input');
                var result = wrapper.find('.skyhshoso-test-email-result');
                var etype = btn.data('email-type');
                var nonce = btn.data('nonce');
                var email = input.val().trim();
                if (!email) {
                    result.text('<?php echo esc_js( __( 'Enter an email address.', 'skyhs-hosting-solution' ) ); ?>').css('color', '#d63638');
                    return;
                }

                btn.prop('disabled', true);
                result.text('<?php echo esc_js( __( 'Sending...', 'skyhs-hosting-solution' ) ); ?>');

                $.post('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', {
                    action: 'skyhshoso_test_email',
                    nonce: nonce,
                    email_type: etype,
                    recipient: email
                }, function(resp) {
                    if (resp.success) {
                        result.text(resp.data.message).css('color', '#007017');
                    } else {
                        result.text(resp.data.message).css('color', '#d63638');
                    }
                }).fail(function() {
                    result.text('<?php echo esc_js( __( 'Request failed.', 'skyhs-hosting-solution' ) ); ?>').css('color', '#d63638');
                }).always(function() {
                    btn.prop('disabled', false);
                });
            });

            // Preview inline (toggle)
            $('.skyhshoso-preview-email-btn').on('click', function() {
                var btn = $(this);
                var wrapper = btn.closest('.skyhshoso-email-template-wrapper');
                var container = wrapper.find('.skyhshoso-preview-container');
                var subjectEl = container.find('.skyhshoso-preview-subject');
                var bodyEl = container.find('.skyhshoso-preview-body');
                var etype = btn.data('email-type');
                var nonce = btn.data('nonce');

                // Toggle if already loaded for this type
                if (container.is(':visible')) {
                    container.slideUp(150);
                    return;
                }

                // If empty or different type, fetch preview
                if (!container.data('loaded') || container.data('etype') !== etype) {
                    btn.prop('disabled', true);
                    bodyEl.html('<p style="padding:20px;color:#646970;text-align:center;"><?php echo esc_js( __( 'Loading preview...', 'skyhs-hosting-solution' ) ); ?></p>');
                    container.slideDown(150);

                    $.post('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', {
                        action: 'skyhshoso_preview_email',
                        nonce: nonce,
                        email_type: etype
                    }, function(resp) {
                        if (resp.success) {
                            subjectEl.text(resp.data.subject);
                            bodyEl.empty();
                            var iframe = document.createElement('iframe');
                            iframe.style.width = '100%';
                            iframe.style.height = '400px';
                            iframe.style.border = 'none';
                            iframe.style.display = 'block';
                            iframe.style.background = '#fff';
                            bodyEl[0].appendChild(iframe);
                            var writeDoc = function() {
                                var doc = iframe.contentDocument || iframe.contentWindow.document;
                                if (doc) { doc.open(); doc.write(resp.data.body); doc.close(); }
                            };
                            writeDoc();
                            iframe.onload = writeDoc;
                            container.data('loaded', true).data('etype', etype);
                        } else {
                            bodyEl.html('<p style="padding:20px;color:#d63638;">' + resp.data.message + '</p>');
                        }
                    }).fail(function() {
                        bodyEl.html('<p style="padding:20px;color:#d63638;"><?php echo esc_js( __( 'Preview request failed.', 'skyhs-hosting-solution' ) ); ?></p>');
                    }).always(function() {
                        btn.prop('disabled', false);
                    });
                } else {
                    container.slideDown(150);
                }
            });

            // Close preview
            $('.skyhshoso-preview-close').on('click', function() {
                $(this).closest('.skyhshoso-preview-container').slideUp(150);
            });
        });
        </script>
        <?php
    }

    /**
     * Render Customize settings tab.
     */
    public function render_customize_tab() {
        $options = get_option( 'skyhshoso_settings_group', array() );
        $custom_logo = isset( $options['custom_logo'] ) ? $options['custom_logo'] : '';
        $custom_sitename = isset( $options['custom_sitename'] ) ? $options['custom_sitename'] : '';
        $show_only_logo = isset( $options['show_only_logo'] ) ? (bool) $options['show_only_logo'] : false;
        $guest_welcome_title = isset( $options['guest_welcome_title'] ) ? $options['guest_welcome_title'] : '';
        $guest_welcome_subtitle = isset( $options['guest_welcome_subtitle'] ) ? $options['guest_welcome_subtitle'] : '';
        $enable_guest_dashboard = isset( $options['enable_guest_dashboard'] ) ? (bool) $options['enable_guest_dashboard'] : false;
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'skyhshoso_settings_customize' ); ?>

            <p style="margin:0 0 20px 0;font-size:13px;color:#50575e;">
                <?php esc_html_e( 'Customize the appearance of the client dashboard header, including the logo and site name display.', 'skyhs-hosting-solution' ); ?>
            </p>

            <div class="skyhshoso-wizard-form-group">
                <label style="font-weight:600; display:block; margin-bottom:8px;"><?php esc_html_e( 'Custom Logo', 'skyhs-hosting-solution' ); ?></label>
                <div style="display:flex; align-items:center; gap:12px; margin-top:8px;">
                    <input type="text" id="skyhshoso-custom-logo-url" name="skyhshoso_settings_group[custom_logo]" value="<?php echo esc_url( $custom_logo ); ?>" placeholder="<?php esc_attr_e( 'https://example.com/logo.png', 'skyhs-hosting-solution' ); ?>" style="width:100%; max-width:400px; height:36px;">
                    <button type="button" id="skyhshoso-upload-logo-btn" class="button button-secondary" style="height:36px; padding:0 16px;"><?php esc_html_e( 'Upload Image', 'skyhs-hosting-solution' ); ?></button>
                    <button type="button" id="skyhshoso-remove-logo-btn" class="button button-link-delete" style="height:36px; color:#d63638; text-decoration:none;"><?php esc_html_e( 'Remove', 'skyhs-hosting-solution' ); ?></button>
                </div>
                <div style="margin-top:12px;">
                    <img id="skyhshoso-logo-preview" src="<?php echo esc_url( $custom_logo ); ?>" style="max-height:80px; width:auto; border:1px solid #dcdcde; border-radius:4px; padding:6px; background:#f6f7f7; display:<?php echo ! empty( $custom_logo ) ? 'block' : 'none'; ?>;" />
                </div>
                <p style="font-size:12px;color:#646970;margin:8px 0 0 0;"><?php esc_html_e( 'Upload or enter the URL of a custom logo to display on the dashboard top-left header bar.', 'skyhs-hosting-solution' ); ?></p>
            </div>

            <div class="skyhshoso-wizard-form-group" style="margin-top: 20px;">
                <label style="font-weight:600; display:block; margin-bottom:8px;"><?php esc_html_e( 'Custom Site Name', 'skyhs-hosting-solution' ); ?></label>
                <input type="text" name="skyhshoso_settings_group[custom_sitename]" value="<?php echo esc_attr( $custom_sitename ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" style="width:100%;max-width:400px;">
                <p style="font-size:12px;color:#646970;margin:8px 0 0 0;"><?php esc_html_e( 'Optionally override the site name display text next to the logo. Defaults to the WordPress site name.', 'skyhs-hosting-solution' ); ?></p>
            </div>

            <div class="skyhshoso-wizard-form-group" style="margin-top: 20px;">
                <label style="font-weight:600; display:block; margin-bottom:8px;"><?php esc_html_e( 'Branding Display Options', 'skyhs-hosting-solution' ); ?></label>
                <label style="font-weight:400; display:block; margin-top:8px;">
                    <input type="hidden" name="skyhshoso_settings_group[show_only_logo]" value="0">
                    <input type="checkbox" name="skyhshoso_settings_group[show_only_logo]" value="1" <?php checked( $show_only_logo ); ?>>
                    <?php esc_html_e( 'Show only logo, do not show site name', 'skyhs-hosting-solution' ); ?>
                </label>
                <p style="font-size:12px;color:#646970;margin:8px 0 0 0;"><?php esc_html_e( 'Check this to completely hide the text site name and only show the logo icon or image.', 'skyhs-hosting-solution' ); ?></p>
            </div>

            <div class="skyhshoso-wizard-form-group" style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #dcdcde;">
                <label style="font-weight:600; display:block; margin-bottom:8px;"><?php esc_html_e( 'Guest Dashboard Access', 'skyhs-hosting-solution' ); ?></label>
                <label style="font-weight:400; display:block; margin-top:8px;">
                    <input type="hidden" name="skyhshoso_settings_group[enable_guest_dashboard]" value="0">
                    <input type="checkbox" name="skyhshoso_settings_group[enable_guest_dashboard]" value="1" <?php checked( $enable_guest_dashboard ); ?>>
                    <?php esc_html_e( 'Allow non-logged-in users to access dashboard (shows welcome page)', 'skyhs-hosting-solution' ); ?>
                </label>
                <p style="font-size:12px;color:#646970;margin:8px 0 0 0;"><?php esc_html_e( 'When unchecked, visitors will be redirected to the login page. When checked, guests can browse hosting plans, register/transfer domains, and see a welcome page.', 'skyhs-hosting-solution' ); ?></p>
            </div>

            <div class="skyhshoso-wizard-form-group" style="margin-top: 20px;">
                <label style="font-weight:600; display:block; margin-bottom:8px;"><?php esc_html_e( 'Guest Welcome Heading', 'skyhs-hosting-solution' ); ?></label>
                <input type="text" name="skyhshoso_settings_group[guest_welcome_title]" value="<?php echo esc_attr( $guest_welcome_title ); ?>" placeholder="<?php esc_attr_e( 'Welcome', 'skyhs-hosting-solution' ); ?>" style="width:100%;max-width:400px;">
                <p style="font-size:12px;color:#646970;margin:8px 0 0 0;"><?php esc_html_e( 'The heading shown to non-logged-in visitors on the dashboard. Leave empty for default.', 'skyhs-hosting-solution' ); ?></p>
            </div>

            <div class="skyhshoso-wizard-form-group" style="margin-top: 20px;">
                <label style="font-weight:600; display:block; margin-bottom:8px;"><?php esc_html_e( 'Guest Welcome Subtitle', 'skyhs-hosting-solution' ); ?></label>
                <textarea name="skyhshoso_settings_group[guest_welcome_subtitle]" rows="3" style="width:100%;max-width:400px;" placeholder="<?php esc_attr_e( 'Explore our hosting plans and domain services. Sign in to manage your existing services.', 'skyhs-hosting-solution' ); ?>"><?php echo esc_textarea( $guest_welcome_subtitle ); ?></textarea>
                <p style="font-size:12px;color:#646970;margin:8px 0 0 0;"><?php esc_html_e( 'The subtitle shown to non-logged-in visitors on the dashboard. Leave empty for default.', 'skyhs-hosting-solution' ); ?></p>
            </div>

            <div class="skyhshoso-wizard-actions" style="margin-top: 30px; border-top: 1px solid #dcdcde; padding-top: 20px;">
                <div></div>
                <div>
                    <button type="submit" class="skyhshoso-wizard-btn skyhshoso-wizard-btn-primary"><?php esc_html_e( 'Save Settings', 'skyhs-hosting-solution' ); ?></button>
                </div>
            </div>
        </form>
        <?php
    }

    // -------------------------------------------------------------------------
    // Activity Log tab
    // -------------------------------------------------------------------------

    /**
     * Render activity log tab.
     */
    public function render_logs_tab() {
        if ( ! class_exists( 'SkyHSHOSO_Activity_Log' ) ) {
            echo '<p>' . esc_html__( 'Activity log system not available.', 'skyhs-hosting-solution' ) . '</p>';
            return;
        }

        $selected_date = isset( $_GET['log_date'] ) ? sanitize_text_field( wp_unslash( $_GET['log_date'] ) ) : gmdate( 'Y-m-d' );
        $selected_type = isset( $_GET['log_type'] ) ? sanitize_text_field( wp_unslash( $_GET['log_type'] ) ) : '';
        $paged         = isset( $_GET['log_paged'] ) ? max( 1, (int) $_GET['log_paged'] ) : 1;
        $per_page      = 50;
        $offset        = ( $paged - 1 ) * $per_page;

        $days = SkyHSHOSO_Activity_Log::get_days_with_logs( 30 );

        $log_types = array(
            ''            => __( 'All Types', 'skyhs-hosting-solution' ),
            'cron'        => __( 'Cron Runs', 'skyhs-hosting-solution' ),
            'renewal'     => __( 'Renewals', 'skyhs-hosting-solution' ),
            'email'       => __( 'Emails', 'skyhs-hosting-solution' ),
            'suspension'  => __( 'Suspensions', 'skyhs-hosting-solution' ),
            'reminder'    => __( 'Reminders', 'skyhs-hosting-solution' ),
            'expiry'      => __( 'Expirations', 'skyhs-hosting-solution' ),
            'cancelled'   => __( 'Cancellations', 'skyhs-hosting-solution' ),
            'termination' => __( 'Terminations', 'skyhs-hosting-solution' ),
        );

        $total = SkyHSHOSO_Activity_Log::get_log_count( $selected_date, $selected_date, $selected_type );
        $logs  = SkyHSHOSO_Activity_Log::get_logs( $selected_date, $selected_date, $selected_type, $per_page, $offset );
        $summary = SkyHSHOSO_Activity_Log::get_daily_summary( $selected_date );
        ?>
        <div class="skyhshoso-wizard-form-group">
            <h3 style="margin-top:0;"><?php esc_html_e( 'Activity Log', 'skyhs-hosting-solution' ); ?></h3>
            <p style="font-size:13px;color:#50575e;"><?php esc_html_e( 'View a log of what the daily subscription processing did, including emails sent and their status.', 'skyhs-hosting-solution' ); ?></p>
        </div>

        <form method="get" action="" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; margin-bottom:20px;">
            <input type="hidden" name="page" value="skyhshoso-settings">
            <input type="hidden" name="tab" value="logs">

            <div>
                <label style="display:block;font-weight:600;margin-bottom:4px;font-size:12px;"><?php esc_html_e( 'Date', 'skyhs-hosting-solution' ); ?></label>
                <select name="log_date" style="min-width:180px;">
                    <?php foreach ( $days as $day ) : ?>
                        <option value="<?php echo esc_attr( $day->log_date ); ?>" <?php selected( $selected_date, $day->log_date ); ?>>
                            <?php echo esc_html( $day->log_date . ' (' . $day->count . ' entries)' ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label style="display:block;font-weight:600;margin-bottom:4px;font-size:12px;"><?php esc_html_e( 'Type', 'skyhs-hosting-solution' ); ?></label>
                <select name="log_type" style="min-width:150px;">
                    <?php foreach ( $log_types as $val => $label ) : ?>
                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $selected_type, $val ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <button type="submit" class="button"><?php esc_html_e( 'Filter', 'skyhs-hosting-solution' ); ?></button>
            </div>
        </form>

        <?php if ( ! empty( $summary ) ) : ?>
            <div style="display:flex; gap:12px; flex-wrap:wrap; margin-bottom:20px;">
                <?php
                $status_colors = array(
                    'success' => '#00a32a',
                    'error'   => '#d63638',
                    'warning' => '#dba617',
                    'info'    => '#2271b1',
                );
                foreach ( $summary as $s ) :
                    $color = isset( $status_colors[ $s->status ] ) ? $status_colors[ $s->status ] : '#646970';
                    $label = isset( $log_types[ $s->log_type ] ) ? $log_types[ $s->log_type ] : $s->log_type;
                ?>
                    <div style="background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:10px 16px;min-width:100px;">
                        <div style="font-size:20px;font-weight:600;color:<?php echo esc_attr( $color ); ?>;"><?php echo intval( $s->count ); ?></div>
                        <div style="font-size:11px;color:#646970;text-transform:uppercase;"><?php echo esc_html( $label ); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <table class="wp-list-table widefat fixed striped" style="margin-top:10px;">
            <thead>
                <tr>
                    <th style="width:100px;"><?php esc_html_e( 'Time', 'skyhs-hosting-solution' ); ?></th>
                    <th style="width:100px;"><?php esc_html_e( 'Type', 'skyhs-hosting-solution' ); ?></th>
                    <th style="width:80px;"><?php esc_html_e( 'Status', 'skyhs-hosting-solution' ); ?></th>
                    <th><?php esc_html_e( 'Message', 'skyhs-hosting-solution' ); ?></th>
                    <th style="width:80px;"><?php esc_html_e( 'Sub ID', 'skyhs-hosting-solution' ); ?></th>
                    <th style="width:80px;"><?php esc_html_e( 'Order ID', 'skyhs-hosting-solution' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $logs ) ) : ?>
                    <tr><td colspan="6"><?php esc_html_e( 'No log entries found for this date.', 'skyhs-hosting-solution' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $logs as $log ) : ?>
                        <tr>
                            <td><?php echo esc_html( gmdate( 'H:i:s', strtotime( $log->created_at ) ) ); ?></td>
                            <td>
                                <span style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;background:#f0f0f1;color:#50575e;">
                                    <?php echo esc_html( isset( $log_types[ $log->log_type ] ) ? $log_types[ $log->log_type ] : $log->log_type ); ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                $status_labels = array(
                                    'success' => __( 'OK', 'skyhs-hosting-solution' ),
                                    'error'   => __( 'FAIL', 'skyhs-hosting-solution' ),
                                    'warning' => __( 'Warn', 'skyhs-hosting-solution' ),
                                    'info'    => __( 'Info', 'skyhs-hosting-solution' ),
                                );
                                $status_css = array(
                                    'success' => 'color:#00a32a;font-weight:600;',
                                    'error'   => 'color:#d63638;font-weight:600;',
                                    'warning' => 'color:#dba617;font-weight:600;',
                                    'info'    => 'color:#2271b1;',
                                );
                                $label = isset( $status_labels[ $log->status ] ) ? $status_labels[ $log->status ] : $log->status;
                                $css   = isset( $status_css[ $log->status ] ) ? $status_css[ $log->status ] : '';
                                ?>
                                <span style="<?php echo esc_attr( $css ); ?>"><?php echo esc_html( $label ); ?></span>
                            </td>
                            <td><?php echo esc_html( $log->message ); ?></td>
                            <td>
                                <?php if ( $log->subscription_id ) : ?>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=skyhshoso-subscriptions&action=edit&subscription=' . $log->subscription_id ) ); ?>">#<?php echo intval( $log->subscription_id ); ?></a>
                                <?php else : ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( $log->order_id ) : ?>
                                    <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $log->order_id . '&action=edit' ) ); ?>">#<?php echo intval( $log->order_id ); ?></a>
                                <?php else : ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ( $total > $per_page ) : ?>
            <div style="margin-top:15px;">
                <div class="tablenav">
                    <div class="tablenav-pages">
                        <?php
                        $total_pages = ceil( $total / $per_page );
                        for ( $i = 1; $i <= $total_pages; $i++ ) :
                            $page_url = add_query_arg( array(
                                'page'      => 'skyhshoso-settings',
                                'tab'       => 'logs',
                                'log_date'  => $selected_date,
                                'log_type'  => $selected_type,
                                'log_paged' => $i,
                            ), admin_url( 'admin.php' ) );
                            $is_current = $i === $paged;
                            ?>
                            <a href="<?php echo esc_url( $page_url ); ?>" class="button <?php echo $is_current ? 'button-primary' : ''; ?>" style="margin-right:4px;"><?php echo intval( $i ); ?></a>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div style="margin-top:20px;padding:12px;background:#f6f7f7;border-radius:4px;font-size:12px;color:#646970;">
            <?php esc_html_e( 'Logs are automatically cleaned up after 90 days. Use these logs to verify that daily subscription processing, renewals, and email notifications are working correctly.', 'skyhs-hosting-solution' ); ?>
        </div>
        <?php
    }

    /**
     * AJAX handler: send a test email for a given template type.
     */
    public function ajax_send_test_email() {
        if ( ! isset( $_POST['nonce'], $_POST['email_type'] ) ) {
            wp_send_json_error( array( 'message' => __( 'Missing parameters.', 'skyhs-hosting-solution' ) ) );
        }

        if ( ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_test_email' ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'skyhs-hosting-solution' ) ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'skyhs-hosting-solution' ) ) );
        }

        $etype = sanitize_text_field( wp_unslash( $_POST['email_type'] ) );
        $valid_types = array( 'provisioning', 'suspension', 'reminder', 'termination_notice', 'terminated', 'deletion_warning' );

        if ( ! in_array( $etype, $valid_types, true ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid email type.', 'skyhs-hosting-solution' ) ) );
        }

        // Determine recipient.
        $recipient = isset( $_POST['recipient'] ) ? sanitize_email( wp_unslash( $_POST['recipient'] ) ) : '';
        if ( empty( $recipient ) || ! is_email( $recipient ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid email address.', 'skyhs-hosting-solution' ) ) );
        }

        // Build sample data for the template.
        $sample_data = $this->get_test_email_data( $etype );

        // Render and send.
        if ( ! class_exists( 'SkyHSHOSO_Emails' ) ) {
            wp_send_json_error( array( 'message' => __( 'Email class not available.', 'skyhs-hosting-solution' ) ) );
        }

        $subject = SkyHSHOSO_Emails::render_template( $etype, 'subject', $sample_data );
        if ( empty( $subject ) ) {
            $subject = SkyHSHOSO_Emails::get_default_subject( $etype );
            foreach ( $sample_data as $var => $value ) {
                $subject = str_replace( '{{' . $var . '}}', $value, $subject );
            }
        }

        $body = SkyHSHOSO_Emails::render_template( $etype, 'body', $sample_data );
        if ( empty( $body ) ) {
            $body = SkyHSHOSO_Emails::get_default_body( $etype );
            foreach ( $sample_data as $var => $value ) {
                $body = str_replace( '{{' . $var . '}}', $value, $body );
            }
        }

        // Temporarily disable test mode for this send.
        add_filter( 'skyhshoso_force_send_test', '__return_true' );

        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        $sent    = wp_mail( $recipient, $subject, $body, $headers );

        remove_filter( 'skyhshoso_force_send_test', '__return_true' );

        if ( $sent ) {
            /* translators: %s: email address */
            wp_send_json_success( array( 'message' => sprintf( __( 'Test email sent to %s', 'skyhs-hosting-solution' ), $recipient ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Failed to send test email.', 'skyhs-hosting-solution' ) ) );
        }
    }

    /**
     * AJAX handler: render email preview HTML (no send).
     */
    public function ajax_preview_email() {
        if ( ! isset( $_POST['nonce'], $_POST['email_type'] ) ) {
            wp_send_json_error( array( 'message' => __( 'Missing parameters.', 'skyhs-hosting-solution' ) ) );
        }

        if ( ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_preview_email' ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'skyhs-hosting-solution' ) ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'skyhs-hosting-solution' ) ) );
        }

        $etype = sanitize_text_field( wp_unslash( $_POST['email_type'] ) );
        $valid_types = array( 'provisioning', 'suspension', 'reminder', 'termination_notice', 'terminated', 'deletion_warning' );

        if ( ! in_array( $etype, $valid_types, true ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid email type.', 'skyhs-hosting-solution' ) ) );
        }

        if ( ! class_exists( 'SkyHSHOSO_Emails' ) ) {
            wp_send_json_error( array( 'message' => __( 'Email class not available.', 'skyhs-hosting-solution' ) ) );
        }

        $sample_data = $this->get_test_email_data( $etype );

        $subject = SkyHSHOSO_Emails::render_template( $etype, 'subject', $sample_data );
        if ( empty( $subject ) ) {
            $subject = SkyHSHOSO_Emails::get_default_subject( $etype );
            foreach ( $sample_data as $var => $value ) {
                $subject = str_replace( '{{' . $var . '}}', $value, $subject );
            }
        }

        $body = SkyHSHOSO_Emails::render_template( $etype, 'body', $sample_data );
        if ( empty( $body ) ) {
            $body = SkyHSHOSO_Emails::get_default_body( $etype );
            foreach ( $sample_data as $var => $value ) {
                $body = str_replace( '{{' . $var . '}}', $value, $body );
            }
        }

        wp_send_json_success( array(
            'subject'    => $subject,
            'body'       => $body,
            'email_type' => $etype,
        ) );
    }

    /**
     * Get sample data for test email rendering.
     *
     * @param string $etype
     * @return array
     */
    private function get_test_email_data( $etype ) {
        $current_user = wp_get_current_user();

        $base = array(
            'site_name' => get_bloginfo( 'name' ),
        );

        switch ( $etype ) {
            case 'provisioning':
                return array_merge( $base, array(
                    'domain'       => 'example.com',
                    'plan'         => 'Starter Plan',
                    'server_name'  => 'Server 01',
                    'server_ip'    => '203.0.113.1',
                    'cpanel_url'   => 'https://example.com:2083',
                    'username'     => 'exampledemo',
                    'password'     => 'SamplePass123!',
                    'nameservers'  => 'ns1.example.com<br>ns2.example.com',
                ) );
            case 'suspension':
                return array_merge( $base, array(
                    'subscription_id' => '42',
                ) );
            case 'reminder':
                return array_merge( $base, array(
                    'display_name' => $current_user->display_name,
                    'renewal_date' => date_i18n( get_option( 'date_format' ), strtotime( '+7 days' ) ),
                    'amount'       => wc_price( 29.99 ),
                ) );
            case 'termination_notice':
                return array_merge( $base, array(
                    'end_date' => date_i18n( get_option( 'date_format' ), strtotime( '+30 days' ) ),
                ) );
            case 'deletion_warning':
                return array_merge( $base, array(
                    'display_name'     => $current_user->display_name,
                    'termination_date' => date_i18n( get_option( 'date_format' ), strtotime( '+3 days' ) ),
                    'days_left'        => '3',
                ) );
            case 'terminated':
                return $base;
            default:
                return $base;
        }
    }

}

// Initialize settings
new SkyHSHOSO_Settings();