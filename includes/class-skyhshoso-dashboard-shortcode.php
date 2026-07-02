<?php
declare(strict_types=1);
/**
 * SkyHS Dashboard Shortcode
 *
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

/**
 * SkyHS Dashboard Shortcode Class
 */
class SkyHSHOSO_Dashboard_Shortcode {

    /**
     * Initialize the shortcode
     */
    public static function init() {
        add_shortcode( 'skyhshoso_dashboard', array( self::class, 'render_dashboard' ) );
        add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
        add_action( 'wp_ajax_skyhshoso_get_hosting_page', array( self::class, 'ajax_get_hosting_page' ) );
        add_action( 'wp_ajax_skyhshoso_get_domain_page', array( self::class, 'ajax_get_domain_page' ) );
        add_action( 'wp_ajax_skyhshoso_get_wp_site_page', array( self::class, 'ajax_get_wp_site_page' ) );
    }

    /**
     * Get the base URL of the dashboard page.
     * * @return string
     */
    private static function get_base_url() {
        return get_permalink( get_queried_object_id() );
    }

    /**
     * Enqueue assets for the dashboard.
     */
    public static function enqueue_assets() {
        global $post;
        if ( is_singular() && is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'skyhshoso_dashboard' ) ) {
            wp_enqueue_style(
                'skyhshoso-dashboard',
                SKYHSHOSO_PLUGIN_URL . 'assets/css/skyhshoso-dashboard.css',
                array(),
                SKYHSHOSO_VERSION
            );

            // Add inline styles for material toggle
            $toggle_styles = '
            .skyhshoso-material-toggle-container {
                display: inline-flex;
                position: relative;
                margin-bottom: 24px;
                background-color: #f1f3f4;
                border-radius: 24px;
                padding: 4px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.12);
                overflow: hidden;
                z-index: 1;
            }
            .skyhshoso-material-toggle-option {
                position: relative;
                z-index: 2;
            }
            .skyhshoso-material-toggle-input {
                opacity: 0;
                position: absolute;
                width: 0;
                height: 0;
            }
            .skyhshoso-material-toggle-label {
                display: inline-block;
                padding: 8px 16px;
                font-size: 14px;
                font-weight: 500;
                color: #5f6368;
                cursor: pointer;
                transition: color 0.3s ease;
                text-align: center;
                min-width: 80px;
                border-radius: 20px;
                user-select: none;
            }
            .skyhshoso-material-toggle-input:checked + .skyhshoso-material-toggle-label {
                color: #1a73e8;
            }
            .skyhshoso-material-toggle-slider {
                position: absolute;
                top: 4px;
                left: 4px;
                height: calc(100% - 8px);
                border-radius: 20px;
                background-color: white;
                transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), width 0.2s ease;
                box-shadow: 0 1px 3px rgba(0,0,0,0.12), 0 1px 2px rgba(0,0,0,0.24);
                z-index: 1;
            }
            .skyhshoso-badge-container {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                margin-bottom: 8px;
            }
            .skyhshoso-badge {
                display: inline-block;
                padding: 4px 12px;
                border-radius: 16px;
                font-size: 12px;
                font-weight: 500;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .skyhshoso-sale-badge {
                background-color: #212121;
                color: white;
                box-shadow: 0 2px 4px rgba(33, 33, 33, 0.3);
            }
            .skyhshoso-trial-badge {
                background-color: #1976d2;
                color: white;
                box-shadow: 0 2px 4px rgba(25, 118, 210, 0.3);
            }
            .skyhshoso-guest2-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
                margin-top: 24px;
            }
            .skyhshoso-guest2-grid .skyhshoso-card {
                margin: 0;
                height: 100%;
                box-sizing: border-box;
                display: flex;
                flex-direction: column;
                background: #fff;
                border: 1px solid #e2e8f0;
                border-radius: 12px;
                transition: box-shadow 0.2s;
            }
            .skyhshoso-guest2-grid .skyhshoso-card:hover {
                box-shadow: 0 8px 25px rgba(0,0,0,0.08);
            }
            .skyhshoso-guest2-grid .skyhshoso-card-content {
                flex: 1;
                display: flex;
                flex-direction: column;
                justify-content: space-between;
                padding: 24px;
                gap: 16px;
            }
            .skyhshoso-guest2-grid .skyhshoso-card-text {
                flex: 1;
            }
            .skyhshoso-guest2-grid .skyhshoso-card-title {
                font-size: 18px;
                font-weight: 700;
                color: #0f172a;
                margin: 0 0 8px;
            }
            .skyhshoso-guest2-grid .skyhshoso-card-description {
                font-size: 14px;
                color: #64748b;
                line-height: 1.6;
                margin: 0;
            }
            .skyhshoso-guest2-grid .skyhshoso-card-button {
                align-self: flex-start;
            }
            @media (max-width: 768px) {
                .skyhshoso-guest2-grid {
                    grid-template-columns: 1fr;
                }
            }
            .skyhshoso-fi {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 20px;
                height: 20px;
                margin-right: 8px;
                flex-shrink: 0;
                vertical-align: middle;
            }
            .skyhshoso-fi svg {
                width: 18px;
                height: 18px;
                display: block;
            }';
            wp_add_inline_style( 'skyhshoso-dashboard', $toggle_styles );

            wp_enqueue_script(
                'skyhshoso-product-shortcode',
                SKYHSHOSO_PLUGIN_URL . 'assets/js/product-shortcode.js',
                array(),
                SKYHSHOSO_VERSION,
                true
            );

            wp_enqueue_script(
                'skyhshoso-dashboard',
                SKYHSHOSO_PLUGIN_URL . 'assets/js/dashboard.js',
                array( 'jquery' ),
                SKYHSHOSO_VERSION,
                true
            );

            // Localize script with all needed data
            wp_localize_script(
                'skyhshoso-dashboard',
                'skyhshosoDashboard',
                array(
                    'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
                    'nonce'              => wp_create_nonce( 'skyhshoso_dashboard_nonce' ),
                    'wpProvisionNonce'   => wp_create_nonce( 'skyhshoso_wp_provision' ),
                    'collaboratorNonce'  => wp_create_nonce( 'skyhshoso-collaborator-nonce' ),
                    'switchNonce'        => wp_create_nonce( 'skyhshoso_switch_nonce' ),
                    'i18n'               => array(
                        'loading'          => __( 'Loading...', 'skyhs-hosting-solution' ),
                        'adding'           => __( 'Adding...', 'skyhs-hosting-solution' ),
                        'sending'          => __( 'Sending...', 'skyhs-hosting-solution' ),
                        'submitting'       => __( 'Submitting...', 'skyhs-hosting-solution' ),
                        'error'            => __( 'An error occurred. Please try again.', 'skyhs-hosting-solution' ),
                        'confirmRemove'    => __( 'Are you sure you want to remove this collaborator?', 'skyhs-hosting-solution' ),
                        'switchError'      => __( 'Failed to switch plan. Please try again.', 'skyhs-hosting-solution' ),
                    ),
                )
            );
        }
    }

    /**
     * Render the dashboard
     *
     * @param array $atts Shortcode attributes.
     * @return string
     */
    public static function render_dashboard( $atts ) {
        // Start output buffering
        ob_start();
        
        // Get active tab
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $active_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'dashboard';
        
        // Check if user is logged in
        $is_logged_in = is_user_logged_in();
        
        // Special case for non-logged in users accessing product pages
        $allow_access = false;
        if (!$is_logged_in) {
            // Only allow guest access if the admin has enabled it
            if ( SkyHSHOSO_Settings::is_guest_dashboard_enabled() ) {
                // phpcs:disable WordPress.Security.NonceVerification.Recommended
                $is_guest_dashboard   = $active_tab === 'dashboard';
                $is_new_hosting       = $active_tab === 'skyhshoso_hosting' && isset($_GET['new_hosting']) && sanitize_text_field( wp_unslash($_GET['new_hosting']) ) === '1';
                $is_new_domain        = $active_tab === 'domains' && isset($_GET['new_domain']) && sanitize_text_field( wp_unslash($_GET['new_domain']) ) === '1' && ! SkyHSHOSO_Settings::is_domain_registration_disabled();
                $is_transfer_domain   = $active_tab === 'domains' && isset($_GET['transfer_domain']) && sanitize_text_field( wp_unslash($_GET['transfer_domain']) ) === '1' && ! SkyHSHOSO_Settings::is_domain_registration_disabled();
                $is_new_wp_site       = $active_tab === 'wp_sites' && isset($_GET['new_wp_site']) && sanitize_text_field( wp_unslash($_GET['new_wp_site']) ) === '1';
                // phpcs:enable WordPress.Security.NonceVerification.Recommended

                if ($is_guest_dashboard || $is_new_hosting || $is_new_domain || $is_transfer_domain || $is_new_wp_site) {
                    $allow_access = true;
                }
            }
            if ( ! $allow_access ) {
                // Redirect to login for other dashboard pages
                wp_safe_redirect( wc_get_page_permalink('myaccount') );
                exit;
            }
        }

        // Get current user info (if logged in)
        $username = '';
        if ($is_logged_in) {
            $current_user = wp_get_current_user();
            $username = $current_user->display_name;
        }
        
        // Begin output
        ?>
        <div class="skyhshoso-dashboard-container">
            <?php
            // Add ajaxurl variable as inline script for backwards compatibility
            wp_add_inline_script( 'skyhshoso-dashboard', 'var ajaxurl = "' . esc_url(admin_url('admin-ajax.php')) . '";' );
            ?>

            <div class="skyhshoso-layout-container">
                <div class="skyhshoso-content-wrapper">
                    <div class="skyhshoso-content-container">
                    <?php if ($is_logged_in): ?>
                        <div class="skyhshoso-header">
                            <div class="skyhshoso-header-content">
                                <?php
                                $header_title = __( 'Dashboard', 'skyhs-hosting-solution' );
                                /* translators: %s: User's display name. */
                                $header_subtitle = sprintf( __( 'Welcome back, %s! Here\'s an overview of your services.', 'skyhs-hosting-solution' ), esc_html( $username ) );

                                switch ( $active_tab ) {
                                    case 'skyhshoso_hosting':
                                        $header_title = __( 'Hosting', 'skyhs-hosting-solution' );
                                        $header_subtitle = __( 'Manage your active web hosting plans, server configurations, and email accounts.', 'skyhs-hosting-solution' );
                                        break;
                                    case 'domains':
                                        $header_title = __( 'Domains', 'skyhs-hosting-solution' );
                                        $header_subtitle = __( 'Register new domains, manage transfers, and configure your DNS settings.', 'skyhs-hosting-solution' );
                                        break;
                                    case 'wp_sites':
                                        $header_title = __( 'WordPress Sites', 'skyhs-hosting-solution' );
                                        $header_subtitle = __( 'Manage your WordPress website installations.', 'skyhs-hosting-solution' );
                                        break;
                                    case 'collaborators':
                                        $header_title = __( 'Collaborators', 'skyhs-hosting-solution' );
                                        $header_subtitle = __( 'Manage team members and grant secure access to your hosting environments.', 'skyhs-hosting-solution' );
                                        break;
                                    case 'subscriptions':
                                        $header_title = __( 'My Subscriptions', 'skyhs-hosting-solution' );
                                        $header_subtitle = __( 'View and manage your active subscriptions, renewals, and payment history.', 'skyhs-hosting-solution' );
                                        break;
                                    case 'account':
                                        $header_title = __( 'Account', 'skyhs-hosting-solution' );
                                        $header_subtitle = __( 'Update your profile information, password, and manage your account settings.', 'skyhs-hosting-solution' );
                                        break;
                                }
                                ?>
                                <p class="skyhshoso-header-title"><?php echo esc_html( $header_title ); ?></p>
                                <p class="skyhshoso-header-subtitle"><?php echo esc_html( $header_subtitle ); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ( ! $is_logged_in ) : ?>
                        <div class="skyhshoso-header">
                            <div class="skyhshoso-header-content">
                                <p class="skyhshoso-header-title"><?php echo esc_html( SkyHSHOSO_Settings::get_guest_welcome_title() ); ?></p>
                                <p class="skyhshoso-header-subtitle"><?php echo esc_html( SkyHSHOSO_Settings::get_guest_welcome_subtitle() ); ?></p>
                                <?php $btn_text = SkyHSHOSO_Settings::get_guest_welcome_btn_text(); ?>
                                <?php $btn_url  = SkyHSHOSO_Settings::get_guest_welcome_btn_url(); ?>
                                <?php if ( $btn_text && $btn_url ) : ?>
                                    <a href="<?php echo esc_url( $btn_url ); ?>" class="skyhshoso-card-button" style="display:inline-block; margin-top:12px;">
                                        <span><?php echo esc_html( $btn_text ); ?></span>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php
                        if ( $is_logged_in ) {
                            self::render_dashboard_warning_notices();
                        }
                        ?>

                        <div class="skyhshoso-navigation">
                            <div class="skyhshoso-navigation-container">
                                <?php
                                $nav_items = SkyHSHOSO_Customize::get_dashboard_nav_items();
                                foreach ( $nav_items as $nav_item ) :
                                    if ( $nav_item['type'] === 'builtin' && isset( $nav_item['tab'] ) ) {
                                        $is_active = $active_tab === $nav_item['tab'];
                                        if ( $nav_item['tab'] === 'account' ) {
                                            $nav_url = $nav_item['url'] ?: wc_get_account_endpoint_url('dashboard');
                                        } else {
                                            $nav_url = add_query_arg( 'tab', $nav_item['tab'], self::get_base_url() );
                                        }
                                    } else {
                                        $nav_url = $nav_item['url'] ?? '#';
                                        $is_active = false;
                                        $nav_qs = (string) wp_parse_url( $nav_url, PHP_URL_QUERY );
                                        if ( $nav_qs ) {
                                            parse_str( $nav_qs, $nav_params );
                                            $current_params = $_GET;
                                            $match = true;
                                            foreach ( $nav_params as $key => $val ) {
                                                if ( ! isset( $current_params[ $key ] ) || (string) $current_params[ $key ] !== (string) $val ) {
                                                    $match = false;
                                                    break;
                                                }
                                            }
                                            $is_active = $match;
                                        }
                                    }
                                ?>
                                <a class="skyhshoso-nav-item <?php echo $is_active ? 'active' : ''; ?>" href="<?php echo esc_url( $nav_url ); ?>">
                                    <p class="skyhshoso-nav-text"><?php echo esc_html( $nav_item['title'] ); ?></p>
                                </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <?php if ($is_logged_in && $active_tab === 'dashboard') : ?>
                        
                        <div class="skyhshoso-dashboard-grid">
                            <div class="skyhshoso-dashboard-main">
                                <h2 class="skyhshoso-section-title"><?php esc_html_e('Hosting Plans', 'skyhs-hosting-solution'); ?></h2>
                                <div class="skyhshoso-section-content">
                                    <?php
                                    $current_user_id = get_current_user_id();
                                    $hosting_args = array(
                                        'post_type' => 'skyhshoso_hosting',
                                        'posts_per_page' => 1,
                                    );
                                    if (!current_user_can('administrator')) {
                                        $invited_by = get_user_meta($current_user_id, 'skyhshoso_invited_by', true);
                                        $invited_by = is_array($invited_by) ? $invited_by : array();
                                        if (!empty($invited_by)) {
                                            $hosting_args['author__in'] = array_merge(array($current_user_id), $invited_by);
                                        } else {
                                            $hosting_args['author'] = $current_user_id;
                                        }
                                    }
                                    $hosting_query = new WP_Query($hosting_args);
                                    if ($hosting_query->have_posts()) {
                                        $hosting_query->the_post();
                                        $hosting_id = get_the_ID();
                                        $hosting_domain = get_post_meta($hosting_id, 'skyhshoso_hosting_domain', true);
                                        $subscription_id = get_post_meta($hosting_id, 'skyhshoso_subscription_id', true);
                                        $subscription_status = 'inactive';
                                        if (!empty($subscription_id)) {
                                            $subscription = skyhshoso_get_subscription($subscription_id);
                                            if ($subscription) {
                                                $subscription_status = $subscription->get_status();
                                            }
                                        }
                                        $display_status = ucwords(str_replace('-', ' ', $subscription_status));
                                    ?>
                                    <div class="skyhshoso-card">
                                        <div class="skyhshoso-card-content">
                                            <div class="skyhshoso-card-text">
                                                <p class="skyhshoso-card-title"><?php the_title(); ?></p>
                                                <p class="skyhshoso-card-description">
                                                    <?php if (!empty($hosting_domain)): ?>
                                                    <?php esc_html_e('Domain:', 'skyhs-hosting-solution'); ?> <?php echo esc_html($hosting_domain); ?><br>
                                                    <?php endif; ?>
                                                    Status: <?php echo esc_html($display_status); ?>
                                                </p>
                                            </div>
                                            <a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'skyhshoso_hosting', 'hosting_id' => $hosting_id ), self::get_base_url() ) ); ?>" class="skyhshoso-card-button">
                                                <span><?php esc_html_e('Manage', 'skyhs-hosting-solution'); ?></span>
                                            </a>
                                        </div>
                                    </div>
                                    <?php
                                        wp_reset_postdata();
                                    } else {
                                    ?>
                                    <div class="skyhshoso-card">
                                        <div class="skyhshoso-card-content">
                                            <div class="skyhshoso-card-text">
                                                <p class="skyhshoso-card-title"><?php esc_html_e('No Hosting Plans', 'skyhs-hosting-solution'); ?></p>
                                                <p class="skyhshoso-card-description">
                                                    <?php esc_html_e('You don\'t have any hosting plans yet. Click the button to browse available hosting options.', 'skyhs-hosting-solution'); ?>
                                                </p>
                                            </div>
                                            <a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'skyhshoso_hosting', 'new_hosting' => 1 ), self::get_base_url() ) ); ?>" class="skyhshoso-card-button">
                                                <span><?php esc_html_e('Buy Hosting', 'skyhs-hosting-solution'); ?></span>
                                            </a>
                                        </div>
                                    </div>
                                    <?php } ?>
                                </div>
                            </div>

                            <div class="skyhshoso-dashboard-sidebar">
                                <?php
                                $domains_handler = new SkyHSHOSO_Account_Domains();
                                $domains_grouped = $domains_handler->get_all_accessible_domains();
                                $has_domain = false;
                                $first_domain = null;
                                if (isset($domains_grouped['your']) && !empty($domains_grouped['your'])) {
                                    $first_domain = $domains_grouped['your'][0];
                                    $has_domain = true;
                                } elseif (current_user_can('administrator') && isset($domains_grouped['all']) && !empty($domains_grouped['all'])) {
                                    $first_domain = $domains_grouped['all'][0];
                                    $has_domain = true;
                                } else {
                                    foreach ($domains_grouped as $key => $group) {
                                        if ($key !== 'your' && isset($group['domains']) && !empty($group['domains'])) {
                                            $first_domain = $group['domains'][0];
                                            $has_domain = true;
                                            break;
                                        }
                                    }
                                }
                                ?>
                                <?php if ($has_domain || ! SkyHSHOSO_Settings::is_domain_registration_disabled()) : ?>
                                <h2 class="skyhshoso-section-title"><?php esc_html_e('Domains', 'skyhs-hosting-solution'); ?></h2>
                                <div class="skyhshoso-section-content">
                                    <?php if ($has_domain) : ?>
                                    <div class="skyhshoso-card">
                                        <div class="skyhshoso-card-content">
                                            <div class="skyhshoso-card-text">
                                                <p class="skyhshoso-card-title"><?php echo esc_html($first_domain['title']); ?></p>
                                                <p class="skyhshoso-card-description">
                                                    <?php esc_html_e('Status:', 'skyhs-hosting-solution'); ?> <?php echo esc_html(ucwords($first_domain['status'])); ?>
                                                </p>
                                            </div>
                                            <?php if ($first_domain['can_manage_dns']) : ?>
                                            <a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'domains', 'domain_id' => absint( $first_domain['id'] ), 'dns' => 'manage' ), self::get_base_url() ) ); ?>" class="skyhshoso-card-button">
                                                <span><?php esc_html_e('Manage DNS', 'skyhs-hosting-solution'); ?></span>
                                            </a>
                                            <?php else: ?>
                                            <a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'domains', 'domain_id' => absint( $first_domain['id'] ) ), self::get_base_url() ) ); ?>" class="skyhshoso-card-button">
                                                <span><?php esc_html_e('View Details', 'skyhs-hosting-solution'); ?></span>
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php else : ?>
                                    <div class="skyhshoso-card">
                                        <div class="skyhshoso-card-content">
                                            <div class="skyhshoso-card-text">
                                                <p class="skyhshoso-card-title"><?php esc_html_e('No Domains', 'skyhs-hosting-solution'); ?></p>
                                                <p class="skyhshoso-card-description">
                                                    <?php esc_html_e('You don\'t have any domains yet. Click the button to register a new domain.', 'skyhs-hosting-solution'); ?>
                                                </p>
                                            </div>
                                            <a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'domains', 'new_domain' => 1 ), self::get_base_url() ) ); ?>" class="skyhshoso-card-button">
                                                <span><?php esc_html_e('Register Domain', 'skyhs-hosting-solution'); ?></span>
                                            </a>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>

                                <?php
                                $invited_users = get_user_meta($current_user_id, 'skyhshoso_invited_users', true);
                                $invited_users = is_array($invited_users) ? $invited_users : array();
                                $invited_by_collab = get_user_meta($current_user_id, 'skyhshoso_invited_by', true);
                                $invited_by_collab = is_array($invited_by_collab) ? $invited_by_collab : array();
                                $collaborator_count = count($invited_users) + count($invited_by_collab);
                                ?>
                                <h2 class="skyhshoso-section-title"><?php esc_html_e('Collaborators', 'skyhs-hosting-solution'); ?> <span class="skyhshoso-badge"><?php echo esc_html( intval( $collaborator_count ) ); ?></span></h2>
                                <div class="skyhshoso-section-content">
                                    <div class="skyhshoso-card">
                                        <div class="skyhshoso-card-content">
                                            <div class="skyhshoso-card-text">
                                                <p class="skyhshoso-card-title"><?php esc_html_e('Team Members', 'skyhs-hosting-solution'); ?></p>
                                                <p class="skyhshoso-card-description">
                                                    <?php if ($collaborator_count > 0): ?>
                                                    <?php esc_html_e('Manage access for your team members. Currently, you have', 'skyhs-hosting-solution'); ?> <?php echo esc_html( intval( $collaborator_count ) ); ?> <?php esc_html_e('collaborator', 'skyhs-hosting-solution'); ?><?php echo $collaborator_count !== 1 ? 's' : ''; ?>.
                                                    <?php else: ?>
                                                    <?php esc_html_e('You don\'t have any collaborators yet. Invite team members to give them access to your hosting and domains.', 'skyhs-hosting-solution'); ?>
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                            <a href="<?php echo esc_url( add_query_arg( 'tab', 'collaborators', self::get_base_url() ) ); ?>" class="skyhshoso-card-button">
                                                <span><?php echo $collaborator_count > 0 ? esc_html__('Manage', 'skyhs-hosting-solution') : esc_html__('Invite User', 'skyhs-hosting-solution'); ?></span>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php
                        // cPanel Overview — full width below the grid
                        $overview_hosting_id = 0;
                        if ( $hosting_query->have_posts() ) {
                            $hosting_query->rewind_posts();
                            $hosting_query->the_post();
                            $overview_hosting_id = get_the_ID();
                            wp_reset_postdata();
                        }
                        if ( $overview_hosting_id ) :
                        ?>
                        <h2 class="skyhshoso-section-title"><?php esc_html_e( 'cPanel Overview', 'skyhs-hosting-solution' ); ?></h2>
                        <div class="skyhshoso-section-content">
                            <div class="skyhshoso-stats-grid" id="skyhshoso-stats-grid-<?php echo esc_attr( $overview_hosting_id ); ?>" data-hosting-id="<?php echo esc_attr( $overview_hosting_id ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'skyhshoso_dashboard_nonce' ) ); ?>">
                                <div class="skyhshoso-stat-card skyhshoso-stat-skeleton"><div class="skyhshoso-skeleton-icon"></div><div class="skyhshoso-skeleton-text"></div><div class="skyhshoso-skeleton-label"></div></div>
                                <div class="skyhshoso-stat-card skyhshoso-stat-skeleton"><div class="skyhshoso-skeleton-icon"></div><div class="skyhshoso-skeleton-text"></div><div class="skyhshoso-skeleton-label"></div></div>
                                <div class="skyhshoso-stat-card skyhshoso-stat-skeleton"><div class="skyhshoso-skeleton-icon"></div><div class="skyhshoso-skeleton-text"></div><div class="skyhshoso-skeleton-label"></div></div>
                                <div class="skyhshoso-stat-card skyhshoso-stat-skeleton"><div class="skyhshoso-skeleton-icon"></div><div class="skyhshoso-skeleton-text"></div><div class="skyhshoso-skeleton-label"></div></div>
                                <div class="skyhshoso-stat-card skyhshoso-stat-skeleton"><div class="skyhshoso-skeleton-icon"></div><div class="skyhshoso-skeleton-text"></div><div class="skyhshoso-skeleton-label"></div></div>
                                <div class="skyhshoso-stat-card skyhshoso-stat-skeleton"><div class="skyhshoso-skeleton-icon"></div><div class="skyhshoso-skeleton-text"></div><div class="skyhshoso-skeleton-label"></div></div>
                            </div>
                            <p style="margin-top:8px;font-size:12px;color:#60768a;">
                                <span class="skyhshoso-cpanel-status"><?php esc_html_e( 'Loading cPanel data...', 'skyhs-hosting-solution' ); ?></span>
                                <button class="skyhshoso-card-button skyhshoso-cpanel-refresh-btn" data-hosting-id="<?php echo esc_attr( $overview_hosting_id ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'skyhshoso_dashboard_nonce' ) ); ?>" style="display:inline-flex;margin-left:8px;font-size:12px;height:28px;">
                                    <span><?php esc_html_e( '↻ Refresh', 'skyhs-hosting-solution' ); ?></span>
                                </button>
                            </p>
                        </div>
                        <?php endif; ?>
                        <?php elseif (!$is_logged_in && $active_tab === 'dashboard') : ?>
                        <div class="skyhshoso-guest2-grid">
                            <div class="skyhshoso-card">
                                <div class="skyhshoso-card-content">
                                    <div class="skyhshoso-card-text">
                                        <p class="skyhshoso-card-title"><?php esc_html_e('Hosting Plans', 'skyhs-hosting-solution'); ?></p>
                                        <p class="skyhshoso-card-description"><?php esc_html_e('Choose from our range of hosting plans tailored to your needs. From shared hosting to dedicated servers, we have you covered.', 'skyhs-hosting-solution'); ?></p>
                                    </div>
                                    <a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'skyhshoso_hosting', 'new_hosting' => '1' ), self::get_base_url() ) ); ?>" class="skyhshoso-card-button">
                                        <span><?php esc_html_e('View Plans', 'skyhs-hosting-solution'); ?></span>
                                    </a>
                                </div>
                            </div>
                            <?php if ( ! SkyHSHOSO_Settings::is_domain_registration_disabled() ) : ?>
                            <div class="skyhshoso-card">
                                <div class="skyhshoso-card-content">
                                    <div class="skyhshoso-card-text">
                                        <p class="skyhshoso-card-title"><?php esc_html_e('Register a Domain', 'skyhs-hosting-solution'); ?></p>
                                        <p class="skyhshoso-card-description"><?php esc_html_e('Find and register the perfect domain name for your website. Check availability instantly.', 'skyhs-hosting-solution'); ?></p>
                                    </div>
                                    <a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'domains', 'new_domain' => '1' ), self::get_base_url() ) ); ?>" class="skyhshoso-card-button">
                                        <span><?php esc_html_e('Search Domains', 'skyhs-hosting-solution'); ?></span>
                                    </a>
                                </div>
                            </div>
                            <div class="skyhshoso-card">
                                <div class="skyhshoso-card-content">
                                    <div class="skyhshoso-card-text">
                                        <p class="skyhshoso-card-title"><?php esc_html_e('Transfer a Domain', 'skyhs-hosting-solution'); ?></p>
                                        <p class="skyhshoso-card-description"><?php esc_html_e('Already own a domain? Transfer it to us for easy management and competitive pricing.', 'skyhs-hosting-solution'); ?></p>
                                    </div>
                                    <a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'domains', 'transfer_domain' => '1' ), self::get_base_url() ) ); ?>" class="skyhshoso-card-button">
                                        <span><?php esc_html_e('Transfer', 'skyhs-hosting-solution'); ?></span>
                                    </a>
                                </div>
                            </div>
                            <?php endif; ?>
                            <div class="skyhshoso-card">
                                <div class="skyhshoso-card-content">
                                    <div class="skyhshoso-card-text">
                                        <p class="skyhshoso-card-title"><?php esc_html_e('Sign In', 'skyhs-hosting-solution'); ?></p>
                                        <p class="skyhshoso-card-description"><?php esc_html_e('Already a customer? Sign in to manage your hosting, domains, subscriptions, and more.', 'skyhs-hosting-solution'); ?></p>
                                    </div>
                                    <a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>" class="skyhshoso-card-button">
                                        <span><?php esc_html_e('Sign In', 'skyhs-hosting-solution'); ?></span>
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php elseif ($active_tab === 'skyhshoso_hosting') : ?>
                            <?php self::render_hosting_tab(); ?>
                        <?php elseif ($active_tab === 'domains') : ?>
                            <?php self::render_domains_tab(); ?>
                        <?php elseif ($active_tab === 'collaborators') : ?>
                            <div class="skyhshoso-hosting-header" style="justify-content: flex-end; margin-bottom: 0;">
                                <button id="skyhshoso-new-collaborator-btn" class="skyhshoso-new-hosting-btn">
                                    <span class="truncate"><?php esc_html_e('Invite User', 'skyhs-hosting-solution'); ?></span>
                                </button>
                            </div>
                            <div class="skyhshoso-section-content">
                                <?php self::render_collaborators_tab(); ?>
                            </div>
                        <?php elseif ($active_tab === 'wp_sites') : ?>
                            <?php self::render_wp_sites_tab(); ?>
                        <?php elseif ($active_tab === 'subscriptions') : ?>
                            <?php self::render_subscriptions_tab(); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
        
        // Return the buffered output
        return ob_get_clean();
    }
    
    /**
     * Render the hosting tab content
     */
    public static function render_hosting_tab() {
        // Get current user ID
        $current_user_id = get_current_user_id();
        
        // Flow control parameters
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $new_hosting = isset($_GET['new_hosting']);

        // Check if we're viewing a specific hosting
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $hosting_id = isset($_GET['hosting_id']) ? intval(wp_unslash($_GET['hosting_id'])) : 0;

        if ( $new_hosting ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $product_id = isset($_GET['product_id']) ? intval(wp_unslash($_GET['product_id'])) : 0;

            if ( $product_id ) {
                self::render_new_hosting_product_detail( $product_id );
            } else {
                self::render_new_hosting_products_list();
            }
            return;
        }
        
        if ($hosting_id) {
            self::render_hosting_detail($hosting_id);
            return;
        }
        
        // Search term
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $search_term = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';
        
        ?>
        <div class="skyhshoso-hosting-header" style="justify-content: flex-end; margin-bottom: 0;">
            <a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'skyhshoso_hosting', 'new_hosting' => 1 ), self::get_base_url() ) ); ?>" class="skyhshoso-new-hosting-btn">
                <span class="truncate"><?php esc_html_e('New Hosting', 'skyhs-hosting-solution'); ?></span>
            </a>
        </div>
        
        <div class="skyhshoso-search-container">
            <div class="skyhshoso-search-form">
                <div class="skyhshoso-search-input-wrapper">
                    <div class="skyhshoso-search-icon-wrapper">
                        <svg class="skyhshoso-search-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>
                    <input 
                        type="text" 
                        id="skyhshoso-hosting-search" 
                        placeholder="<?php esc_attr_e('Search hosting', 'skyhs-hosting-solution'); ?>" 
                        class="skyhshoso-search-input" 
                        value="<?php echo esc_attr($search_term); ?>"
                    >
                    <button type="button" class="skyhshoso-search-clear" aria-label="Clear search">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>
        
        <div class="skyhshoso-table-container" id="skyhshoso-hosting-table-container">
            <div class="skyhshoso-table-wrapper">
                <?php
                $paged = isset( $_GET['hpaged'] ) ? max( 1, intval( $_GET['hpaged'] ) ) : 1;

                $args = array(
                    'post_type'      => 'skyhshoso_hosting',
                    'posts_per_page' => 10,
                    'paged'          => $paged,
                );

                if ( ! empty( $search_term ) ) {
                    $args['s'] = $search_term;
                }

                if ( ! current_user_can( 'administrator' ) ) {
                    $invited_by = get_user_meta( $current_user_id, 'skyhshoso_invited_by', true );
                    $invited_by = is_array( $invited_by ) ? $invited_by : array();

                    if ( ! empty( $invited_by ) ) {
                        $args['author__in'] = array_merge( array( $current_user_id ), $invited_by );
                    } else {
                        $args['author'] = $current_user_id;
                    }
                }

                $hosting_query = new WP_Query( $args );
                $total_pages   = $hosting_query->max_num_pages;

                if ( $hosting_query->have_posts() ) {
                    ?>
                    <table class="skyhshoso-table" id="skyhshoso-hosting-table">
                        <thead>
                            <tr>
                                <th class="skyhshoso-column-plan"><?php esc_html_e( 'Hosting Plan', 'skyhs-hosting-solution' ); ?></th>
                                <th class="skyhshoso-column-domain"><?php esc_html_e( 'Domain', 'skyhs-hosting-solution' ); ?></th>
                                <th class="skyhshoso-column-status"><?php esc_html_e( 'Status', 'skyhs-hosting-solution' ); ?></th>
                                <th class="skyhshoso-column-action"><?php esc_html_e( 'Action', 'skyhs-hosting-solution' ); ?></th>
                            </tr>
                        </thead>
                        <tbody id="skyhshoso-hosting-tbody">
                            <?php
                            while ( $hosting_query->have_posts() ) {
                                $hosting_query->the_post();
                                $hosting_id       = get_the_ID();
                                $hosting_domain   = get_post_meta( $hosting_id, 'skyhshoso_hosting_domain', true );
                                $subscription_id  = get_post_meta( $hosting_id, 'skyhshoso_subscription_id', true );

                                $subscription_status = 'inactive';
                                $status_class        = 'skyhshoso-status-inactive';

                                if ( ! empty( $subscription_id ) ) {
                                    $subscription = skyhshoso_get_subscription( $subscription_id );
                                    if ( $subscription ) {
                                        $subscription_status = $subscription->get_status();
                                        if ( $subscription_status === 'active' || $subscription_status === 'pending-cancel' ) {
                                            $status_class = 'skyhshoso-status-active';
                                        }
                                    }
                                }

                                $display_status = str_replace( '-', ' ', $subscription_status );
                                $display_status = ucwords( $display_status );

                                $domain_display = ! empty( $hosting_domain ) ? esc_html( $hosting_domain ) : 'Not set';
                                ?>
                                <tr class="skyhshoso-hosting-row" data-title="<?php echo esc_attr( strtolower( get_the_title() ) ); ?>" data-domain="<?php echo esc_attr( strtolower( $domain_display ) ); ?>">
                                    <td class="skyhshoso-column-plan"><?php the_title(); ?></td>
                                    <td class="skyhshoso-column-domain"><?php echo esc_html( $domain_display ); ?></td>
                                    <td class="skyhshoso-column-status">
                                        <span class="skyhshoso-status-btn <?php echo esc_attr( $status_class ); ?>">
                                            <span class="truncate"><?php echo esc_html( $display_status ); ?></span>
                                        </span>
                                    </td>
                                    <td class="skyhshoso-column-action">
                                        <?php if ( in_array( $subscription_status, array( 'active', 'pending-cancel' ), true ) ) : ?>
                                            <a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'skyhshoso_hosting', 'hosting_id' => $hosting_id ), self::get_base_url() ) ); ?>" class="skyhshoso-action-link">
                                                <?php esc_html_e( 'Manage', 'skyhs-hosting-solution' ); ?>
                                            </a>
                                        <?php else : ?>
                                            <span class="skyhshoso-action-disabled" style="color:#999;font-size:14px;"><?php echo esc_html( $display_status ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php
                            }
                            ?>
                        </tbody>
                    </table>
                    <div id="skyhshoso-hosting-pagination" class="skyhshoso-pagination-container" data-total-pages="<?php echo esc_attr( $total_pages ); ?>" data-current-page="<?php echo esc_attr( $paged ); ?>" data-base-url="<?php echo esc_url( self::get_base_url() ); ?>"></div>
                    <?php
                } else {
                    ?>
                    <div class="skyhshoso-empty-message">
                        <p><?php esc_html_e('No hosting plans found. Click the "New Hosting" button to browse available hosting options.', 'skyhs-hosting-solution'); ?></p>
                    </div>
                    <?php
                }
                
                wp_reset_postdata();
                ?>
            </div>
            <div id="skyhshoso-hosting-no-results" style="display: none;" class="skyhshoso-empty-message">
                <p><?php esc_html_e('No matching hosting plans found.', 'skyhs-hosting-solution'); ?></p>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render the GoDaddy-Style Hosting Detail Control Panel
     * * @param int $hosting_id The hosting ID to display
     */
    public static function render_hosting_detail($hosting_id) {
        $hosting = get_post($hosting_id);
        
        if (!$hosting || $hosting->post_type !== 'skyhshoso_hosting') {
            echo '<div class="skyhshoso-section-content"><p>' . esc_html__('Hosting not found.', 'skyhs-hosting-solution') . '</p></div>';
            return;
        }
        
        // Permission Check
        $current_user_id = get_current_user_id();
        $hosting_author_id = $hosting->post_author;
        $invited_by = get_user_meta($current_user_id, 'skyhshoso_invited_by', true);
        $invited_by = is_array($invited_by) ? $invited_by : array();
        
        if ($current_user_id != $hosting_author_id && !current_user_can('administrator') && !in_array($hosting_author_id, $invited_by)) {
            echo '<div class="skyhshoso-section-content"><p>' . esc_html__('Permission denied.', 'skyhs-hosting-solution') . '</p></div>';
            return;
        }
        
        // Get Core Data
        $hosting_domain = get_post_meta($hosting_id, 'skyhshoso_hosting_domain', true) ?: 'Not configured';
        $subscription_id = get_post_meta($hosting_id, 'skyhshoso_subscription_id', true);
        $whm_user = get_post_meta($hosting_id, 'skyhshoso_hosting_username', true);
        
        $server_id   = get_post_meta($hosting_id, 'skyhshoso_server_id', true);
        $display_ip  = get_post_meta($hosting_id, '_skyhshoso_server_ip', true) ?: ($server_id ? get_post_meta($server_id, '_skyhshoso_server_ip', true) : 'Pending');
        
        $status_class = 'skyhshoso-status-inactive';
        $display_status = 'Inactive';
        $is_active = false;

        if (!empty($subscription_id)) {
            $subscription = skyhshoso_get_subscription($subscription_id);
            if ($subscription) {
                $status = $subscription->get_status();
                $display_status = ucwords(str_replace('-', ' ', $status));
                if (in_array($status, array('active', 'pending-cancel'))) {
                    $status_class = 'skyhshoso-status-active';
                    $is_active = true;
                }
            }
        }
        ?>

        <div class="skyhshoso-hosting-header" style="justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 16px; margin-bottom: 24px;">
            <div>
                <h2 style="margin:0; font-size:24px; font-weight:700; color:#0f172a;"><?php echo esc_html($hosting_domain); ?></h2>
                <span class="skyhshoso-status-btn <?php echo esc_attr($status_class); ?>" style="margin-top:8px; display:inline-block;"><?php echo esc_html($display_status); ?></span>
            </div>
            <a href="<?php echo esc_url( add_query_arg( 'tab', 'skyhshoso_hosting', self::get_base_url() ) ); ?>" class="skyhshoso-button skyhshoso-button-secondary">
                <span class="skyhshoso-button-text">&larr; <?php esc_html_e('Back to List', 'skyhs-hosting-solution'); ?></span>
            </a>
        </div>

        <?php if (!$is_active) : ?>
            <div class="skyhshoso-empty-message">
                <p><?php esc_html_e('This hosting plan is currently inactive. Please renew your subscription to access the management console.', 'skyhs-hosting-solution'); ?></p>
            </div>
        <?php return; endif; ?>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px;">

            <div class="skyhshoso-detail-card" style="margin:0; padding:24px;">
                <h3 style="font-size:16px; font-weight:700; margin-bottom:16px; border-bottom:1px solid #f1f5f9; padding-bottom:8px;">Infrastructure</h3>
                
                <div style="margin-bottom:16px;">
                    <span style="display:block; font-size:12px; color:#64748b; font-weight:600; text-transform:uppercase;">IP Address</span>
                    <span style="font-size:15px; font-family:monospace; color:#0f172a;"><?php echo esc_html($display_ip); ?></span>
                </div>

                <div style="margin-bottom:16px;">
                    <span style="display:block; font-size:12px; color:#64748b; font-weight:600; text-transform:uppercase;">cPanel Username</span>
                    <span style="font-size:15px; color:#0f172a; font-weight:500;"><?php echo esc_html($whm_user); ?></span>
                </div>

                <div id="skyhshoso-disk-usage-container" data-hosting-id="<?php echo esc_attr($hosting_id); ?>">
                    <span style="display:block; font-size:12px; color:#64748b; font-weight:600; text-transform:uppercase;">Disk Usage</span>
                    <div style="width:100%; background:#e2e8f0; border-radius:4px; height:8px; margin-top:6px; overflow:hidden;">
                        <div style="width:30%; background:#94a3b8; height:100%; transition: width 0.5s;" id="skyhs-disk-bar"></div> </div>
                    <span style="font-size:12px; color:#64748b; margin-top:4px; display:inline-block;" id="skyhs-disk-text">Loading metrics...</span>
                </div>
            </div>

            <div class="skyhshoso-detail-card" style="margin:0; padding:24px;">
                <h3 style="font-size:16px; font-weight:700; margin-bottom:16px; border-bottom:1px solid #f1f5f9; padding-bottom:8px;">Security & Access</h3>
                
                <div style="margin-bottom: 20px;">
                    <p style="font-size:13px; color:#475569; margin:0 0 8px 0;">Access your raw server files, databases, and emails.</p>
                    <button id="skyhshoso-cpanel-login-btn" class="skyhshoso-button skyhshoso-button-primary" style="width:100%; justify-content:center;" data-hosting-id="<?php echo esc_attr($hosting_id); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('skyhshoso_generate_cpanel_login_url_nonce')); ?>">
                        <?php esc_html_e('Open cPanel', 'skyhs-hosting-solution'); ?>
                    </button>
                </div>

                <div style="margin-bottom: 20px; display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <span style="display:block; font-size:14px; font-weight:600; color:#0f172a;">cPanel Password</span>
                        <span style="font-size:12px; color:#64748b;">Update your server password.</span>
                    </div>
                    <button class="skyhshoso-button skyhshoso-button-secondary" id="skyhshoso-trigger-pass-reset" style="padding: 6px 12px; font-size:12px;">Reset</button>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <span style="display:block; font-size:14px; font-weight:600; color:#0f172a;">SSH Access</span>
                        <span style="font-size:12px; color:#64748b;">Allow terminal connections.</span>
                    </div>
                    <label class="skyhshoso-switch">
                        <input type="checkbox" id="skyhshoso-ssh-toggle" data-hosting-id="<?php echo esc_attr($hosting_id); ?>">
                        <span class="skyhshoso-slider round"></span>
                    </label>
                </div>
            </div>

            <div class="skyhshoso-detail-card" style="margin:0; padding:24px; grid-column: 1 / -1;">
                <h3 style="font-size:16px; font-weight:700; margin-bottom:16px; border-bottom:1px solid #f1f5f9; padding-bottom:8px; display:flex; align-items:center; gap:8px;">
                    <svg style="width:20px; height:20px; color:#2563eb;" viewBox="0 0 24 24" fill="currentColor"><path d="M12.158 12.786l-2.698 7.84c.806.236 1.657.365 2.54.365 1.047 0 2.05-.18 2.986-.51-.024-.037-.046-.078-.065-.123l-2.763-7.572zm5.883-7.368c-.682-.315-1.226-.48-1.62-.48-.683 0-1.025.328-1.025.86 0 .46.205 1.05.614 1.77.368.64.914 1.83 1.637 3.56l2.185 5.86c.01-.06.015-.12.015-.18 0-2.313-1.066-6.196-1.806-11.39zm-10.748.24c-.03-.1-.06-.184-.09-.253-.133-.316-.36-.453-.68-.41-.334.043-.88.163-1.64.36l-.37-.87c1.378-.455 2.502-.682 3.37-.682.72 0 1.2.146 1.44.437.24.292.36.702.36 1.23 0 .723-.198 1.806-.593 3.25l-2.457 7.02c-.896-1.144-1.652-2.58-2.268-4.306-.328-.908-.492-1.69-.492-2.348 0-.82.164-1.428.492-1.823.328-.396.908-.63 1.74-.702l.187-.903zm8.396 7.42l-2.253-6.52c-.15-.436-.226-.816-.226-1.14 0-.356.096-.63.288-.82.192-.19.467-.286.824-.286.136 0 .313.018.53.054l.135-.88c-1.32-.206-2.355-.31-3.105-.31-.76 0-1.746.104-2.955.31l.142.87c.238-.035.422-.053.553-.053.385 0 .684.09.897.27.213.18.368.49.464.93l2.872 8.442 3.83-8.868zm-3.69-11.08c-5.522 0-10 4.477-10 10s4.478 10 10 10 10-4.477 10-10-4.478-10-10-10zm0 18.8c-4.86 0-8.8-3.94-8.8-8.8s3.94-8.8 8.8-8.8 8.8 3.94 8.8 8.8-3.94 8.8-8.8 8.8z"/></svg>
                    WordPress Management
                </h3>
                
                <div style="display:flex; gap:16px; align-items: flex-end;">
                    <div style="flex:1;">
                        <label style="display:block; font-size:12px; color:#64748b; font-weight:600; text-transform:uppercase; margin-bottom:8px;">Select Site to Manage</label>
                        <select id="skyhshoso-wp-site-selector" class="skyhshoso-form-input" style="width:100%; padding:10px;">
                            <option value="">Scanning server for WordPress sites...</option>
                            </select>
                    </div>
                    <button id="skyhshoso-wp-sso-btn" class="skyhshoso-button skyhshoso-button-primary" disabled style="opacity:0.5; cursor:not-allowed;">
                        <?php esc_html_e('Log into WP Admin', 'skyhs-hosting-solution'); ?>
                    </button>
                </div>
            </div>

        </div>

        <div id="skyhshoso-pass-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.6); z-index:9999; align-items:center; justify-content:center;">
            <div style="background:#fff; padding:24px; border-radius:12px; width:100%; max-width:400px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
                <h3 style="margin-top:0; font-size:18px; color:#0f172a;">Reset cPanel Password</h3>
                <p style="font-size:13px; color:#64748b;">Enter a new secure password for user <strong><?php echo esc_html($whm_user); ?></strong>.</p>
                
                <input type="password" id="skyhs-new-pass" placeholder="New Password" style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:6px; margin-bottom:16px; box-sizing:border-box;">
                
                <div style="display:flex; justify-content:flex-end; gap:12px;">
                    <button id="skyhshoso-cancel-pass" class="skyhshoso-button skyhshoso-button-secondary">Cancel</button>
                    <button id="skyhshoso-save-pass" class="skyhshoso-button skyhshoso-button-primary" data-hosting-id="<?php echo esc_attr($hosting_id); ?>">Save Password</button>
                </div>
            </div>
        </div>

        <style>
            .skyhshoso-switch { position: relative; display: inline-block; width: 44px; height: 24px; }
            .skyhshoso-switch input { opacity: 0; width: 0; height: 0; }
            .skyhshoso-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e1; transition: .4s; }
            .skyhshoso-slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .4s; }
            input:checked + .skyhshoso-slider { background-color: #2563eb; }
            input:checked + .skyhshoso-slider:before { transform: translateX(20px); }
            .skyhshoso-slider.round { border-radius: 24px; }
            .skyhshoso-slider.round:before { border-radius: 50%; }
        </style>
        <?php
    }
    
    /**
     * Render global dashboard warning notices for suspended (on-hold) subscriptions.
     */
    private static function render_dashboard_warning_notices() {
        $current_user_id = get_current_user_id();
        if ( ! $current_user_id ) {
            return;
        }

        $rows = SkyHSHOSO_Subscription_DB::query( array(
            'user_id'             => $current_user_id,
            'subscription_status' => 'on-hold',
        ) );

        if ( empty( $rows ) ) {
            return;
        }

        // Print styles once
        ?>
        <style>
            .skyhshoso-global-alerts-container {
                margin-bottom: 24px;
                display: flex;
                flex-direction: column;
                gap: 16px;
            }
            .skyhshoso-global-alert {
                display: flex;
                align-items: center;
                justify-content: space-between;
                flex-wrap: wrap;
                background: linear-gradient(135deg, #fff5f5 0%, #ffe3e3 100%);
                border-left: 5px solid #fa5252;
                border-radius: 8px;
                padding: 16px 20px;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
                animation: alert-slide-down 0.4s ease-out;
                gap: 16px;
            }
            @keyframes alert-slide-down {
                from { opacity: 0; transform: translateY(-10px); }
                to { opacity: 1; transform: translateY(0); }
            }
            .skyhshoso-global-alert-content {
                display: flex;
                align-items: center;
                gap: 14px;
                flex: 1;
                min-width: 280px;
            }
            .skyhshoso-global-alert-icon {
                color: #fa5252;
                flex-shrink: 0;
                width: 24px;
                height: 24px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .skyhshoso-global-alert-text {
                color: #c92a2a;
                font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                font-size: 14px;
                line-height: 1.5;
                margin: 0;
            }
            .skyhshoso-global-alert-title {
                font-weight: 700;
                color: #b01a1a;
                margin-bottom: 4px;
            }
            .skyhshoso-global-alert-btn {
                background-color: #fa5252;
                color: #ffffff !important;
                text-decoration: none !important;
                padding: 10px 20px;
                font-size: 14px;
                font-weight: 600;
                border-radius: 6px;
                border: none;
                box-shadow: 0 2px 4px rgba(250, 82, 82, 0.2);
                transition: all 0.2s ease-in-out;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                white-space: nowrap;
            }
            .skyhshoso-global-alert-btn:hover {
                background-color: #e03131;
                box-shadow: 0 4px 12px rgba(250, 82, 82, 0.3);
                transform: translateY(-1px);
            }
            .skyhshoso-global-alert-btn:active {
                transform: translateY(0);
            }
        </style>
        <div class="skyhshoso-global-alerts-container">
            <?php
            foreach ( $rows as $row ) {
                $subscription = new SkyHSHOSO_Subscription( $row );
                $product      = wc_get_product( $row->variation_id ?: $row->product_id );
                if ( ! $product ) {
                    continue;
                }
                
                $product_name = $product->get_name();
                if ( strpos( $product_name, ' - ' ) !== false ) {
                    list( $parent_part, $variation_part ) = explode( ' - ', $product_name, 2 );
                    $variation_part = str_replace( '-', ' ', $variation_part );
                    $variation_part = ucwords( $variation_part );
                    $variation_part = str_ireplace( 'wordpress', 'WordPress', $variation_part );
                    $parent_part = str_replace( '-', ' ', $parent_part );
                    $parent_part = ucwords( $parent_part );
                    $parent_part = str_ireplace( 'wordpress', 'WordPress', $parent_part );
                    $product_name = $parent_part . ' - ' . $variation_part;
                } else {
                    $product_name = str_replace( '-', ' ', $product_name );
                    $product_name = ucwords( $product_name );
                    $product_name = str_ireplace( 'wordpress', 'WordPress', $product_name );
                }

                $terminate_meta = SkyHSHOSO_Subscription_DB::get_meta( $subscription->get_id(), '_skyhshoso_terminate_after', true );
                if ( ! $terminate_meta ) {
                    continue;
                }
                $days_left = max( 0, ceil( ( strtotime( $terminate_meta ) - time() ) / DAY_IN_SECONDS ) );
                $renew_url = add_query_arg( 'add-to-cart', $product->get_id(), wc_get_checkout_url() );
                ?>
                <div class="skyhshoso-global-alert">
                    <div class="skyhshoso-global-alert-content">
                        <div class="skyhshoso-global-alert-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:24px; height:24px;">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                            </svg>
                        </div>
                        <div class="skyhshoso-global-alert-text">
                            <div class="skyhshoso-global-alert-title"><?php esc_html_e( 'Suspension Warning: Action Required', 'skyhs-hosting-solution' ); ?></div>
                            <div>
                                <?php
                                echo wp_kses_post(
                                    sprintf(
                                        /* translators: 1: Product name, 2: Number of days left. */
                                        _n(
                                            'Your subscription for <strong>%1$s</strong> is currently suspended (on-hold). You have <strong>%2$d day</strong> left to renew, after which your hosting account and all associated data will be permanently deleted.',
                                            'Your subscription for <strong>%1$s</strong> is currently suspended (on-hold). You have <strong>%2$d days</strong> left to renew, after which your hosting account and all associated data will be permanently deleted.',
                                            $days_left,
                                            'skyhs-hosting-solution'
                                        ),
                                        esc_html( $product_name ),
                                        $days_left
                                    )
                                );
                                ?>
                            </div>
                        </div>
                    </div>
                    <a href="<?php echo esc_url( $renew_url ); ?>" class="skyhshoso-global-alert-btn">
                        <?php esc_html_e( 'Renew Immediately', 'skyhs-hosting-solution' ); ?>
                    </a>
                </div>
                <?php
            }
            ?>
        </div>
        <?php
    }

    /**
     * Render client-facing Subscriptions tab with cancel/reactivate/view order.
     */
    private static function render_subscriptions_tab() {
        $current_user_id = get_current_user_id();

        // Handle cancel / reactivate actions.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $sub_action = isset( $_GET['sub_action'] ) ? sanitize_text_field( wp_unslash( $_GET['sub_action'] ) ) : '';
        $sub_id     = isset( $_GET['sub_id'] ) ? absint( wp_unslash( $_GET['sub_id'] ) ) : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ( $sub_action && $sub_id && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'skyhshoso_sub_action_' . $sub_id ) ) {
            $sub = skyhshoso_get_subscription( $sub_id );
            if ( $sub && $sub->get_customer_id() === $current_user_id ) {
                if ( $sub_action === 'cancel' && in_array( $sub->get_status(), array( 'active' ), true ) ) {
                    // Don't cancel immediately — mark as pending-cancel so it stays active until period ends.
                    $sub->update_status( 'pending-cancel', __( 'Cancellation scheduled by customer. Service continues until end of billing period.', 'skyhs-hosting-solution' ) );
                } elseif ( $sub_action === 'undo_cancel' && $sub->get_status() === 'pending-cancel' ) {
                    $sub->update_status( 'active', __( 'Cancellation undone by customer.', 'skyhs-hosting-solution' ) );
                }
            }
        }

        $rows = SkyHSHOSO_Subscription_DB::query( array( 'user_id' => $current_user_id ) );
        ?>
        </div>

        <div class="skyhshoso-section-content">
            <?php if ( empty( $rows ) ) : ?>
                <div class="skyhshoso-empty-message">
                    <?php esc_html_e('You don\'t have any subscriptions yet.', 'skyhs-hosting-solution'); ?>
                </div>
            <?php else : ?>
                <?php foreach ( $rows as $row ) :
                    $subscription = new SkyHSHOSO_Subscription( $row );
                    $status       = $subscription->get_status();
                    $product      = wc_get_product( $row->variation_id ?: $row->product_id );
                    $product_name = '#' . $row->product_id;
                    if ( $product ) {
                        $product_name = $product->get_name();
                        if ( strpos( $product_name, ' - ' ) !== false ) {
                            list( $parent_part, $variation_part ) = explode( ' - ', $product_name, 2 );
                            $variation_part = str_replace( '-', ' ', $variation_part );
                            $variation_part = ucwords( $variation_part );
                            $variation_part = str_ireplace( 'wordpress', 'WordPress', $variation_part );

                            $parent_part = str_replace( '-', ' ', $parent_part );
                            $parent_part = ucwords( $parent_part );
                            $parent_part = str_ireplace( 'wordpress', 'WordPress', $parent_part );

                            $product_name = $parent_part . ' - ' . $variation_part;
                        } else {
                            $product_name = str_replace( '-', ' ', $product_name );
                            $product_name = ucwords( $product_name );
                            $product_name = str_ireplace( 'wordpress', 'WordPress', $product_name );
                        }
                    }
                    $next         = $subscription->get_date( 'next_payment' );
                    $period       = $subscription->get_billing_period();
                    $interval     = $subscription->get_billing_interval();
                    $period_label = ( 1 === $interval ) ? $period : $interval . ' ' . $period . 's';

                    // Check if inline switching dropdown should be shown.
                    $show_switch_dropdown = false;
                    $switch_variations    = array();
                    $allow_switching      = get_option( 'skyhshoso_allow_switching', 'no' );


                    if ( 'no' !== $allow_switching && $product && 'active' === $status ) {
                        $current_product_id   = $row->variation_id ? (int) $row->variation_id : (int) $row->product_id;
                        $current_product      = wc_get_product( $current_product_id );
                        $parent_product_id    = (int) $row->product_id;
                        $parent_product       = wc_get_product( $parent_product_id );
                        $switch_candidates    = array();

                        if ( $parent_product && $parent_product->is_type( 'variable' ) && false !== strpos( $allow_switching, 'variable' ) ) {
                            $switch_candidates = $parent_product->get_children();

                        } elseif ( false !== strpos( $allow_switching, 'grouped' ) && $current_product ) {
                            $parent_products = class_exists( 'SkyHSHOSO_Subscriptions_Product' ) ? SkyHSHOSO_Subscriptions_Product::get_visible_grouped_parent_product_ids( $current_product ) : array();

                            if ( ! empty( $parent_products ) ) {
                                $parent_grouped_id = reset( $parent_products );
                                $parent_grouped    = wc_get_product( $parent_grouped_id );
                                if ( $parent_grouped && $parent_grouped->is_type( 'grouped' ) ) {
                                    $switch_candidates = $parent_grouped->get_children();

                                }
                            }
                        }

                        if ( ! empty( $switch_candidates ) ) {
                            foreach ( $switch_candidates as $child_id ) {
                                $child = wc_get_product( $child_id );
                                if ( ! $child || ! $child->is_purchasable() || ! $child->is_in_stock() ) {

                                    continue;
                                }

                                $v_price    = $child->get_price();
                                $v_period   = '';
                                $v_interval = 1;
                                if ( class_exists( 'SkyHSHOSO_Subscriptions_Product' ) && SkyHSHOSO_Subscriptions_Product::is_subscription( $child ) ) {
                                    $v_period   = SkyHSHOSO_Subscriptions_Product::get_period( $child );
                                    $v_interval = (int) SkyHSHOSO_Subscriptions_Product::get_interval( $child );
                                }
                                $v_period_label = '';
                                if ( $v_period ) {
                                    $v_period_label = ( 1 === $v_interval ) ? $v_period : $v_interval . ' ' . $v_period . 's';
                                }

                                $child_name = $child->get_name();
                                if ( strpos( $child_name, ' - ' ) !== false ) {
                                    list( $parent_part, $variation_part ) = explode( ' - ', $child_name, 2 );
                                    $variation_part = str_replace( '-', ' ', $variation_part );
                                    $variation_part = ucwords( $variation_part );
                                    $variation_part = str_ireplace( 'wordpress', 'WordPress', $variation_part );

                                    $parent_part = str_replace( '-', ' ', $parent_part );
                                    $parent_part = ucwords( $parent_part );
                                    $parent_part = str_ireplace( 'wordpress', 'WordPress', $parent_part );

                                    $child_name = $parent_part . ' - ' . $variation_part;
                                } else {
                                    $child_name = str_replace( '-', ' ', $child_name );
                                    $child_name = ucwords( $child_name );
                                    $child_name = str_ireplace( 'wordpress', 'WordPress', $child_name );
                                }

                                $switch_variations[] = array(
                                    'id'           => $child_id,
                                    'name'         => $child_name,
                                    'price_html'   => wp_strip_all_tags( wc_price( $v_price ) ),
                                    'period_label' => $v_period_label,
                                    'is_current'   => ( $child_id === $current_product_id ),
                                );
                            }

                            // Show dropdown only if there are 2+ variations.
                            if ( count( $switch_variations ) >= 2 ) {
                                $show_switch_dropdown = true;
                            }

                        }
                    }

                    // Human-readable status.
                    if ( $status === 'pending-cancel' ) {
                        $display_status = __( 'Active', 'skyhs-hosting-solution' );
                    } else {
                        $display_status = ucwords( str_replace( '-', ' ', $status ) );
                    }
                ?>
                    <div class="skyhshoso-card skyhshoso-subscription-card" style="margin-bottom:16px; flex-direction: column !important; display: flex !important;">
                        <div class="skyhshoso-subscription-header">
                            <div class="skyhshoso-card-text">
                                <p class="skyhshoso-card-title"><?php echo esc_html( $product_name ); ?></p>
                                <p class="skyhshoso-card-description">
                                    <?php echo wp_kses_post( wc_price( $subscription->get_total() ) ); ?>
                                    <?php echo ' / ' . esc_html( $period_label ); ?><br>
                                    <?php esc_html_e( 'Status:', 'skyhs-hosting-solution' ); ?>
                                    <strong><?php echo esc_html( $display_status ); ?></strong><br>
                                    <?php if ( $status === 'pending-cancel' && $next ) : ?>
                                        <span style="color:#dc3545;">
                                            <?php esc_html_e( 'Cancels on:', 'skyhs-hosting-solution' ); ?>
                                            <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $next ) ) ); ?>
                                        </span>
                                    <?php elseif ( $next ) : ?>
                                        <?php esc_html_e( 'Next Payment:', 'skyhs-hosting-solution' ); ?>
                                        <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $next ) ) ); ?>
                                    <?php endif; ?>
                                    <?php if ( $status === 'on-hold' ) : ?>
                                        <?php
                                        $terminate_meta = SkyHSHOSO_Subscription_DB::get_meta( $subscription->get_id(), '_skyhshoso_terminate_after', true );
                                        if ( $terminate_meta ) :
                                            $days_left = max( 0, ceil( ( strtotime( $terminate_meta ) - time() ) / DAY_IN_SECONDS ) );
                                            ?>
                                            <br>
                                            <span style="color:#dc3545; font-weight:600;">
                                                <?php echo esc_html( sprintf( _n( '%d day left to renew — data will be deleted after.', '%d days left to renew — data will be deleted after.', $days_left, 'skyhs-hosting-solution' ), $days_left ) ); ?>
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </p>

                                <?php
                                // Transaction log — show all invoices (related orders) for this subscription.
                                $sub_payments = $subscription->get_related_orders();
                                if ( ! empty( $sub_payments ) ) {
                                    krsort( $sub_payments );
                                }
                                if ( ! empty( $sub_payments ) ) :
                                ?>
                                <div style="margin-top:12px;padding-top:12px;border-top:1px solid #e5e5e5;">
                                    <p style="font-size:12px;font-weight:600;margin:0 0 6px 0;color:#646970;"><?php esc_html_e( 'Invoices', 'skyhs-hosting-solution' ); ?></p>
                                    <table style="width:100%;font-size:12px;border-collapse:collapse;">
                                        <thead>
                                            <tr style="color:#646970;">
                                                <th style="text-align:left;padding:3px 6px;border-bottom:1px solid #e5e5e5;"><?php esc_html_e( 'Order ID', 'skyhs-hosting-solution' ); ?></th>
                                                <th style="text-align:left;padding:3px 6px;border-bottom:1px solid #e5e5e5;"><?php esc_html_e( 'Date', 'skyhs-hosting-solution' ); ?></th>
                                                <th style="text-align:right;padding:3px 6px;border-bottom:1px solid #e5e5e5;"><?php esc_html_e( 'Amount', 'skyhs-hosting-solution' ); ?></th>
                                                <th style="text-align:center;padding:3px 6px;border-bottom:1px solid #e5e5e5;"><?php esc_html_e( 'Status', 'skyhs-hosting-solution' ); ?></th>
                                                <th style="text-align:center;padding:3px 6px;border-bottom:1px solid #e5e5e5;"><?php esc_html_e( 'Invoice', 'skyhs-hosting-solution' ); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ( $sub_payments as $pay_order ) :
                                                $pay_status = $pay_order->get_status();
                                                $pay_color  = 'completed' === $pay_status ? '#007017' : ( 'failed' === $pay_status ? '#d63638' : '#646970' );
                                            ?>
                                            <tr>
                                                <td style="padding:3px 6px;border-bottom:1px solid #f0f0f1;font-weight:600;">#<?php echo esc_html( $pay_order->get_id() ); ?></td>
                                                <td style="padding:3px 6px;border-bottom:1px solid #f0f0f1;"><?php echo esc_html( date_i18n( get_option( 'date_format' ), $pay_order->get_date_created() ? $pay_order->get_date_created()->getTimestamp() : time() ) ); ?></td>
                                                <td style="text-align:right;padding:3px 6px;border-bottom:1px solid #f0f0f1;"><?php echo wp_kses_post( wc_price( $pay_order->get_total(), array( 'currency' => $pay_order->get_currency() ) ) ); ?></td>
                                                <td style="text-align:center;padding:3px 6px;border-bottom:1px solid #f0f0f1;color:<?php echo esc_attr( $pay_color ); ?>;"><?php echo esc_html( ucwords( $pay_status ) ); ?></td>
                                                <td style="text-align:center;padding:3px 6px;border-bottom:1px solid #f0f0f1;">
                                                    <a href="<?php echo esc_url( SkyHSHOSO_Invoice::get_invoice_url( $pay_order ) ); ?>" target="_blank" style="font-size:12px;color:#2271b1;text-decoration:none;"><?php esc_html_e( 'View', 'skyhs-hosting-solution' ); ?></a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php if ( $show_switch_dropdown && ! empty( $switch_variations ) ) : ?>
                                <div class="skyhshoso-switch-container" data-subscription-id="<?php echo esc_attr( $subscription->get_id() ); ?>">
                                    <label class="skyhshoso-switch-label" for="skyhshoso-switch-select-<?php echo esc_attr( $subscription->get_id() ); ?>">
                                        <?php esc_html_e( 'Change Plan', 'skyhs-hosting-solution' ); ?>
                                    </label>
                                    <div class="skyhshoso-switch-row">
                                        <select id="skyhshoso-switch-select-<?php echo esc_attr( $subscription->get_id() ); ?>" class="skyhshoso-switch-select">
                                            <?php foreach ( $switch_variations as $sv ) : ?>
                                                <option
                                                    value="<?php echo esc_attr( $sv['id'] ); ?>"
                                                    <?php selected( $sv['is_current'] ); ?>
                                                    <?php if ( $sv['is_current'] ) : ?>data-current="1"<?php endif; ?>
                                                >
                                                    <?php echo esc_html( $sv['name'] ); ?> — <?php echo esc_html( $sv['price_html'] ); ?><?php echo $sv['period_label'] ? ' / ' . esc_html( $sv['period_label'] ) : ''; ?><?php echo $sv['is_current'] ? ' (' . esc_html__( 'Current', 'skyhs-hosting-solution' ) . ')' : ''; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="skyhshoso-switch-btn" style="display:none;">
                                            <span class="skyhshoso-switch-btn-text"><?php esc_html_e( 'Switch Plan', 'skyhs-hosting-solution' ); ?></span>
                                            <span class="skyhshoso-switch-spinner" style="display:none;"></span>
                                        </button>
                                    </div>
                                    <div class="skyhshoso-switch-message" style="display:none;"></div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="skyhshoso-subscription-footer">
                            <?php if ( $status === 'active' ) : ?>
                                <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'tab' => 'subscriptions', 'sub_action' => 'cancel', 'sub_id' => $subscription->get_id() ), self::get_base_url() ), 'skyhshoso_sub_action_' . $subscription->get_id() ) ); ?>" class="skyhshoso-card-button skyhshoso-btn-danger" onclick="return confirm('<?php esc_attr_e( 'Your subscription will remain active until the end of the current billing period. Continue?', 'skyhs-hosting-solution' ); ?>');">
                                    <span><?php esc_html_e( 'Cancel', 'skyhs-hosting-solution' ); ?></span>
                                </a>
                                <?php if ( skyhshoso_can_user_renew_early( $subscription ) ) : ?>
                                    <a href="<?php echo esc_url( skyhshoso_get_early_renewal_url( $subscription ) ); ?>" class="skyhshoso-card-button">
                                        <span><?php esc_html_e( 'Renew Early', 'skyhs-hosting-solution' ); ?></span>
                                    </a>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ( $status === 'pending-cancel' ) : ?>
                                <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'tab' => 'subscriptions', 'sub_action' => 'undo_cancel', 'sub_id' => $subscription->get_id() ), self::get_base_url() ), 'skyhshoso_sub_action_' . $subscription->get_id() ) ); ?>" class="skyhshoso-card-button skyhshoso-btn-danger">
                                    <span><?php esc_html_e( 'Undo Cancel', 'skyhs-hosting-solution' ); ?></span>
                                </a>
                            <?php endif; ?>
                            <?php if ( $status === 'on-hold' && $product ) : ?>
                                <a href="<?php echo esc_url( add_query_arg( 'add-to-cart', $product->get_id(), wc_get_checkout_url() ) ); ?>" class="skyhshoso-card-button">
                                    <span><?php esc_html_e( 'Renew', 'skyhs-hosting-solution' ); ?></span>
                                </a>
                            <?php endif; ?>
                            <a href="<?php echo esc_url( $subscription->get_view_order_url() ); ?>" class="skyhshoso-card-button">
                                <span><?php esc_html_e( 'View Order', 'skyhs-hosting-solution' ); ?></span>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Show list of available hosting products to purchase
     */
    private static function render_new_hosting_products_list() {
        // Query WooCommerce products with custom meta _skyhshoso_product_type = hosting
        // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- This query only pulls WooCommerce products once to build the hosting products list, acceptable for front-end usage.
        $args = array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => array(
                array(
                    'key'   => '_skyhshoso_product_type',
                    'value' => array('skyhshoso_hosting', 'hosting'),
                    'compare' => 'IN',
                ),
            ),
            'tax_query'      => array(
                array(
                    'taxonomy' => 'product_visibility',
                    'field'    => 'name',
                    'terms'    => 'exclude-from-catalog',
                    'operator' => 'NOT IN',
                ),
            ),
        );
 
        $products = new WP_Query( $args );
        // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query

        ?>
        <div class="skyhshoso-hosting-header">
            <p class="skyhshoso-hosting-title"><?php esc_html_e('Choose Hosting Plan', 'skyhs-hosting-solution'); ?></p>
            <?php if (is_user_logged_in()): ?>
            <a href="<?php echo esc_url( add_query_arg( 'tab', 'skyhshoso_hosting', self::get_base_url() ) ); ?>" class="skyhshoso-card-button"><span><?php esc_html_e('Back to Hosting', 'skyhs-hosting-solution'); ?></span></a>
            <?php endif; ?>
        </div>

        <div class="skyhshoso-section-content">
            <?php if ( $products->have_posts() ) : ?>
                <?php while ( $products->have_posts() ) : $products->the_post();
                    $product = wc_get_product( get_the_ID() );
                    if ( ! $product ) {
                        continue;
                    }
                    $list_period_label = '';
                    ?>
                    <div class="skyhshoso-card" style="margin-bottom:20px;">
                        <div class="skyhshoso-card-content">
                            <div class="skyhshoso-card-text">
                                <p class="skyhshoso-card-title"><?php the_title(); ?></p>
                                <p class="skyhshoso-card-description"><?php echo wp_kses_post( $product->get_price_html() ); ?><?php echo esc_html( $list_period_label ); ?></p>
                            </div>
                            <a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'skyhshoso_hosting', 'new_hosting' => 1, 'product_id' => $product->get_id() ), self::get_base_url() ) ); ?>" class="skyhshoso-card-button"><span><?php esc_html_e('Choose', 'skyhs-hosting-solution'); ?></span></a>
                        </div>
                    </div>
                <?php endwhile; wp_reset_postdata(); ?>
            <?php else : ?>
                <div class="skyhshoso-empty-message">
                    <?php esc_html_e('No hosting products available at the moment.', 'skyhs-hosting-solution'); ?><br>
                    <a href="<?php echo esc_url( add_query_arg( 'tab', 'skyhshoso_hosting', self::get_base_url() ) ); ?>" class="skyhshoso-card-button" style="display:inline-block; margin-top:10px;">
                        <span><?php esc_html_e('Back to Hosting', 'skyhs-hosting-solution'); ?></span>
                    </a>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Show details (features & variations) for a single hosting product
     *
     * @param int $product_id
     */
    private static function render_new_hosting_product_detail( $product_id ) {
        $product = wc_get_product( $product_id );

        // Check if product is valid and has hosting product type
        $product_type = get_post_meta( $product_id, '_skyhshoso_product_type', true );
        
        if ( ! $product || ! in_array( $product_type, array( 'skyhshoso_hosting', 'hosting' ), true ) ) {
            echo '<div class="skyhshoso-empty-message">' . esc_html__( 'This product is not available for hosting purchase. Only WooCommerce Subscription products with product type "Hosting" can be purchased here.', 'skyhs-hosting-solution' ) . '</div>';
            return;
        }

        ?>
        <div class="skyhshoso-hosting-header">
            <p class="skyhshoso-hosting-title"><?php echo esc_html( $product->get_name() ); ?></p>
            <a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'skyhshoso_hosting', 'new_hosting' => 1 ), self::get_base_url() ) ); ?>" class="skyhshoso-card-button"><span><?php esc_html_e('Back', 'skyhs-hosting-solution'); ?> <?php esc_html_e('to Hosting', 'skyhs-hosting-solution'); ?></span></a>
        </div>

        <div class="skyhshoso-section-content" style="padding:0!important;margin:0!important;">
            <?php echo SkyHSHOSO_Product_Shortcode::render_shortcode( array( 'id' => $product_id, 'show_title' => 'false' ) ); ?>
        </div>
        <?php
    }
    
    /**
     * Render the domains tab content
     */
    public static function render_domains_tab() {
        // Get current user ID
        $current_user_id = get_current_user_id();
        
        // Check if we're viewing a specific domain
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $domain_id = isset($_GET['domain_id']) ? intval(wp_unslash($_GET['domain_id'])) : 0;
        
        // Check if we're showing the domain checker
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $show_domain_checker = isset($_GET['new_domain']) && sanitize_text_field( wp_unslash($_GET['new_domain']) ) === '1';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $show_domain_transfer = isset($_GET['transfer_domain']) && sanitize_text_field( wp_unslash($_GET['transfer_domain']) ) === '1';
        
        if ($domain_id) {
            self::render_domain_detail($domain_id);
            return;
        }
        
        if ($show_domain_transfer) {
            if ( SkyHSHOSO_Settings::is_domain_registration_disabled() ) {
                // Domain transfers disabled — fall through to the domains list
            } else {
                self::render_domain_transfer();
                return;
            }
        }
        
        if ($show_domain_checker) {
            if ( SkyHSHOSO_Settings::is_domain_registration_disabled() ) {
                // Domain registration disabled — fall through to the domains list
            } else {
                self::render_domain_checker();
                return;
            }
        }
        
        // Search term
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $search_term = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';
        
        // Get the domains class instance
        $domains_handler = new SkyHSHOSO_Account_Domains();
        $domains_grouped = $domains_handler->get_all_accessible_domains();
        
        ?>
        <div class="skyhshoso-hosting-header" style="justify-content: flex-end; margin-bottom: 0;">
            <div class="skyhshoso-hosting-header-actions">
                <?php if ( ! SkyHSHOSO_Settings::is_domain_registration_disabled() ) : ?>
                <a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'domains', 'new_domain' => 1 ), self::get_base_url() ) ); ?>" class="skyhshoso-new-hosting-btn">
                    <span class="truncate"><?php esc_html_e('New Domain', 'skyhs-hosting-solution'); ?></span>
                </a>
                <a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'domains', 'transfer_domain' => 1 ), self::get_base_url() ) ); ?>" class="skyhshoso-new-hosting-btn skyhshoso-transfer-btn">
                    <span class="truncate"><?php esc_html_e('Transfer Domain', 'skyhs-hosting-solution'); ?></span>
                </a>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="skyhshoso-search-container">
            <div class="skyhshoso-search-form">
                <div class="skyhshoso-search-input-wrapper">
                    <div class="skyhshoso-search-icon-wrapper">
                        <svg class="skyhshoso-search-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>
                    <input 
                        type="text" 
                        id="skyhshoso-domain-search" 
                        placeholder="<?php esc_attr_e('Search domain', 'skyhs-hosting-solution'); ?>" 
                        class="skyhshoso-search-input" 
                        value="<?php echo esc_attr($search_term); ?>"
                    >
                    <button type="button" class="skyhshoso-search-clear" aria-label="Clear search">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>
        
        <div class="skyhshoso-table-container" id="skyhshoso-domain-table-container">
            <div class="skyhshoso-table-wrapper" id="skyhshoso-domain-table-wrapper">
                <?php
                $dpaged = isset( $_GET['dpaged'] ) ? max( 1, intval( $_GET['dpaged'] ) ) : 1;

                $flat_domains = array();
                if ( current_user_can( 'administrator' ) && isset( $domains_grouped['all'] ) && ! empty( $domains_grouped['all'] ) ) {
                    $flat_domains = $domains_grouped['all'];
                } else {
                    if ( isset( $domains_grouped['your'] ) && ! empty( $domains_grouped['your'] ) ) {
                        $flat_domains = $domains_grouped['your'];
                    }
                    foreach ( $domains_grouped as $key => $group ) {
                        if ( $key !== 'your' && isset( $group['domains'] ) && ! empty( $group['domains'] ) ) {
                            $flat_domains = array_merge( $flat_domains, $group['domains'] );
                        }
                    }
                }

                if ( ! empty( $search_term ) ) {
                    $flat_domains = array_filter( $flat_domains, function ( $d ) use ( $search_term ) {
                        return stripos( $d['title'], $search_term ) !== false;
                    } );
                    $flat_domains = array_values( $flat_domains );
                }

                $dper_page    = 10;
                $dtotal       = count( $flat_domains );
                $dtotal_pages = max( 1, ceil( $dtotal / $dper_page ) );
                $d_offset     = ( $dpaged - 1 ) * $dper_page;
                $page_domains = array_slice( $flat_domains, $d_offset, $dper_page );

                if ( ! empty( $page_domains ) ) {
                    self::render_domains_table( $page_domains, __( 'Domains', 'skyhs-hosting-solution' ) );
                } else {
                    ?>
                    <div class="skyhshoso-empty-message">
                        <?php esc_html_e( 'No domains found. Click "New Domain" to register a domain.', 'skyhs-hosting-solution' ); ?>
                    </div>
                    <?php
                }
                ?>
            </div>
            <div id="skyhshoso-domain-pagination" class="skyhshoso-pagination-container" data-total-pages="<?php echo esc_attr( $dtotal_pages ); ?>" data-current-page="<?php echo esc_attr( $dpaged ); ?>" data-base-url="<?php echo esc_url( self::get_base_url() ); ?>"></div>
        </div>

        <div id="skyhshoso-domain-no-results" style="display: none;" class="skyhshoso-empty-message">
            <?php esc_html_e( 'No matching domains found.', 'skyhs-hosting-solution' ); ?>
        </div>
        <?php
    }
    
    /**
     * Render the domain checker
     */
    public static function render_domain_checker() {
        ?>
        <div class="skyhshoso-hosting-header">
            <p class="skyhshoso-hosting-title"><?php esc_html_e('Register New Domain', 'skyhs-hosting-solution'); ?></p>
            <?php if (is_user_logged_in()): ?>
            <a href="<?php echo esc_url( add_query_arg( 'tab', 'domains', self::get_base_url() ) ); ?>" class="skyhshoso-card-button">
                <span><?php esc_html_e('Back to Domains', 'skyhs-hosting-solution'); ?></span>
            </a>
            <?php endif; ?>
        </div>
        
        <div class="skyhshoso-section-content">
            <div class="skyhshoso-domain-checker-wrapper">
                <?php echo do_shortcode('[skyhshoso_domain_checker]'); ?>
            </div>
        </div>
        
        <?php
    }

    public static function render_domain_transfer() {
        ?>
        <div class="skyhshoso-hosting-header">
            <p class="skyhshoso-hosting-title"><?php esc_html_e('Transfer Domain', 'skyhs-hosting-solution'); ?></p>
            <?php if (is_user_logged_in()): ?>
            <a href="<?php echo esc_url( add_query_arg( 'tab', 'domains', self::get_base_url() ) ); ?>" class="skyhshoso-card-button">
                <span><?php esc_html_e('Back to Domains', 'skyhs-hosting-solution'); ?></span>
            </a>
            <?php endif; ?>
        </div>

        <div class="skyhshoso-section-content">
            <div class="skyhshoso-domain-checker-wrapper">
                <?php echo do_shortcode('[skyhshoso_domain_transfer_checker]'); ?>
            </div>
        </div>

        <?php
    }

    /**
     * Render the domain detail view
     * * @param int $domain_id The domain ID to display
     */
    public static function render_domain_detail($domain_id) {
        // Check if this is a DNS management request
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $manage_dns = isset($_GET['dns']) && sanitize_text_field( wp_unslash($_GET['dns']) ) === 'manage';
        
        // Get the domain post
        $domain = get_post($domain_id);
        
        if (!$domain || $domain->post_type !== 'skyhshoso_domain') {
            ?>
            <div class="skyhshoso-hosting-header">
                <p class="skyhshoso-hosting-title"><?php esc_html_e('Domain Details', 'skyhs-hosting-solution'); ?></p>
            </div>
            <div class="skyhshoso-section-content">
                <p><?php esc_html_e('Domain not found.', 'skyhs-hosting-solution'); ?></p>
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'domains', self::get_base_url() ) ); ?>" class="skyhshoso-card-button">
                    <span><?php esc_html_e('Back to Domains', 'skyhs-hosting-solution'); ?></span>
                </a>
            </div>
            <?php
            return;
        }
        
        // Check if user has permission to access this domain
        $current_user_id = get_current_user_id();
        $domain_author_id = $domain->post_author;
        $invited_by = get_user_meta($current_user_id, 'skyhshoso_invited_by', true);
        $invited_by = is_array($invited_by) ? $invited_by : array();
        
        if ($current_user_id != $domain_author_id 
            && !current_user_can('administrator')
            && !in_array($domain_author_id, $invited_by)) {
            ?>
            <div class="skyhshoso-hosting-header">
                <p class="skyhshoso-hosting-title"><?php esc_html_e('Domain Details', 'skyhs-hosting-solution'); ?></p>
            </div>
            <div class="skyhshoso-section-content">
                <p><?php esc_html_e('You do not have permission to view this domain information.', 'skyhs-hosting-solution'); ?></p>
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'domains', self::get_base_url() ) ); ?>" class="skyhshoso-card-button">
                    <span><?php esc_html_e('Back to Domains', 'skyhs-hosting-solution'); ?></span>
                </a>
            </div>
            <?php
            return;
        }
        
        // Get subscription status
        $subscription_id = get_post_meta($domain_id, 'skyhshoso_subscription_id', true);
        $subscription_status = '';
        $next_payment_date = 'N/A';
        
        if (!empty($subscription_id)) {
            $subscription = skyhshoso_get_subscription($subscription_id);
            if ($subscription) {
                $status = $subscription->get_status();
                $subscription_status = ucwords(str_replace('-', ' ', $status));
                
                $next_payment = $subscription->get_date('next_payment');
                $next_payment_date = $next_payment ? gmdate('d-m-Y', strtotime($next_payment)) : 'N/A';
            }
        }
        
        // Check if DNS management is available
        $can_manage_dns = in_array(strtolower($subscription_status), array('active', 'pending cancel'));
        
        // If this is a DNS management request and the domain can be managed
        if ($manage_dns && $can_manage_dns) {
            self::render_dns_management($domain);
            return;
        }
        
        ?>
        <div class="skyhshoso-hosting-header" style="justify-content: flex-end;">
            <a href="<?php echo esc_url( add_query_arg( 'tab', 'domains', self::get_base_url() ) ); ?>" class="skyhshoso-card-button">
                <span><?php esc_html_e('Back to Domains', 'skyhs-hosting-solution'); ?></span>
            </a>
        </div>
        
        <div class="skyhshoso-section-content">
            <div class="skyhshoso-detail-card">
                <h2 class="skyhshoso-detail-title"><?php esc_html_e('Domain Information', 'skyhs-hosting-solution'); ?></h2>
                <div class="skyhshoso-detail-row">
                    <span class="skyhshoso-detail-label"><?php esc_html_e('Domain:', 'skyhs-hosting-solution'); ?></span>
                    <span class="skyhshoso-detail-value"><?php echo esc_html($domain->post_title); ?></span>
                </div>
                <div class="skyhshoso-detail-row">
                    <span class="skyhshoso-detail-label"><?php esc_html_e('Status:', 'skyhs-hosting-solution'); ?></span>
                    <span class="skyhshoso-detail-value"><?php echo esc_html($subscription_status); ?></span>
                </div>
                <?php if (!empty($next_payment_date) && $next_payment_date !== 'N/A') : ?>
                <div class="skyhshoso-detail-row">
                    <span class="skyhshoso-detail-label"><?php esc_html_e('Next Payment:', 'skyhs-hosting-solution'); ?></span>
                    <span class="skyhshoso-detail-value"><?php echo esc_html($next_payment_date); ?></span>
                </div>
                <?php endif; ?>
                
                <div class="skyhshoso-detail-actions">
                    <?php if ($can_manage_dns) : ?>
                        <a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'domains', 'domain_id' => absint( $domain_id ), 'dns' => 'manage' ), self::get_base_url() ) ); ?>" class="skyhshoso-button skyhshoso-button-primary">
                            <span class="skyhshoso-button-text"><?php esc_html_e('Manage DNS', 'skyhs-hosting-solution'); ?></span>
                        </a>
                    <?php else : ?>
                        <p class="skyhshoso-note"><?php esc_html_e('DNS management is only available for active or pending cancellation subscriptions.', 'skyhs-hosting-solution'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
       
        <?php
    }
    
    /**
     * Render DNS management interface
     * * @param WP_Post $domain The domain post object
     */
    private static function render_dns_management($domain) {
        $domain_name = $domain->post_title;
        $domain_id = $domain->ID;
        ?>
        <div class="skyhshoso-hosting-header">
            <p class="skyhshoso-hosting-title"><?php esc_html_e('DNS Management:', 'skyhs-hosting-solution'); ?> <?php echo esc_html($domain_name); ?></p>
            <a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'domains', 'domain_id' => absint( $domain_id ) ), self::get_base_url() ) ); ?>" class="skyhshoso-card-button">
                <span><?php esc_html_e('Back to Domain Details', 'skyhs-hosting-solution'); ?></span>
            </a>
        </div>
        
        <div class="skyhshoso-section-content">
                <?php
                // Capture the output of the enom_dns_editor function
                ob_start();
                skyhshoso_enom_dns_editor($domain_name);
                $dns_editor_output = ob_get_clean();
                
                // Output the DNS editor (it employs strict internal escaping using esc_html and esc_attr)
                echo $dns_editor_output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                ?>
            
        </div>
        <?php
    }
    

    /**
     * Render the collaborators tab content
     */
    public static function render_collaborators_tab() {
        $current_user_id = get_current_user_id();
        ?>
        <div id="skyhshoso-collaborator-form-container" style="display: none;" class="skyhshoso-form-card">
            <h3 class="skyhshoso-form-title"><?php esc_html_e('Invite User', 'skyhs-hosting-solution'); ?></h3>
            <div class="skyhshoso-invite-form-wrapper">
            <form id="skyhshoso-invite-user-form" class="skyhshoso-form">
                <?php wp_nonce_field('skyhshoso_invite_user', 'skyhshoso_collaborator_nonce'); ?>
                    <div class="skyhshoso-form-group skyhshoso-material-input">
                        <div class="skyhshoso-input-container">
                    <input type="email" id="invitee_email" name="invitee_email" class="skyhshoso-form-input" placeholder="<?php esc_attr_e('Email Address', 'skyhs-hosting-solution'); ?>" required>
                            
                            <div class="skyhshoso-input-underline"></div>
                        </div>
                </div>
                <div class="skyhshoso-form-actions">
                    <button type="submit" class="skyhshoso-button skyhshoso-button-primary">
                        <span class="skyhshoso-button-text"><?php esc_html_e('Send Invitation', 'skyhs-hosting-solution'); ?></span>
                    </button>
                    <button type="button" id="skyhshoso-cancel-invite" class="skyhshoso-button skyhshoso-button-secondary">
                        <span class="skyhshoso-button-text"><?php esc_html_e('Cancel', 'skyhs-hosting-solution'); ?></span>
                    </button>
                </div>
            </form>
                <div id="skyhshoso-invite-message" class="skyhshoso-message" style="display: none;"></div>
            </div>
        </div>
        
        <input type="hidden" id="skyhshoso-nonce-value" value="<?php echo esc_attr(wp_create_nonce('skyhshoso-collaborator-nonce')); ?>">
        <input type="hidden" id="skyhshoso-ajax-url" value="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
        
        <div id="skyhshoso-collaborator-lists" class="skyhshoso-collaborator-lists">
            <?php
            // Display users you invited
            $invited_users = get_user_meta($current_user_id, 'skyhshoso_invited_users', true);
            $invited_users = is_array($invited_users) ? $invited_users : array();
            
            if (!empty($invited_users)) {
                ?>
                <div class="skyhshoso-collaborator-section">
                    <h3 class="skyhshoso-collaborator-title"><?php esc_html_e('Users You Invited', 'skyhs-hosting-solution'); ?></h3>
                    <div class="skyhshoso-table-wrapper skyhshoso-collaborator-table-wrapper">
                        <table class="skyhshoso-table skyhshoso-collaborator-table">
                            <thead>
                                <tr>
                                    <th class="skyhshoso-column-name"><?php esc_html_e('Email', 'skyhs-hosting-solution'); ?></th>
                                    <th class="skyhshoso-column-action"><?php esc_html_e('Action', 'skyhs-hosting-solution'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($invited_users as $user_id) : 
                                    $user_info = get_userdata($user_id);
                                    if (!$user_info) continue;
                                ?>
                                <tr class="skyhshoso-collaborator-row">
                                    <td class="skyhshoso-column-name"><?php echo esc_html($user_info->user_email); ?></td>
                                    <td class="skyhshoso-column-action">
                                        <a href="#" class="skyhshoso-action-link skyhshoso-remove-invite" data-user-id="<?php echo esc_attr($user_id); ?>">
                                            <?php esc_html_e('Remove', 'skyhs-hosting-solution'); ?>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php
            }
            
            // Display users who invited you
            $invited_by = get_user_meta($current_user_id, 'skyhshoso_invited_by', true);
            $invited_by = is_array($invited_by) ? $invited_by : array();
            
            if (!empty($invited_by)) {
                ?>
                <div class="skyhshoso-collaborator-section">
                    <h3 class="skyhshoso-collaborator-title"><?php esc_html_e('Users Who Invited You', 'skyhs-hosting-solution'); ?></h3>
                    <div class="skyhshoso-table-wrapper skyhshoso-collaborator-table-wrapper">
                        <table class="skyhshoso-table skyhshoso-collaborator-table">
                            <thead>
                                <tr>
                                    <th class="skyhshoso-column-name"><?php esc_html_e('Email', 'skyhs-hosting-solution'); ?></th>
                                    <th class="skyhshoso-column-action"><?php esc_html_e('Action', 'skyhs-hosting-solution'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($invited_by as $user_id) : 
                                    $user_info = get_userdata($user_id);
                                    if (!$user_info) continue;
                                ?>
                                <tr class="skyhshoso-collaborator-row">
                                    <td class="skyhshoso-column-name"><?php echo esc_html($user_info->user_email); ?></td>
                                    <td class="skyhshoso-column-action">
                                        <a href="#" class="skyhshoso-action-link skyhshoso-remove-invite" data-user-id="<?php echo esc_attr($user_id); ?>">
                                            <?php esc_html_e('Remove', 'skyhs-hosting-solution'); ?>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php
            }
            
            if (empty($invited_users) && empty($invited_by)) {
                ?>
                <div class="skyhshoso-empty-message">
                    <?php esc_html_e('No collaborators found. Click "Invite User" to add a collaborator.', 'skyhs-hosting-solution'); ?>
                </div>
                <?php
            }
            ?>
        <?php
    }

    /**
     * Render a domains table
     * * @param array $domains The domains to display
     * @param string $title The title for this group of domains
     */
    private static function render_domains_table($domains, $title) {
        if (empty($domains)) {
            return;
        }
        ?>
        <div class="skyhshoso-domain-group">
            <table class="skyhshoso-table skyhshoso-domain-table" id="skyhshoso-domain-table">
                <thead>
                    <tr>
                        <th class="skyhshoso-column-domain-name"><?php esc_html_e('Domain Name', 'skyhs-hosting-solution'); ?></th>
                        <th class="skyhshoso-column-status"><?php esc_html_e('Status', 'skyhs-hosting-solution'); ?></th>
                        <th class="skyhshoso-column-action"><?php esc_html_e('Action', 'skyhs-hosting-solution'); ?></th>
                    </tr>
                </thead>
                <tbody id="skyhshoso-domain-tbody">
                    <?php foreach ($domains as $domain) : ?>
                        <tr class="skyhshoso-domain-row" data-title="<?php echo esc_attr(strtolower($domain['title'])); ?>">
                            <td class="skyhshoso-column-domain-name"><?php echo esc_html($domain['title']); ?></td>
                            <td class="skyhshoso-column-status">
                                <?php 
                                $status_class = 'skyhshoso-status-inactive';
                                $display_status = __('Not active', 'skyhs-hosting-solution');
                                
                                if ($domain['status'] === 'active') {
                                    $status_class = 'skyhshoso-status-active';
                                    $display_status = __('Active', 'skyhs-hosting-solution');
                                } elseif ($domain['status'] === 'on-hold') {
                                    $status_class = 'skyhshoso-status-inactive';
                                    $display_status = __('On Hold', 'skyhs-hosting-solution');
                                }
                                ?>
                                <span class="skyhshoso-status-btn <?php echo esc_attr( $status_class ); ?>">
                                    <span class="truncate"><?php echo esc_html($display_status); ?></span>
                                </span>
                            </td>
                            <td class="skyhshoso-column-action" style="text-align: right;">
                                <?php if ($domain['can_manage_dns']) : ?>
                                    <a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'domains', 'domain_id' => absint( $domain['id'] ), 'dns' => 'manage' ), self::get_base_url() ) ); ?>" class="skyhshoso-action-link">
                                        <?php esc_html_e('Manage DNS', 'skyhs-hosting-solution'); ?> 
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Format bytes into a human-readable string.
     *
     * @param float $bytes
     * @return string
     */
    private static function format_bytes( $bytes ) {
        if ( $bytes <= 0 ) {
            return '0 B';
        }
        $units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
        $i     = min( floor( log( $bytes, 1024 ) ), count( $units ) - 1 );
        return round( $bytes / pow( 1024, $i ), 1 ) . ' ' . $units[ $i ];
    }

    // -------------------------------------------------------------------------
    // WordPress Sites Tab (NEW FLEET VIEW)
    // -------------------------------------------------------------------------

    public static function render_wp_sites_tab() {
        ?>
        <div id="skyhshoso-tab-wordpress" class="skyhshoso-tab-content">
            <div class="skyhshoso-dashboard-header">
                <h2 style="font-size: 20px; font-weight: 700; color: #111827; margin-bottom: 4px;"><?php esc_html_e('WordPress Sites', 'skyhs-hosting-solution'); ?></h2>
                <p style="color: #6b7280; font-size: 14px; margin-top: 0;"><?php esc_html_e('Manage all your WordPress installations across your hosting accounts.', 'skyhs-hosting-solution'); ?></p>
            </div>

            <div class="skyhshoso-table-wrapper" style="margin-top: 20px; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
                <table id="skyhshoso-wp-site-table" class="skyhshoso-table" style="width: 100%; text-align: left; border-collapse: collapse;">
                    <thead style="background: #f9fafb; border-bottom: 1px solid #e5e7eb;">
                        <tr>
                            <th style="padding: 12px 16px; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Domain / Source</th>
                            <th style="padding: 12px 16px; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Status</th>
                            <th style="padding: 12px 16px; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase; text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="skyhshoso-wp-site-tbody">
                        <tr>
                            <td colspan="3" style="text-align:center; padding: 40px; color: #6b7280;">
                                <svg class="skyhshoso-spinner-svg" viewBox="0 0 50 50" style="width:24px;height:24px;animation:skyhshoso-spin 1s linear infinite;margin:0 auto 10px auto;display:block;"><circle cx="25" cy="25" r="20" fill="none" stroke-width="5" stroke="#2563eb" stroke-linecap="round"></circle></svg>
                                <p style="margin: 0; font-size: 14px;">Scanning fleet for WordPress installations...</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <div id="skyhshoso-wp-site-pagination" class="skyhshoso-pagination-container" data-current-page="1" data-total-pages="1" style="display:none;"></div>
        </div>
        <?php
    }

    public static function render_wp_site_detail( $wp_site_id ) {
        // Obsolete function, replaced by fleet view but kept to prevent fatal errors
    }

    public static function render_new_wp_site_products_list() {
        // Obsolete function, replaced by fleet view but kept to prevent fatal errors
    }

    public static function render_new_wp_site_product_detail( $product_id ) {
        // Obsolete function, replaced by fleet view but kept to prevent fatal errors
    }

    /**
     * AJAX handler: returns a single page of hosting rows as HTML.
     */
    public static function ajax_get_hosting_page() {
        if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'skyhshoso_dashboard_nonce' ) ) {
            wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
        }

        $paged     = isset( $_POST['paged'] ) ? max( 1, intval( $_POST['paged'] ) ) : 1;
        $search    = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
        $base_url  = isset( $_POST['base_url'] ) ? esc_url_raw( wp_unslash( $_POST['base_url'] ) ) : '';
        $current_user_id = get_current_user_id();

        $args = array(
            'post_type'      => 'skyhshoso_hosting',
            'posts_per_page' => 10,
            'paged'          => $paged,
        );

        if ( ! current_user_can( 'administrator' ) ) {
            $invited_by = get_user_meta( $current_user_id, 'skyhshoso_invited_by', true );
            $invited_by = is_array( $invited_by ) ? $invited_by : array();
            if ( ! empty( $invited_by ) ) {
                $args['author__in'] = array_merge( array( $current_user_id ), $invited_by );
            } else {
                $args['author'] = $current_user_id;
            }
        }

        if ( ! empty( $search ) ) {
            $args['s'] = $search;
        }

        $hosting_query = new WP_Query( $args );
        $total_pages   = $hosting_query->max_num_pages;

        ob_start();
        if ( $hosting_query->have_posts() ) {
            while ( $hosting_query->have_posts() ) {
                $hosting_query->the_post();
                $hosting_id       = get_the_ID();
                $hosting_domain   = get_post_meta( $hosting_id, 'skyhshoso_hosting_domain', true );
                $subscription_id  = get_post_meta( $hosting_id, 'skyhshoso_subscription_id', true );

                $subscription_status = 'inactive';
                $status_class        = 'skyhshoso-status-inactive';

                if ( ! empty( $subscription_id ) ) {
                    $subscription = skyhshoso_get_subscription( $subscription_id );
                    if ( $subscription ) {
                        $subscription_status = $subscription->get_status();
                        if ( $subscription_status === 'active' || $subscription_status === 'pending-cancel' ) {
                            $status_class = 'skyhshoso-status-active';
                        }
                    }
                }

                $display_status = str_replace( '-', ' ', $subscription_status );
                $display_status = ucwords( $display_status );

                $domain_display = ! empty( $hosting_domain ) ? esc_html( $hosting_domain ) : 'Not set';
                ?>
                <tr class="skyhshoso-hosting-row" data-title="<?php echo esc_attr( strtolower( get_the_title() ) ); ?>" data-domain="<?php echo esc_attr( strtolower( $domain_display ) ); ?>">
                    <td class="skyhshoso-column-plan"><?php the_title(); ?></td>
                    <td class="skyhshoso-column-domain"><?php echo esc_html( $domain_display ); ?></td>
                    <td class="skyhshoso-column-status">
                        <span class="skyhshoso-status-btn <?php echo esc_attr( $status_class ); ?>">
                            <span class="truncate"><?php echo esc_html( $display_status ); ?></span>
                        </span>
                    </td>
                    <td class="skyhshoso-column-action">
                        <?php if ( in_array( $subscription_status, array( 'active', 'pending-cancel' ), true ) ) : ?>
                            <a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'skyhshoso_hosting', 'hosting_id' => $hosting_id ), $base_url ) ); ?>" class="skyhshoso-action-link">
                                <?php esc_html_e( 'Manage', 'skyhs-hosting-solution' ); ?>
                            </a>
                        <?php else : ?>
                            <span class="skyhshoso-action-disabled" style="color:#999;font-size:14px;"><?php echo esc_html( $display_status ); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php
            }
        }
        wp_reset_postdata();
        $html = ob_get_clean();

        wp_send_json_success( array(
            'html'         => $html,
            'total_pages'  => $total_pages,
            'current_page' => $paged,
        ) );
    }

    /**
     * AJAX handler: returns a single page of domain rows as HTML.
     */
    public static function ajax_get_domain_page() {
        if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'skyhshoso_dashboard_nonce' ) ) {
            wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
        }

        $paged    = isset( $_POST['paged'] ) ? max( 1, intval( $_POST['paged'] ) ) : 1;
        $search   = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
        $base_url = isset( $_POST['base_url'] ) ? esc_url_raw( wp_unslash( $_POST['base_url'] ) ) : '';

        $domains_handler = new SkyHSHOSO_Account_Domains();
        $domains_grouped = $domains_handler->get_all_accessible_domains();

        $flat_domains = array();
        if ( current_user_can( 'administrator' ) && isset( $domains_grouped['all'] ) && ! empty( $domains_grouped['all'] ) ) {
            $flat_domains = $domains_grouped['all'];
        } else {
            if ( isset( $domains_grouped['your'] ) && ! empty( $domains_grouped['your'] ) ) {
                $flat_domains = $domains_grouped['your'];
            }
            foreach ( $domains_grouped as $key => $group ) {
                if ( $key !== 'your' && isset( $group['domains'] ) && ! empty( $group['domains'] ) ) {
                    $flat_domains = array_merge( $flat_domains, $group['domains'] );
                }
            }
        }

        if ( ! empty( $search ) ) {
            $flat_domains = array_filter( $flat_domains, function ( $d ) use ( $search ) {
                return stripos( $d['title'], $search ) !== false;
            } );
            $flat_domains = array_values( $flat_domains );
        }

        $dper_page    = 10;
        $dtotal       = count( $flat_domains );
        $dtotal_pages = max( 1, ceil( $dtotal / $dper_page ) );
        $d_offset     = ( $paged - 1 ) * $dper_page;
        $page_domains = array_slice( $flat_domains, $d_offset, $dper_page );

        ob_start();
        if ( ! empty( $page_domains ) ) :
            foreach ( $page_domains as $domain ) :
                $status_class = 'skyhshoso-status-inactive';
                $display_status = __( 'Not active', 'skyhs-hosting-solution' );

                if ( $domain['status'] === 'active' ) {
                    $status_class = 'skyhshoso-status-active';
                    $display_status = __( 'Active', 'skyhs-hosting-solution' );
                } elseif ( $domain['status'] === 'on-hold' ) {
                    $status_class = 'skyhshoso-status-inactive';
                    $display_status = __( 'On Hold', 'skyhs-hosting-solution' );
                }
                ?>
                <tr class="skyhshoso-domain-row" data-title="<?php echo esc_attr( strtolower( $domain['title'] ) ); ?>">
                    <td class="skyhshoso-column-domain-name"><?php echo esc_html( $domain['title'] ); ?></td>
                    <td class="skyhshoso-column-status">
                        <span class="skyhshoso-status-btn <?php echo esc_attr( $status_class ); ?>">
                            <span class="truncate"><?php echo esc_html( $display_status ); ?></span>
                        </span>
                    </td>
                    <td class="skyhshoso-column-action" style="text-align: right;">
                        <?php if ( $domain['can_manage_dns'] ) : ?>
                            <a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'domains', 'domain_id' => absint( $domain['id'] ), 'dns' => 'manage' ), $base_url ) ); ?>" class="skyhshoso-action-link">
                                <?php esc_html_e( 'Manage DNS', 'skyhs-hosting-solution' ); ?>
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php
            endforeach;
        endif;
        $html = ob_get_clean();

        wp_send_json_success( array(
            'html'          => $html,
            'total_pages'   => $dtotal_pages,
            'current_page'  => $paged,
            'total_items'   => $dtotal,
        ) );
    }

    /**
     * AJAX handler: replaced by the aggregated backend handler in whm-ajax-handlers.php.
     * This is kept to satisfy action hooks without crashing.
     */
    public static function ajax_get_wp_site_page() {
        // Intentionally empty. The actual hook is defined and executed from includes/whm-ajax-handlers.php
        // which powers the new Fleet Management table.
    }
}

// Initialize shortcode
SkyHSHOSO_Dashboard_Shortcode::init();