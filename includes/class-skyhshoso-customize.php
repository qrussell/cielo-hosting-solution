<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class SkyHSHOSO_Customize {

    private static $menu_option = 'skyhshoso_dashboard_menu_items';

    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'wp_ajax_skyhshoso_save_menu_items', array( $this, 'ajax_save_menu_items' ) );
        add_action( 'wp_ajax_skyhshoso_get_default_menu', array( $this, 'ajax_get_default_menu' ) );
    }

    public function enqueue_scripts( $hook ) {
        if ( false === strpos( $hook, 'skyhshoso-customize' ) ) {
            return;
        }

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

        wp_enqueue_style(
            'skyhshoso-menu-builder',
            SKYHSHOSO_PLUGIN_URL . 'assets/css/dashboard-menu-builder.css',
            array(),
            SKYHSHOSO_VERSION
        );

        wp_enqueue_script(
            'skyhshoso-menu-builder',
            SKYHSHOSO_PLUGIN_URL . 'assets/js/dashboard-menu-builder.js',
            array( 'jquery', 'jquery-ui-sortable' ),
            SKYHSHOSO_VERSION,
            true
        );

        wp_localize_script(
            'skyhshoso-menu-builder',
            'skyhshosoMenuBuilder',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'skyhshoso_menu_builder' ),
                'i18n'    => array(
                    'deleteConfirm' => __( 'Delete this menu item?', 'skyhs-hosting-solution' ),
                    'saving'        => __( 'Saving...', 'skyhs-hosting-solution' ),
                    'saved'         => __( 'Menu saved!', 'skyhs-hosting-solution' ),
                    'error'         => __( 'Save failed.', 'skyhs-hosting-solution' ),
                ),
            )
        );

        wp_enqueue_script(
            'hosting-solution-admin-script',
            SKYHSHOSO_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            SKYHSHOSO_VERSION,
            true
        );

        wp_localize_script(
            'hosting-solution-admin-script',
            'skyhshoso_admin_l10n',
            array(
                'preview_title' => __( 'Preview', 'skyhs-hosting-solution' ),
            )
        );
    }

    public function render_page() {
        $current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'general';
        ?>
        <div class="skyhshoso-wizard-wrap">
            <div class="skyhshoso-wizard-header">
                <h1><?php echo esc_html__( 'Customize', 'skyhs-hosting-solution' ); ?></h1>
                <p><?php esc_html_e( 'Customize the client dashboard appearance and navigation menu.', 'skyhs-hosting-solution' ); ?></p>
            </div>

            <div class="skyhshoso-wizard-nav">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=skyhshoso-customize&tab=general' ) ); ?>" class="skyhshoso-wizard-step-link <?php echo $current_tab === 'general' ? 'active' : ''; ?>"><?php esc_html_e( 'General', 'skyhs-hosting-solution' ); ?></a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=skyhshoso-customize&tab=menu' ) ); ?>" class="skyhshoso-wizard-step-link <?php echo $current_tab === 'menu' ? 'active' : ''; ?>"><?php esc_html_e( 'Menu', 'skyhs-hosting-solution' ); ?></a>
            </div>

            <div class="skyhshoso-wizard-content">
                <?php if ( $current_tab === 'general' ) : ?>
                    <?php $this->render_general_tab(); ?>
                <?php elseif ( $current_tab === 'menu' ) : ?>
                    <?php $this->render_menu_tab(); ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function render_general_tab() {
        $options              = get_option( 'skyhshoso_settings_group', array() );
        $custom_logo          = isset( $options['custom_logo'] ) ? $options['custom_logo'] : '';
        $custom_sitename      = isset( $options['custom_sitename'] ) ? $options['custom_sitename'] : '';
        $show_only_logo       = isset( $options['show_only_logo'] ) ? (bool) $options['show_only_logo'] : false;
        $guest_welcome_title  = isset( $options['guest_welcome_title'] ) ? $options['guest_welcome_title'] : '';
        $guest_welcome_subtitle = isset( $options['guest_welcome_subtitle'] ) ? $options['guest_welcome_subtitle'] : '';
        $guest_welcome_btn_text = isset( $options['guest_welcome_btn_text'] ) ? $options['guest_welcome_btn_text'] : '';
        $guest_welcome_btn_url  = isset( $options['guest_welcome_btn_url'] ) ? $options['guest_welcome_btn_url'] : '';
        $enable_guest_dashboard = isset( $options['enable_guest_dashboard'] ) ? (bool) $options['enable_guest_dashboard'] : false;
        $back_to_site_url     = isset( $options['back_to_site_url'] ) ? $options['back_to_site_url'] : '';
        $header_menu_id       = isset( $options['header_menu_id'] ) ? $options['header_menu_id'] : '';
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
                <label style="font-weight:600; display:block; margin-bottom:8px;"><?php esc_html_e( 'Return to Site URL', 'skyhs-hosting-solution' ); ?></label>
                <input type="text" name="skyhshoso_settings_group[back_to_site_url]" value="<?php echo esc_url( $back_to_site_url ); ?>" placeholder="<?php echo esc_url( home_url( '/' ) ); ?>" style="width:100%;max-width:400px;">
                <p style="font-size:12px;color:#646970;margin:8px 0 0 0;"><?php esc_html_e( 'The custom URL the user will be sent to when they click the "Return to Site" button. Defaults to the home page URL.', 'skyhs-hosting-solution' ); ?></p>
            </div>

            <div class="skyhshoso-wizard-form-group" style="margin-top: 20px;">
                <label style="font-weight:600; display:block; margin-bottom:8px;"><?php esc_html_e( 'Header Navigation Menu', 'skyhs-hosting-solution' ); ?></label>
                <select name="skyhshoso_settings_group[header_menu_id]" style="width:100%;max-width:400px;height:36px;">
                    <option value=""><?php esc_html_e( 'None (Show default "Return to Site" button)', 'skyhs-hosting-solution' ); ?></option>
                    <option value="location" <?php selected( $header_menu_id, 'location' ); ?>><?php esc_html_e( 'Use assigned "SkyHS Dashboard Header Menu" Location', 'skyhs-hosting-solution' ); ?></option>
                    <?php
                    $menus = wp_get_nav_menus();
                    if ( ! empty( $menus ) ) {
                        foreach ( $menus as $menu ) {
                            echo '<option value="' . esc_attr( $menu->term_id ) . '" ' . selected( $header_menu_id, (string) $menu->term_id, false ) . '>' . esc_html( $menu->name ) . '</option>';
                        }
                    }
                    ?>
                </select>
                <p style="font-size:12px;color:#646970;margin:8px 0 0 0;">
                    <?php 
                    echo sprintf(
                        /* translators: %s: menus admin url */
                        esc_html__( 'Assign a WordPress navigation menu to display in the client dashboard header. You can also assign it directly to the "SkyHS Dashboard Header Menu" location under %s.', 'skyhs-hosting-solution' ),
                        '<a href="' . esc_url( admin_url( 'nav-menus.php' ) ) . '" target="_blank">' . esc_html__( 'Appearance > Menus', 'skyhs-hosting-solution' ) . '</a>'
                    ); 
                    ?>
                </p>
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

            <div class="skyhshoso-wizard-form-group" style="margin-top: 20px;">
                <label style="font-weight:600; display:block; margin-bottom:8px;"><?php esc_html_e( 'Promotional Button Text', 'skyhs-hosting-solution' ); ?></label>
                <input type="text" name="skyhshoso_settings_group[guest_welcome_btn_text]" value="<?php echo esc_attr( $guest_welcome_btn_text ); ?>" placeholder="<?php esc_attr_e( 'e.g. Shop Now', 'skyhs-hosting-solution' ); ?>" style="width:100%;max-width:400px;">
                <p style="font-size:12px;color:#646970;margin:8px 0 0 0;"><?php esc_html_e( 'The label for the promotional button shown to guests. Leave empty to hide the button.', 'skyhs-hosting-solution' ); ?></p>
            </div>

            <div class="skyhshoso-wizard-form-group" style="margin-top: 20px;">
                <label style="font-weight:600; display:block; margin-bottom:8px;"><?php esc_html_e( 'Promotional Button URL', 'skyhs-hosting-solution' ); ?></label>
                <input type="text" name="skyhshoso_settings_group[guest_welcome_btn_url]" value="<?php echo esc_url( $guest_welcome_btn_url ); ?>" placeholder="https://example.com/sale" style="width:100%;max-width:400px;">
                <p style="font-size:12px;color:#646970;margin:8px 0 0 0;"><?php esc_html_e( 'The URL the promotional button links to.', 'skyhs-hosting-solution' ); ?></p>
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

    private function render_menu_tab() {
        $menu_items = self::get_menu_items();
        ?>
        <div class="skyhshoso-menu-builder-wrap">
            <p style="margin:0 0 16px 0;font-size:13px;color:#50575e;">
                <?php esc_html_e( 'Drag and drop to reorder the client dashboard navigation menu. Add custom endpoints, edit labels, and control visibility.', 'skyhs-hosting-solution' ); ?>
            </p>

            <div class="skyhshoso-menu-builder-toolbar">
                <button type="button" class="button button-primary skyhshoso-add-endpoint-btn">
                    <span class="dashicons dashicons-plus"></span> <?php esc_html_e( 'Add Custom Endpoint', 'skyhs-hosting-solution' ); ?>
                </button>
                <button type="button" class="button skyhshoso-reset-menu-btn">
                    <?php esc_html_e( 'Reset to Default', 'skyhs-hosting-solution' ); ?>
                </button>
                <span class="skyhshoso-menu-save-status"></span>
            </div>

            <div class="skyhshoso-menu-builder-list" id="skyhshoso-menu-items">
                <?php foreach ( $menu_items as $index => $item ) : ?>
                    <?php $is_builtin = isset( $item['default'] ) && $item['default']; ?>
                    <div class="skyhshoso-menu-item <?php echo $is_builtin ? 'skyhshoso-menu-item-builtin' : 'skyhshoso-menu-item-custom'; ?> <?php echo ! empty( $item['enabled'] ) ? '' : 'skyhshoso-menu-item-disabled'; ?>" data-id="<?php echo esc_attr( $item['id'] ); ?>">
                        <div class="skyhshoso-menu-item-handle">
                            <span class="dashicons dashicons-menu"></span>
                        </div>
                        <div class="skyhshoso-menu-item-icon">
                            <?php if ( ! empty( $item['icon'] ) ) : ?>
                                <span class="dashicons <?php echo esc_attr( $item['icon'] ); ?>"></span>
                            <?php else : ?>
                                <span class="dashicons dashicons-admin-links"></span>
                            <?php endif; ?>
                        </div>
                        <div class="skyhshoso-menu-item-content">
                            <div class="skyhshoso-menu-item-title-row">
                                <span class="skyhshoso-menu-item-title"><?php echo esc_html( $item['title'] ); ?></span>
                                <span class="skyhshoso-menu-item-badge">
                                    <?php echo $is_builtin ? esc_html__( 'Built-in', 'skyhs-hosting-solution' ) : esc_html__( 'Custom', 'skyhs-hosting-solution' ); ?>
                                </span>
                                <?php if ( ! $is_builtin ) : ?>
                                    <button type="button" class="button button-small skyhshoso-delete-endpoint-btn" data-id="<?php echo esc_attr( $item['id'] ); ?>" title="<?php esc_attr_e( 'Delete', 'skyhs-hosting-solution' ); ?>">
                                        <span class="dashicons dashicons-trash"></span>
                                    </button>
                                <?php endif; ?>
                            </div>
                            <div class="skyhshoso-menu-item-fields">
                                <input type="hidden" name="menu_items[<?php echo esc_attr( $index ); ?>][id]" value="<?php echo esc_attr( $item['id'] ); ?>">
                                <input type="hidden" name="menu_items[<?php echo esc_attr( $index ); ?>][default]" value="<?php echo $is_builtin ? '1' : '0'; ?>">
                                <input type="hidden" name="menu_items[<?php echo esc_attr( $index ); ?>][type]" value="<?php echo esc_attr( $item['type'] ); ?>">

                                <div class="skyhshoso-menu-item-field">
                                    <label><?php esc_html_e( 'Label', 'skyhs-hosting-solution' ); ?></label>
                                    <input type="text" name="menu_items[<?php echo esc_attr( $index ); ?>][title]" value="<?php echo esc_attr( $item['title'] ); ?>" class="skyhshoso-menu-input-title">
                                </div>

                                <?php if ( isset( $item['tab'] ) && $item['type'] === 'builtin' ) : ?>
                                    <div class="skyhshoso-menu-item-field">
                                        <label><?php esc_html_e( 'Tab', 'skyhs-hosting-solution' ); ?></label>
                                        <input type="text" value="<?php echo esc_attr( $item['tab'] ); ?>" readonly class="skyhshoso-menu-input-readonly">
                                        <input type="hidden" name="menu_items[<?php echo esc_attr( $index ); ?>][tab]" value="<?php echo esc_attr( $item['tab'] ); ?>">
                                    </div>
                                <?php else : ?>
                                    <div class="skyhshoso-menu-item-field">
                                        <label><?php esc_html_e( 'URL', 'skyhs-hosting-solution' ); ?></label>
                                        <input type="text" name="menu_items[<?php echo esc_attr( $index ); ?>][url]" value="<?php echo esc_attr( $item['url'] ?? '' ); ?>" placeholder="https://example.com" class="skyhshoso-menu-input-url">
                                    </div>
                                <?php endif; ?>

                                <div class="skyhshoso-menu-item-field skyhshoso-menu-item-field-inline">
                                    <label>
                                        <input type="checkbox" name="menu_items[<?php echo esc_attr( $index ); ?>][enabled]" value="1" <?php checked( ! empty( $item['enabled'] ) ); ?>>
                                        <?php esc_html_e( 'Enabled', 'skyhs-hosting-solution' ); ?>
                                    </label>
                                    <label>
                                        <select name="menu_items[<?php echo esc_attr( $index ); ?>][visibility]">
                                            <option value="all" <?php selected( $item['visibility'] ?? 'all', 'all' ); ?>><?php esc_html_e( 'All Users', 'skyhs-hosting-solution' ); ?></option>
                                            <option value="logged_in" <?php selected( $item['visibility'] ?? 'all', 'logged_in' ); ?>><?php esc_html_e( 'Logged In Only', 'skyhs-hosting-solution' ); ?></option>
                                            <option value="guest" <?php selected( $item['visibility'] ?? 'all', 'guest' ); ?>><?php esc_html_e( 'Guests Only', 'skyhs-hosting-solution' ); ?></option>
                                        </select>
                                    </label>
                                    <label class="skyhshoso-menu-item-icon-field">
                                        <span><?php esc_html_e( 'Icon', 'skyhs-hosting-solution' ); ?></span>
                                        <input type="text" name="menu_items[<?php echo esc_attr( $index ); ?>][icon]" value="<?php echo esc_attr( $item['icon'] ?? '' ); ?>" placeholder="dashicons-admin-generic" class="skyhshoso-menu-input-icon">
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="skyhshoso-menu-actions">
                <button type="button" id="skyhshoso-save-menu-btn" class="skyhshoso-wizard-btn skyhshoso-wizard-btn-primary">
                    <?php esc_html_e( 'Save Menu', 'skyhs-hosting-solution' ); ?>
                </button>
                <span class="skyhshoso-menu-save-status-bottom"></span>
            </div>
        </div>

        <script type="text/template" id="skyhshoso-endpoint-template">
            <div class="skyhshoso-menu-item skyhshoso-menu-item-custom" data-id="{id}">
                <div class="skyhshoso-menu-item-handle">
                    <span class="dashicons dashicons-menu"></span>
                </div>
                <div class="skyhshoso-menu-item-icon">
                    <span class="dashicons dashicons-admin-links"></span>
                </div>
                <div class="skyhshoso-menu-item-content">
                    <div class="skyhshoso-menu-item-title-row">
                        <span class="skyhshoso-menu-item-title">{title}</span>
                        <span class="skyhshoso-menu-item-badge"><?php esc_html_e( 'Custom', 'skyhs-hosting-solution' ); ?></span>
                        <button type="button" class="button button-small skyhshoso-delete-endpoint-btn" data-id="{id}" title="<?php esc_attr_e( 'Delete', 'skyhs-hosting-solution' ); ?>">
                            <span class="dashicons dashicons-trash"></span>
                        </button>
                    </div>
                    <div class="skyhshoso-menu-item-fields">
                        <input type="hidden" name="menu_items[{index}][id]" value="{id}">
                        <input type="hidden" name="menu_items[{index}][default]" value="0">
                        <input type="hidden" name="menu_items[{index}][type]" value="custom">
                        <div class="skyhshoso-menu-item-field">
                            <label><?php esc_html_e( 'Label', 'skyhs-hosting-solution' ); ?></label>
                            <input type="text" name="menu_items[{index}][title]" value="{title}" class="skyhshoso-menu-input-title">
                        </div>
                        <div class="skyhshoso-menu-item-field">
                            <label><?php esc_html_e( 'URL', 'skyhs-hosting-solution' ); ?></label>
                            <input type="text" name="menu_items[{index}][url]" value="{url}" placeholder="https://example.com" class="skyhshoso-menu-input-url">
                        </div>
                        <div class="skyhshoso-menu-item-field skyhshoso-menu-item-field-inline">
                            <label>
                                <input type="checkbox" name="menu_items[{index}][enabled]" value="1" checked>
                                <?php esc_html_e( 'Enabled', 'skyhs-hosting-solution' ); ?>
                            </label>
                            <label>
                                <select name="menu_items[{index}][visibility]">
                                    <option value="all" selected><?php esc_html_e( 'All Users', 'skyhs-hosting-solution' ); ?></option>
                                    <option value="logged_in"><?php esc_html_e( 'Logged In Only', 'skyhs-hosting-solution' ); ?></option>
                                    <option value="guest"><?php esc_html_e( 'Guests Only', 'skyhs-hosting-solution' ); ?></option>
                                </select>
                            </label>
                            <label class="skyhshoso-menu-item-icon-field">
                                <span><?php esc_html_e( 'Icon', 'skyhs-hosting-solution' ); ?></span>
                                <input type="text" name="menu_items[{index}][icon]" value="" placeholder="dashicons-admin-generic" class="skyhshoso-menu-input-icon">
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </script>

        <div id="skyhshoso-add-endpoint-modal" class="skyhshoso-modal" style="display:none;">
            <div class="skyhshoso-modal-backdrop"></div>
            <div class="skyhshoso-modal-content">
                <div class="skyhshoso-modal-header">
                    <h3><?php esc_html_e( 'Add Custom Endpoint', 'skyhs-hosting-solution' ); ?></h3>
                    <button type="button" class="skyhshoso-modal-close dashicons dashicons-no-alt"></button>
                </div>
                <div class="skyhshoso-modal-body">
                    <div class="skyhshoso-wizard-form-group">
                        <label><?php esc_html_e( 'Menu Label', 'skyhs-hosting-solution' ); ?></label>
                        <input type="text" id="skyhshoso-new-endpoint-title" class="skyhshoso-new-endpoint-title" value="" placeholder="<?php esc_attr_e( 'e.g. Support', 'skyhs-hosting-solution' ); ?>">
                    </div>
                    <div class="skyhshoso-wizard-form-group">
                        <label><?php esc_html_e( 'URL', 'skyhs-hosting-solution' ); ?></label>
                        <input type="text" id="skyhshoso-new-endpoint-url" class="skyhshoso-new-endpoint-url" value="" placeholder="https://example.com/support">
                        <p style="font-size:12px;color:#646970;margin:4px 0 0 0;"><?php esc_html_e( 'Enter a full URL (https://...) or a relative path (/support-page).', 'skyhs-hosting-solution' ); ?></p>
                    </div>
                    <div class="skyhshoso-wizard-form-group">
                        <label><?php esc_html_e( 'Visibility', 'skyhs-hosting-solution' ); ?></label>
                        <select id="skyhshoso-new-endpoint-visibility">
                            <option value="all"><?php esc_html_e( 'All Users', 'skyhs-hosting-solution' ); ?></option>
                            <option value="logged_in"><?php esc_html_e( 'Logged In Only', 'skyhs-hosting-solution' ); ?></option>
                            <option value="guest"><?php esc_html_e( 'Guests Only', 'skyhs-hosting-solution' ); ?></option>
                        </select>
                    </div>
                    <div class="skyhshoso-wizard-form-group">
                        <label><?php esc_html_e( 'Dashicon', 'skyhs-hosting-solution' ); ?></label>
                        <input type="text" id="skyhshoso-new-endpoint-icon" class="skyhshoso-new-endpoint-icon" value="" placeholder="dashicons-admin-generic">
                        <p style="font-size:12px;color:#646970;margin:4px 0 0 0;">
                            <?php esc_html_e( 'Enter a Dashicon class name. See', 'skyhs-hosting-solution' ); ?>
                            <a href="https://developer.wordpress.org/resource/dashicons/" target="_blank"><?php esc_html_e( 'Dashicons reference', 'skyhs-hosting-solution' ); ?></a>.
                        </p>
                    </div>
                </div>
                <div class="skyhshoso-modal-footer">
                    <button type="button" class="button skyhshoso-modal-close-btn"><?php esc_html_e( 'Cancel', 'skyhs-hosting-solution' ); ?></button>
                    <button type="button" class="button button-primary skyhshoso-add-endpoint-confirm"><?php esc_html_e( 'Add Endpoint', 'skyhs-hosting-solution' ); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    public static function get_menu_items() {
        $saved = get_option( self::$menu_option, null );
        if ( is_array( $saved ) && ! empty( $saved ) ) {
            return $saved;
        }
        return self::get_default_menu_items();
    }

    public static function get_default_menu_items() {
        $dashboard_url = SkyHSHOSO_Settings::get_dashboard_url();
        $base_url = $dashboard_url ? $dashboard_url : home_url();

        return array(
            array(
                'id'         => 'dashboard',
                'title'      => __( 'Dashboard', 'skyhs-hosting-solution' ),
                'type'       => 'builtin',
                'tab'        => 'dashboard',
                'url'        => add_query_arg( 'tab', 'dashboard', $base_url ),
                'enabled'    => true,
                'visibility' => 'logged_in',
                'icon'       => 'dashicons-dashboard',
                'default'    => true,
            ),
            array(
                'id'         => 'hosting',
                'title'      => __( 'Hosting', 'skyhs-hosting-solution' ),
                'type'       => 'builtin',
                'tab'        => 'skyhshoso_hosting',
                'url'        => add_query_arg( 'tab', 'skyhshoso_hosting', $base_url ),
                'enabled'    => true,
                'visibility' => 'logged_in',
                'icon'       => 'dashicons-admin-home',
                'default'    => true,
            ),
            array(
                'id'         => 'domains',
                'title'      => __( 'Domains', 'skyhs-hosting-solution' ),
                'type'       => 'builtin',
                'tab'        => 'domains',
                'url'        => add_query_arg( 'tab', 'domains', $base_url ),
                'enabled'    => true,
                'visibility' => 'logged_in',
                'icon'       => 'dashicons-admin-site',
                'default'    => true,
            ),
            array(
                'id'         => 'wp_sites',
                'title'      => __( 'WordPress Sites', 'skyhs-hosting-solution' ),
                'type'       => 'builtin',
                'tab'        => 'wp_sites',
                'url'        => add_query_arg( 'tab', 'wp_sites', $base_url ),
                'enabled'    => true,
                'visibility' => 'logged_in',
                'icon'       => 'dashicons-wordpress',
                'default'    => true,
            ),
            array(
                'id'         => 'collaborators',
                'title'      => __( 'Collaborators', 'skyhs-hosting-solution' ),
                'type'       => 'builtin',
                'tab'        => 'collaborators',
                'url'        => add_query_arg( 'tab', 'collaborators', $base_url ),
                'enabled'    => true,
                'visibility' => 'logged_in',
                'icon'       => 'dashicons-groups',
                'default'    => true,
            ),
            array(
                'id'         => 'subscriptions',
                'title'      => __( 'Subscriptions', 'skyhs-hosting-solution' ),
                'type'       => 'builtin',
                'tab'        => 'subscriptions',
                'url'        => add_query_arg( 'tab', 'subscriptions', $base_url ),
                'enabled'    => true,
                'visibility' => 'logged_in',
                'icon'       => 'dashicons-update',
                'default'    => true,
            ),
            array(
                'id'         => 'account',
                'title'      => __( 'Account', 'skyhs-hosting-solution' ),
                'type'       => 'builtin',
                'tab'        => 'account',
                'url'        => wc_get_account_endpoint_url( 'dashboard' ),
                'enabled'    => true,
                'visibility' => 'logged_in',
                'icon'       => 'dashicons-admin-users',
                'default'    => true,
            ),
            array(
                'id'         => 'guest_home',
                'title'      => __( 'Home', 'skyhs-hosting-solution' ),
                'type'       => 'custom',
                'url'        => add_query_arg( 'tab', 'dashboard', $base_url ),
                'enabled'    => true,
                'visibility' => 'guest',
                'icon'       => 'dashicons-dashboard',
                'default'    => true,
            ),
            array(
                'id'         => 'guest_hosting',
                'title'      => __( 'Hosting Plans', 'skyhs-hosting-solution' ),
                'type'       => 'custom',
                'url'        => add_query_arg( array( 'tab' => 'skyhshoso_hosting', 'new_hosting' => '1' ), $base_url ),
                'enabled'    => true,
                'visibility' => 'guest',
                'icon'       => 'dashicons-admin-home',
                'default'    => true,
            ),
            array(
                'id'         => 'guest_wp_sites',
                'title'      => __( 'Buy WordPress', 'skyhs-hosting-solution' ),
                'type'       => 'custom',
                'url'        => add_query_arg( array( 'tab' => 'wp_sites', 'new_wp_site' => '1' ), $base_url ),
                'enabled'    => true,
                'visibility' => 'guest',
                'icon'       => 'dashicons-wordpress',
                'default'    => true,
            ),
            array(
                'id'         => 'guest_register_domain',
                'title'      => __( 'Register Domain', 'skyhs-hosting-solution' ),
                'type'       => 'custom',
                'url'        => add_query_arg( array( 'tab' => 'domains', 'new_domain' => '1' ), $base_url ),
                'enabled'    => true,
                'visibility' => 'guest',
                'icon'       => 'dashicons-admin-site',
                'default'    => true,
            ),
            array(
                'id'         => 'guest_transfer_domain',
                'title'      => __( 'Transfer Domain', 'skyhs-hosting-solution' ),
                'type'       => 'custom',
                'url'        => add_query_arg( array( 'tab' => 'domains', 'transfer_domain' => '1' ), $base_url ),
                'enabled'    => true,
                'visibility' => 'guest',
                'icon'       => 'dashicons-migrate',
                'default'    => true,
            ),
            array(
                'id'         => 'guest_signin',
                'title'      => __( 'Sign In', 'skyhs-hosting-solution' ),
                'type'       => 'custom',
                'url'        => wc_get_page_permalink( 'myaccount' ),
                'enabled'    => true,
                'visibility' => 'guest',
                'icon'       => 'dashicons-lock',
                'default'    => true,
            ),
        );
    }

    public function ajax_save_menu_items() {
        check_ajax_referer( 'skyhshoso_menu_builder', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'skyhs-hosting-solution' ) ) );
        }

        $raw = isset( $_POST['menu_items'] ) ? wp_unslash( $_POST['menu_items'] ) : array();

        $sanitized = array();
        foreach ( $raw as $item ) {
            $id = isset( $item['id'] ) ? sanitize_key( $item['id'] ) : 'item_' . uniqid();

            $entry = array(
                'id'         => $id,
                'title'      => isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '',
                'type'       => isset( $item['type'] ) && in_array( $item['type'], array( 'builtin', 'custom' ), true ) ? $item['type'] : 'custom',
                'url'        => isset( $item['url'] ) ? esc_url_raw( $item['url'] ) : '',
                'enabled'    => ! empty( $item['enabled'] ),
                'visibility' => isset( $item['visibility'] ) && in_array( $item['visibility'], array( 'all', 'logged_in', 'guest' ), true ) ? $item['visibility'] : 'all',
                'icon'       => isset( $item['icon'] ) ? sanitize_text_field( $item['icon'] ) : '',
                'default'    => ! empty( $item['default'] ),
            );

            if ( $entry['type'] === 'builtin' && isset( $item['tab'] ) ) {
                $entry['tab'] = sanitize_key( $item['tab'] );
            }

            $sanitized[] = $entry;
        }

        update_option( self::$menu_option, $sanitized );

        wp_send_json_success( array(
            'message' => __( 'Menu saved successfully.', 'skyhs-hosting-solution' ),
            'items'   => $sanitized,
        ) );
    }

    public function ajax_get_default_menu() {
        check_ajax_referer( 'skyhshoso_menu_builder', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'skyhs-hosting-solution' ) ) );
        }

        delete_option( self::$menu_option );

        $defaults = self::get_default_menu_items();

        wp_send_json_success( array(
            'message' => __( 'Menu reset to default.', 'skyhs-hosting-solution' ),
            'items'   => $defaults,
        ) );
    }

    public static function get_dashboard_nav_items() {
        $items = self::get_menu_items();
        $is_logged_in = is_user_logged_in();

        $filtered = array();
        foreach ( $items as $item ) {
            if ( empty( $item['enabled'] ) ) {
                continue;
            }

            $vis = isset( $item['visibility'] ) ? $item['visibility'] : 'all';
            if ( $vis === 'logged_in' && ! $is_logged_in ) {
                continue;
            }
            if ( $vis === 'guest' && $is_logged_in ) {
                continue;
            }

            $domain_disabled = SkyHSHOSO_Settings::is_domain_registration_disabled();
            if ( $domain_disabled ) {
                if ( $item['id'] === 'domains' ) {
                    continue;
                }
                if ( isset( $item['id'] ) && strpos( $item['id'], 'guest_' ) === 0 && strpos( $item['id'], 'domain' ) !== false ) {
                    continue;
                }
            }

            $filtered[] = $item;
        }

        return $filtered;
    }
}

SkyHSHOSO_Customize::instance();
