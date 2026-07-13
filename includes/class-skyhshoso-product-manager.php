<?php
/**
 * SkyHS Product Manager
 *
 * Guided wizard UI for creating hosting products (simple/variable,
 * subscription/one-time) without touching complex WooCommerce screens.
 *
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

class SkyHSHOSO_Product_Manager {

    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // ADD THIS LINE: This hooks the function to make the menu visible!
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

        // AJAX: create product
        add_action( 'wp_ajax_skyhshoso_create_product', array( $this, 'ajax_create_product' ) );
        // AJAX: fetch server plans
        add_action( 'wp_ajax_skyhshoso_fetch_server_plans', array( $this, 'ajax_fetch_server_plans' ) );
        // AJAX: delete product
        add_action( 'wp_ajax_skyhshoso_delete_product', array( $this, 'ajax_delete_product' ) );
        // AJAX: get products
        add_action( 'wp_ajax_skyhshoso_get_products', array( $this, 'ajax_get_products' ) );
    }

    /**
     * Add "Products" submenu under SKYHS.
     */
    public function add_admin_page() {
        add_submenu_page(
            'skyhshoso-dashboard',
            __( 'Products', 'skyhs-hosting-solution' ),
            __( 'Products', 'skyhs-hosting-solution' ),
            'manage_options',
            'skyhshoso-products',
            array( $this, 'render_page' )
        );
    }

    /**
     * Enqueue scripts/styles.
     */
    public function enqueue_scripts( $hook ) {
        $page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( false === strpos( $hook, 'skyhshoso-products' ) && 'skyhshoso-products' !== $page ) {
            return;
        }

        wp_enqueue_style(
            'skyhshoso-hosting-manager',
            SKYHSHOSO_PLUGIN_URL . 'assets/css/hosting-manager.css',
            array(),
            SKYHSHOSO_VERSION
        );

        wp_enqueue_script(
            'skyhshoso-product-manager',
            SKYHSHOSO_PLUGIN_URL . 'assets/js/product-manager.js',
            array( 'jquery' ),
            SKYHSHOSO_VERSION,
            true
        );

        $servers = get_posts( array(
            'post_type'      => 'skyhshoso_server',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );

        $server_list = array();
        foreach ( $servers as $s ) {
            $plans = get_post_meta( $s->ID, '_skyhshoso_whm_default_package_names', true );
            $server_list[] = array(
                'id'    => $s->ID,
                'title' => $s->post_title,
                'plans' => is_array( $plans ) ? $plans : array(),
            );
        }

        wp_localize_script(
            'skyhshoso-product-manager',
            'skyhshoso_pm',
            array(
                'ajax_url'          => admin_url( 'admin-ajax.php' ),
                'nonce_create'      => wp_create_nonce( 'skyhshoso_create_product' ),
                'nonce_plans'       => wp_create_nonce( 'skyhshoso_fetch_server_plans' ),
                'nonce_delete'      => wp_create_nonce( 'skyhshoso_delete_product' ),
                'nonce_get'         => wp_create_nonce( 'skyhshoso_get_products' ),
                'nonce_cpanel_accounts' => wp_create_nonce( 'skyhshoso_get_cpanel_accounts' ),
                'servers'           => $server_list,
                'products'          => array(),
                'strings'           => array(
                    'creating'       => __( 'Creating product...', 'skyhs-hosting-solution' ),
                    'created'        => __( 'Product created successfully!', 'skyhs-hosting-solution' ),
                    'deleting'       => __( 'Deleting product...', 'skyhs-hosting-solution' ),
                    'deleted'        => __( 'Product deleted successfully.', 'skyhs-hosting-solution' ),
                    'error'          => __( 'Error creating product.', 'skyhs-hosting-solution' ),
                    'fill_required'  => __( 'Please fill in all required fields.', 'skyhs-hosting-solution' ),
                    'no_products'    => __( 'No products created yet. Use the form above to create one.', 'skyhs-hosting-solution' ),
                    'edit'           => __( 'Edit in WooCommerce', 'skyhs-hosting-solution' ),
                    'view'           => __( 'View', 'skyhs-hosting-solution' ),
                    'confirm_delete'  => __( 'Are you sure you want to delete this product?', 'skyhs-hosting-solution' ),
                    'copied'          => __( 'Shortcode copied!', 'skyhs-hosting-solution' ),
                ),
            )
        );
    }

    /**
     * Get existing hosting/domain products for the list table.
     */
    private function get_existing_products( $filter_args = array() ) {
        $limit = isset( $filter_args['limit'] ) ? intval( $filter_args['limit'] ) : 10;
        $page  = isset( $filter_args['paged'] ) ? intval( $filter_args['paged'] ) : 1;
        if ( $page < 1 ) {
            $page = 1;
        }
        $offset = ( $page - 1 ) * $limit;

        $meta_query = array();

        // Product type filter
        if ( ! empty( $filter_args['product_type'] ) ) {
            $meta_query[] = array(
                'key'   => '_skyhshoso_product_type',
                'value' => sanitize_text_field( $filter_args['product_type'] ),
            );
        } else {
            $meta_query[] = array(
                'key'     => '_skyhshoso_product_type',
                'value'   => array( 'skyhshoso_hosting', 'skyhshoso_wp_site' ),
                'compare' => 'IN',
            );
        }

        // Payment filter
        if ( ! empty( $filter_args['payment'] ) ) {
            if ( 'subscription' === $filter_args['payment'] ) {
                $meta_query[] = array(
                    'key'   => '_skyhshoso_is_subscription',
                    'value' => 'yes',
                );
            } elseif ( 'one-time' === $filter_args['payment'] ) {
                $meta_query[] = array(
                    'relation' => 'OR',
                    array(
                        'key'     => '_skyhshoso_is_subscription',
                        'compare' => 'NOT EXISTS',
                    ),
                    array(
                        'key'     => '_skyhshoso_is_subscription',
                        'value'   => 'yes',
                        'compare' => '!=',
                    ),
                );
            }
        }

        $args = array(
            'post_type'      => 'product',
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'meta_query'     => $meta_query,
            'orderby'        => 'date',
            'order'          => 'DESC',
        );

        if ( ! empty( $filter_args['search'] ) ) {
            $args['s'] = sanitize_text_field( $filter_args['search'] );
        }

        $query = new WP_Query( $args );
        $posts = $query->posts;
        $list  = array();

        foreach ( $posts as $post ) {
            $product = wc_get_product( $post->ID );
            if ( ! $product ) {
                continue;
            }
            $type      = get_post_meta( $post->ID, '_skyhshoso_product_type', true );
            $is_sub    = get_post_meta( $post->ID, '_skyhshoso_is_subscription', true );
            $server_id = get_post_meta( $post->ID, '_skyhshoso_server_id', true );
            $plan      = get_post_meta( $post->ID, '_skyhshoso_hosting_plan', true );
            $features  = get_post_meta( $post->ID, '_skyhshoso_hosting_features', true );
            $server_title = '';
            if ( $server_id ) {
                $server = get_post( $server_id );
                $server_title = $server ? $server->post_title : '';
            }

            $is_variable = $product->is_type( 'variable' );
            $structure   = $is_variable ? 'variable' : 'simple';
            $payment     = 'yes' === $is_sub ? 'subscription' : 'one-time';

            $type_label = __( 'Domain', 'skyhs-hosting-solution' );
            if ( 'skyhshoso_hosting' === $type ) {
                $type_label = __( 'Hosting', 'skyhs-hosting-solution' );
            } elseif ( 'skyhshoso_wp_site' === $type ) {
                $type_label = __( 'WordPress Site', 'skyhs-hosting-solution' );
            }

            $item = array(
                'id'           => $post->ID,
                'name'         => $product->get_name(),
                'type'         => $type,
                'structure'    => $structure,
                'payment'      => $payment,
                'server_id'    => $server_id ? intval( $server_id ) : 0,
                'hosting_plan' => $plan ?: '',
                'features'     => $features ?: '',
                'price'        => $product->get_regular_price(),
                'variations'   => array(),
                // Display fields
                'type_label'   => $type_label,
                'price_display' => wp_strip_all_tags( wc_price( $product->get_price() ) ),
                'is_sub'       => 'yes' === $is_sub,
                'server'       => $server_title,
                'plan'         => $plan,
                'is_variable'  => $is_variable,
                // WP Site specific fields
                'wp_host_user' => get_post_meta( $post->ID, '_skyhshoso_wp_host_user', true ) ?: '',
                'wp_storage'   => get_post_meta( $post->ID, '_skyhshoso_wp_storage', true ) ?: '500',
                'wp_memory'    => get_post_meta( $post->ID, '_skyhshoso_wp_memory', true ) ?: '64M',
            );

            // Simple sub meta
            if ( ! $is_variable ) {
                $item['sub_price']    = $product->get_regular_price();
                $item['sub_period']   = get_post_meta( $post->ID, '_skyhshoso_billing_period', true );
                $item['sub_interval'] = get_post_meta( $post->ID, '_skyhshoso_billing_interval', true );
            }

            // Variable children
            if ( $is_variable ) {
                $children = $product->get_children();
                foreach ( $children as $child_id ) {
                    $variation = wc_get_product( $child_id );
                    if ( ! $variation ) {
                        continue;
                    }

                    // Get the Plan attribute value
                    $attrs = $variation->get_attributes();
                    $plan_name = '';
                    if ( ! empty( $attrs ) ) {
                        $plan_name = reset( $attrs );
                    }

                    $item['variations'][] = array(
                        'name'         => $plan_name ?: '',
                        'price'        => $variation->get_regular_price(),
                        'period'       => get_post_meta( $child_id, '_skyhshoso_billing_period', true ) ?: 'month',
                        'interval'     => get_post_meta( $child_id, '_skyhshoso_billing_interval', true ) ?: 1,
                        'hosting_plan' => get_post_meta( $child_id, '_skyhshoso_hosting_plan', true ) ?: '',
                        'features'     => get_post_meta( $child_id, '_skyhshoso_hosting_features', true ) ?: '',
                        // WP Site specific fields
                        'wp_host_user' => get_post_meta( $child_id, '_skyhshoso_wp_host_user', true ) ?: '',
                        'wp_storage'   => get_post_meta( $child_id, '_skyhshoso_wp_storage', true ) ?: '',
                        'wp_memory'    => get_post_meta( $child_id, '_skyhshoso_wp_memory', true ) ?: '',
                    );
                }
            }

            $list[] = $item;
        }

        return array(
            'products'      => $list,
            'total_records' => intval( $query->found_posts ),
            'total_pages'   => intval( $query->max_num_pages ),
            'current_page'  => intval( $page ),
        );
    }

    /**
     * Render the products admin page.
     */
    public function render_page() {
        $servers = get_posts( array(
            'post_type'      => 'skyhshoso_server',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );
        ?>
        <div class="wrap skyhshoso-hm-wrap">
            <h1><?php esc_html_e( 'Products', 'skyhs-hosting-solution' ); ?></h1>
            <p><?php esc_html_e( 'Create hosting products your customers can purchase. No WooCommerce knowledge needed.', 'skyhs-hosting-solution' ); ?></p>

            <div id="skyhshoso-pm-notice" class="notice" style="display:none;"></div>

            <?php if ( empty( $servers ) ) : ?>
                <div class="skyhshoso-hm-empty" style="margin:24px 0;">
                    <p><?php esc_html_e( 'No servers found. Create a server first before adding products.', 'skyhs-hosting-solution' ); ?></p>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=skyhshoso-servers' ) ); ?>" class="button button-primary" style="margin-top:8px;">
                        <?php esc_html_e( 'Add Server', 'skyhs-hosting-solution' ); ?>
                    </a>
                </div>
                <?php $this->render_product_list(); ?>
                <?php return; ?>
            <?php endif; ?>

            <div id="skyhshoso-hm-app">

                <!-- Create/Edit Form -->
                <div class="skyhshoso-hm-form-panel" id="skyhshoso-pm-form-panel" style="display:none;">
                    <div class="skyhshoso-hm-form-header">
                        <h2 id="skyhshoso-pm-form-title"><?php esc_html_e( 'Create New Product', 'skyhs-hosting-solution' ); ?></h2>
                    </div>

                    <form id="skyhshoso-pm-form" class="skyhshoso-hm-form">

                        <input type="hidden" id="pm_product_id" name="product_id" value="0" />

                        <!-- Section: Basic Info -->
                        <div class="skyhshoso-hm-section">
                            <h3><?php esc_html_e( 'Basic Info', 'skyhs-hosting-solution' ); ?></h3>
                            <div class="skyhshoso-hm-row">
                                <div class="skyhshoso-hm-field">
                                    <label for="pm_product_name"><?php esc_html_e( 'Product Name', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                    <input type="text" id="pm_product_name" name="product_name" class="hm-input" placeholder="<?php esc_attr_e( 'e.g., Web Hosting, Premium Hosting', 'skyhs-hosting-solution' ); ?>" />
                                </div>
                            </div>
                            <div class="skyhshoso-hm-row skyhshoso-hm-row-cols-2">
                                <div class="skyhshoso-hm-field">
                                    <label><?php esc_html_e( 'Structure', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                    <select id="pm_structure" name="structure" class="hm-input">
                                        <option value="simple"><?php esc_html_e( 'Simple (Single Plan)', 'skyhs-hosting-solution' ); ?></option>
                                        <option value="variable"><?php esc_html_e( 'Variable (Multiple Plans)', 'skyhs-hosting-solution' ); ?></option>
                                    </select>
                                    <p class="hm-field-desc"><?php esc_html_e( 'Simple = one price & plan. Variable = multiple plan options customer chooses from.', 'skyhs-hosting-solution' ); ?></p>
                                </div>
                                <div class="skyhshoso-hm-field">
                                    <label><?php esc_html_e( 'Payment', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                    <select id="pm_payment" name="payment" class="hm-input">
                                        <option value="one-time"><?php esc_html_e( 'One-Time Payment', 'skyhs-hosting-solution' ); ?></option>
                                        <option value="subscription" selected><?php esc_html_e( 'Subscription (Recurring)', 'skyhs-hosting-solution' ); ?></option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Section: Simple Pricing & Features -->
                        <div id="pm-section-simple" class="skyhshoso-hm-section">
                            <h3><?php esc_html_e( 'Pricing & Features', 'skyhs-hosting-solution' ); ?></h3>

                            <div id="pm-simple-once-fields">
                                <div class="skyhshoso-hm-row skyhshoso-hm-row-cols-2">
                                    <div class="skyhshoso-hm-field">
                                        <label for="pm_price_once"><?php esc_html_e( 'Price ($)', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                        <input type="number" id="pm_price_once" name="price" class="hm-input" min="0" step="0.01" placeholder="9.99" />
                                    </div>
                                </div>
                            </div>

                            <div id="pm-simple-sub-fields" style="display:none;">
                                <div class="skyhshoso-hm-row skyhshoso-hm-row-cols-3">
                                    <div class="skyhshoso-hm-field">
                                        <label for="pm_sub_price"><?php esc_html_e( 'Amount ($)', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                        <input type="number" id="pm_sub_price" name="sub_price" class="hm-input" min="0" step="0.01" placeholder="9.99" />
                                    </div>
                                    <div class="skyhshoso-hm-field">
                                        <label for="pm_sub_period"><?php esc_html_e( 'Billing Period', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                        <select id="pm_sub_period" name="sub_period" class="hm-input">
                                            <option value="month"><?php esc_html_e( 'Month', 'skyhs-hosting-solution' ); ?></option>
                                            <option value="year"><?php esc_html_e( 'Year', 'skyhs-hosting-solution' ); ?></option>
                                            <option value="week"><?php esc_html_e( 'Week', 'skyhs-hosting-solution' ); ?></option>
                                            <option value="day"><?php esc_html_e( 'Day', 'skyhs-hosting-solution' ); ?></option>
                                        </select>
                                    </div>
                                    <div class="skyhshoso-hm-field">
                                        <label for="pm_sub_interval"><?php esc_html_e( 'Interval (every X)', 'skyhs-hosting-solution' ); ?></label>
                                        <input type="number" id="pm_sub_interval" name="sub_interval" class="hm-input" min="1" step="1" value="1" />
                                    </div>
                                </div>
                            </div>

                            <div class="skyhshoso-hm-row">
                                <div class="skyhshoso-hm-field">
                                    <label for="pm_features"><?php esc_html_e( 'Features (one per line)', 'skyhs-hosting-solution' ); ?></label>
                                    <textarea id="pm_features" name="features" class="hm-input pm-textarea" placeholder="<?php esc_attr_e( 'e.g.&#10;10 GB Storage&#10;Unlimited Bandwidth&#10;Free SSL Certificate&#10;cPanel Control Panel', 'skyhs-hosting-solution' ); ?>"></textarea>
                                </div>
                            </div>

                            <!-- Server & Plan for Simple -->
                            <div class="skyhshoso-hm-row skyhshoso-hm-row-cols-2" style="margin-top:16px;padding-top:16px;border-top:1px solid #f3f4f6;">
                                <div class="skyhshoso-hm-field">
                                    <label for="pm_server_id"><?php esc_html_e( 'Server', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                    <select id="pm_server_id" name="server_id" class="hm-input">
                                        <option value=""><?php esc_html_e( 'Select a server', 'skyhs-hosting-solution' ); ?></option>
                                        <?php foreach ( $servers as $server ) : ?>
                                            <option value="<?php echo esc_attr( $server->ID ); ?>"><?php echo esc_html( $server->post_title ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="skyhshoso-hm-field">
                                    <label for="pm_hosting_plan"><?php esc_html_e( 'Hosting Plan', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                    <select id="pm_hosting_plan" name="hosting_plan" class="hm-input" disabled>
                                        <option value=""><?php esc_html_e( 'Select server first', 'skyhs-hosting-solution' ); ?></option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Section: Variable Plans -->
                        <div id="pm-section-variable" class="skyhshoso-hm-section" style="display:none;">
                            <h3><?php esc_html_e( 'Plans', 'skyhs-hosting-solution' ); ?></h3>
                            <p class="hm-field-desc"><?php esc_html_e( 'Add each plan option. Customers will choose between these when purchasing.', 'skyhs-hosting-solution' ); ?></p>

                            <div class="skyhshoso-hm-row" style="margin-bottom:16px;">
                                <div class="skyhshoso-hm-field">
                                    <label for="pm_variable_server_id"><?php esc_html_e( 'Server', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                    <select id="pm_variable_server_id" class="hm-input">
                                        <option value=""><?php esc_html_e( 'Select a server', 'skyhs-hosting-solution' ); ?></option>
                                        <?php foreach ( $servers as $server ) : ?>
                                            <option value="<?php echo esc_attr( $server->ID ); ?>"><?php echo esc_html( $server->post_title ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="skyhshoso-pm-variations-table-wrap">
                                <table class="skyhshoso-pm-variations-table" id="pm-variations-table">
                                    <thead>
                                        <tr>
                                            <th class="vp-name"><?php esc_html_e( 'Plan Name', 'skyhs-hosting-solution' ); ?></th>
                                            <th class="vp-price"><?php esc_html_e( 'Price ($)', 'skyhs-hosting-solution' ); ?></th>
                                            <th class="vp-period vp-sub-only"><?php esc_html_e( 'Billing Period', 'skyhs-hosting-solution' ); ?></th>
                                            <th class="vp-interval vp-sub-only"><?php esc_html_e( 'Interval', 'skyhs-hosting-solution' ); ?></th>
                                            <th class="vp-plan"><?php esc_html_e( 'Hosting Plan', 'skyhs-hosting-solution' ); ?></th>
                                            <th class="vp-features"><?php esc_html_e( 'Features', 'skyhs-hosting-solution' ); ?></th>
                                            <th class="vp-actions"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="pm-variations-tbody">
                                        <!-- populated by JS -->
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td colspan="7">
                                                <button type="button" id="pm-add-variation" class="button"><?php esc_html_e( '+ Add Plan', 'skyhs-hosting-solution' ); ?></button>
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>

                        <!-- Submit -->
                        <div class="skyhshoso-hm-actions">
                            <div class="skyhshoso-hm-actions-left">
                                <span id="pm-loader" class="spinner" style="float:none;margin:0;"></span>
                            </div>
                            <div class="skyhshoso-hm-actions-right">
                                <button type="button" id="pm-cancel-btn" class="button" style="display:none;">
                                    <?php esc_html_e( 'Cancel', 'skyhs-hosting-solution' ); ?>
                                </button>
                                <button type="submit" id="pm-submit" class="button button-primary button-large">
                                    <?php esc_html_e( 'Create Product', 'skyhs-hosting-solution' ); ?>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- WordPress Site Create/Edit Form -->
                <div class="skyhshoso-hm-form-panel" id="skyhshoso-wps-form-panel" style="display:none;">
                    <div class="skyhshoso-hm-form-header">
                        <h2 id="skyhshoso-wps-form-title"><?php esc_html_e( 'Create New WordPress Site Product', 'skyhs-hosting-solution' ); ?></h2>
                    </div>

                    <form id="skyhshoso-wps-form" class="skyhshoso-hm-form">

                        <input type="hidden" id="wps_product_id" name="product_id" value="0" />

                        <!-- Section: Basic Info -->
                        <div class="skyhshoso-hm-section">
                            <h3><?php esc_html_e( 'Basic Info', 'skyhs-hosting-solution' ); ?></h3>
                            <div class="skyhshoso-hm-row">
                                <div class="skyhshoso-hm-field">
                                    <label for="wps_product_name"><?php esc_html_e( 'Product Name', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                    <input type="text" id="wps_product_name" name="product_name" class="hm-input" placeholder="<?php esc_attr_e( 'e.g., WordPress Site Starter, WP Pro', 'skyhs-hosting-solution' ); ?>" />
                                </div>
                            </div>
                            <div class="skyhshoso-hm-row skyhshoso-hm-row-cols-2">
                                <div class="skyhshoso-hm-field">
                                    <label><?php esc_html_e( 'Structure', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                    <select id="wps_structure" name="structure" class="hm-input">
                                        <option value="simple"><?php esc_html_e( 'Simple (Single Plan)', 'skyhs-hosting-solution' ); ?></option>
                                        <option value="variable"><?php esc_html_e( 'Variable (Multiple Plans)', 'skyhs-hosting-solution' ); ?></option>
                                    </select>
                                    <p class="hm-field-desc"><?php esc_html_e( 'Simple = one price & configuration. Variable = multiple plan options customer chooses from.', 'skyhs-hosting-solution' ); ?></p>
                                </div>
                                <div class="skyhshoso-hm-field">
                                    <label><?php esc_html_e( 'Payment', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                    <select id="wps_payment" name="payment" class="hm-input">
                                        <option value="one-time"><?php esc_html_e( 'One-Time Payment', 'skyhs-hosting-solution' ); ?></option>
                                        <option value="subscription" selected><?php esc_html_e( 'Subscription (Recurring)', 'skyhs-hosting-solution' ); ?></option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Section: Simple Pricing & Features -->
                        <div id="wps-section-simple" class="skyhshoso-hm-section">
                            <h3><?php esc_html_e( 'Pricing & Configuration', 'skyhs-hosting-solution' ); ?></h3>

                            <div id="wps-simple-once-fields">
                                <div class="skyhshoso-hm-row skyhshoso-hm-row-cols-2">
                                    <div class="skyhshoso-hm-field">
                                        <label for="wps_price_once"><?php esc_html_e( 'Price ($)', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                        <input type="number" id="wps_price_once" name="price" class="hm-input" min="0" step="0.01" placeholder="9.99" />
                                    </div>
                                </div>
                            </div>

                            <div id="wps-simple-sub-fields" style="display:none;">
                                <div class="skyhshoso-hm-row skyhshoso-hm-row-cols-3">
                                    <div class="skyhshoso-hm-field">
                                        <label for="wps_sub_price"><?php esc_html_e( 'Amount ($)', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                        <input type="number" id="wps_sub_price" name="sub_price" class="hm-input" min="0" step="0.01" placeholder="9.99" />
                                    </div>
                                    <div class="skyhshoso-hm-field">
                                        <label for="wps_sub_period"><?php esc_html_e( 'Billing Period', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                        <select id="wps_sub_period" name="sub_period" class="hm-input">
                                            <option value="month"><?php esc_html_e( 'Month', 'skyhs-hosting-solution' ); ?></option>
                                            <option value="year"><?php esc_html_e( 'Year', 'skyhs-hosting-solution' ); ?></option>
                                            <option value="week"><?php esc_html_e( 'Week', 'skyhs-hosting-solution' ); ?></option>
                                            <option value="day"><?php esc_html_e( 'Day', 'skyhs-hosting-solution' ); ?></option>
                                        </select>
                                    </div>
                                    <div class="skyhshoso-hm-field">
                                        <label for="wps_sub_interval"><?php esc_html_e( 'Interval (every X)', 'skyhs-hosting-solution' ); ?></label>
                                        <input type="number" id="wps_sub_interval" name="sub_interval" class="hm-input" min="1" step="1" value="1" />
                                    </div>
                                </div>
                            </div>

                            <!-- Server selection for Simple -->
                            <div class="skyhshoso-hm-row skyhshoso-hm-row-cols-2" style="margin-top:16px;padding-top:16px;border-top:1px solid #f3f4f6;">
                                <div class="skyhshoso-hm-field">
                                    <label for="wps_server_id"><?php esc_html_e( 'Server', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                    <select id="wps_server_id" name="server_id" class="hm-input">
                                        <option value=""><?php esc_html_e( 'Select a server', 'skyhs-hosting-solution' ); ?></option>
                                        <?php foreach ( $servers as $server ) : ?>
                                            <option value="<?php echo esc_attr( $server->ID ); ?>"><?php echo esc_html( $server->post_title ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="skyhshoso-hm-field wps-cpanel-search-wrapper" style="position:relative;">
                                    <label for="wps_wp_host_user_search"><?php esc_html_e( 'WordPress Host cPanel', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                    <input type="text" id="wps_wp_host_user_search" class="hm-input wps-wp-host-search" placeholder="<?php esc_attr_e( 'Select server first...', 'skyhs-hosting-solution' ); ?>" autocomplete="off" disabled />
                                    <input type="hidden" id="wps_wp_host_user" name="wp_host_user" value="" />
                                    <div id="wps_cpanel_search_results" class="hm-autocomplete-results wps-cpanel-search-results" style="display:none;position:absolute;z-index:999;width:100%;background:#fff;border:1px solid #ddd;max-height:200px;overflow-y:auto;box-shadow:0 4px 6px rgba(0,0,0,0.1);"></div>
                                </div>
                            </div>

                            <!-- Storage & Memory Limit & Features for Simple -->
                            <div class="skyhshoso-hm-row skyhshoso-hm-row-cols-2" style="margin-top:16px;">
                                <div class="skyhshoso-hm-field">
                                    <label for="wps_wp_storage"><?php esc_html_e( 'Storage Limit (MB)', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                    <input type="number" id="wps_wp_storage" name="wp_storage" class="hm-input" min="1" value="500" />
                                </div>
                                <div class="skyhshoso-hm-field">
                                    <label for="wps_wp_memory"><?php esc_html_e( 'PHP Memory Limit', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                    <select id="wps_wp_memory" name="wp_memory" class="hm-input">
                                        <option value="64M">64M</option>
                                        <option value="128M" selected>128M</option>
                                        <option value="256M">256M</option>
                                        <option value="512M">512M</option>
                                    </select>
                                </div>
                            </div>

                            <div class="skyhshoso-hm-row">
                                <div class="skyhshoso-hm-field">
                                    <label for="wps_features"><?php esc_html_e( 'Features (one per line)', 'skyhs-hosting-solution' ); ?></label>
                                    <textarea id="wps_features" name="features" class="hm-input pm-textarea" placeholder="<?php esc_attr_e( 'e.g.&#10;1 WP Install&#10;Free SSL&#10;Daily Backups', 'skyhs-hosting-solution' ); ?>"></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Section: Variable Plans -->
                        <div id="wps-section-variable" class="skyhshoso-hm-section" style="display:none;">
                            <h3><?php esc_html_e( 'WordPress Site Plans', 'skyhs-hosting-solution' ); ?></h3>
                            <p class="hm-field-desc"><?php esc_html_e( 'Add each plan option. Customers will choose between these when purchasing.', 'skyhs-hosting-solution' ); ?></p>

                            <div class="skyhshoso-hm-row" style="margin-bottom:16px;">
                                <div class="skyhshoso-hm-field">
                                    <label for="wps_variable_server_id"><?php esc_html_e( 'Server', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                    <select id="wps_variable_server_id" class="hm-input">
                                        <option value=""><?php esc_html_e( 'Select a server', 'skyhs-hosting-solution' ); ?></option>
                                        <?php foreach ( $servers as $server ) : ?>
                                            <option value="<?php echo esc_attr( $server->ID ); ?>"><?php echo esc_html( $server->post_title ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="skyhshoso-pm-variations-table-wrap">
                                <table class="skyhshoso-pm-variations-table" id="wps-variations-table">
                                    <thead>
                                        <tr>
                                            <th class="vp-name"><?php esc_html_e( 'Plan Name', 'skyhs-hosting-solution' ); ?></th>
                                            <th class="vp-price"><?php esc_html_e( 'Price ($)', 'skyhs-hosting-solution' ); ?></th>
                                            <th class="vp-period vp-sub-only"><?php esc_html_e( 'Billing Period', 'skyhs-hosting-solution' ); ?></th>
                                            <th class="vp-interval vp-sub-only"><?php esc_html_e( 'Interval', 'skyhs-hosting-solution' ); ?></th>
                                            <th class="vp-wp-host"><?php esc_html_e( 'WordPress Host cPanel', 'skyhs-hosting-solution' ); ?></th>
                                            <th class="vp-storage"><?php esc_html_e( 'Storage (MB)', 'skyhs-hosting-solution' ); ?></th>
                                            <th class="vp-memory"><?php esc_html_e( 'PHP Memory', 'skyhs-hosting-solution' ); ?></th>
                                            <th class="vp-features"><?php esc_html_e( 'Features', 'skyhs-hosting-solution' ); ?></th>
                                            <th class="vp-actions"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="wps-variations-tbody">
                                        <!-- populated by JS -->
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td colspan="9">
                                                <button type="button" id="wps-add-variation" class="button"><?php esc_html_e( '+ Add Plan', 'skyhs-hosting-solution' ); ?></button>
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>

                        <!-- Submit -->
                        <div class="skyhshoso-hm-actions">
                            <div class="skyhshoso-hm-actions-left">
                                <span id="wps-loader" class="spinner" style="float:none;margin:0;"></span>
                            </div>
                            <div class="skyhshoso-hm-actions-right">
                                <button type="button" id="wps-cancel-btn" class="button" style="display:none;">
                                    <?php esc_html_e( 'Cancel', 'skyhs-hosting-solution' ); ?>
                                </button>
                                <button type="submit" id="wps-submit" class="button button-primary button-large">
                                    <?php esc_html_e( 'Create Product', 'skyhs-hosting-solution' ); ?>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Existing Products List -->
                <?php $this->render_product_list(); ?>

            </div>
        </div>
        <?php
    }

    /**
     * Render product list table.
     */
    public function render_product_list() {
        ?>
        <div class="skyhshoso-hm-list-panel">
            <div class="skyhshoso-hm-list-header">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
                    <h2 style="margin:0;">
                        <?php esc_html_e( 'Existing Products', 'skyhs-hosting-solution' ); ?>
                        <span id="pm-list-loader" class="spinner" style="float:none;margin:0 0 0 8px;vertical-align:middle;display:none;"></span>
                    </h2>
                    <div style="display:inline-flex;gap:6px;">
                        <button type="button" id="pm-add-hosting-btn" class="button button-primary">
                            <span class="dashicons dashicons-plus-alt2" style="vertical-align:middle;margin-right:4px;font-size:16px;line-height:1.4;"></span>
                            <?php esc_html_e( 'Add Hosting Product', 'skyhs-hosting-solution' ); ?>
                        </button>
                        <button type="button" id="pm-add-wpsite-btn" class="button button-primary">
                            <span class="dashicons dashicons-plus-alt2" style="vertical-align:middle;margin-right:4px;font-size:16px;line-height:1.4;"></span>
                            <?php esc_html_e( 'Add WP Site Product', 'skyhs-hosting-solution' ); ?>
                        </button>
                    </div>
                </div>
                <div class="skyhshoso-hm-controls" style="display:flex;gap:10px;margin-top:15px;margin-bottom:15px;flex-wrap:wrap;">
                    <input type="text" id="pm-search-input" class="hm-control-input" placeholder="<?php esc_attr_e( 'Search product name...', 'skyhs-hosting-solution' ); ?>" style="flex:1;min-width:200px;" />
                    <select id="pm-type-filter" class="hm-control-select" style="min-width:180px;">
                        <option value=""><?php esc_html_e( 'All Types', 'skyhs-hosting-solution' ); ?></option>
                        <option value="skyhshoso_hosting"><?php esc_html_e( 'Hosting', 'skyhs-hosting-solution' ); ?></option>
                        <option value="skyhshoso_wp_site"><?php esc_html_e( 'WordPress Site', 'skyhs-hosting-solution' ); ?></option>
                    </select>
                    <select id="pm-payment-filter" class="hm-control-select" style="min-width:180px;">
                        <option value=""><?php esc_html_e( 'All Payments', 'skyhs-hosting-solution' ); ?></option>
                        <option value="subscription"><?php esc_html_e( 'Subscription', 'skyhs-hosting-solution' ); ?></option>
                        <option value="one-time"><?php esc_html_e( 'One-Time', 'skyhs-hosting-solution' ); ?></option>
                    </select>
                </div>
            </div>

            <div id="skyhshoso-pm-container">
                <!-- Loaded dynamically via AJAX -->
            </div>

            <div class="skyhshoso-hm-pagination" style="display:flex;justify-content:space-between;align-items:center;margin-top:15px;padding-top:15px;border-top:1px solid #eee;">
                <button type="button" id="pm-prev-page" class="button" disabled>&laquo; <?php esc_html_e( 'Previous', 'skyhs-hosting-solution' ); ?></button>
                <span id="pm-page-info"><?php esc_html_e( 'Page 1 of 1', 'skyhs-hosting-solution' ); ?></span>
                <button type="button" id="pm-next-page" class="button" disabled><?php esc_html_e( 'Next', 'skyhs-hosting-solution' ); ?> &raquo;</button>
            </div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX: fetch server plans
    // -------------------------------------------------------------------------

    public function ajax_fetch_server_plans() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_fetch_server_plans' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'skyhs-hosting-solution' ) ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'skyhs-hosting-solution' ) ) );
        }

        $server_id = isset( $_POST['server_id'] ) ? intval( $_POST['server_id'] ) : 0;
        if ( ! $server_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid server.', 'skyhs-hosting-solution' ) ) );
        }

        $plans = get_post_meta( $server_id, '_skyhshoso_whm_default_package_names', true );
        $plans = is_array( $plans ) ? $plans : array();

        // Also try to sync fresh from WHM if possible
        $whm_user = get_post_meta( $server_id, '_skyhshoso_whm_user_id', true );
        $whm_token = get_post_meta( $server_id, '_skyhshoso_whm_token', true );
        $whm_host = get_post_meta( $server_id, '_skyhshoso_whm_host', true );

        if ( $whm_user && $whm_token && $whm_host && empty( $plans ) ) {
            require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-whm-integration.php';
            $whm = new SkyHSHOSO_WHM_Integration( $whm_user, $whm_token, $whm_host );
            $whm->save_packages( $server_id );
            $plans = get_post_meta( $server_id, '_skyhshoso_whm_default_package_names', true );
            $plans = is_array( $plans ) ? $plans : array();
        }

        $formatted = array();
        foreach ( $plans as $pkg ) {
            $formatted[ $pkg ] = ucwords( str_replace( '_', ' ', $pkg ) );
        }

        wp_send_json_success( array( 'plans' => $formatted ) );
    }

    // -------------------------------------------------------------------------
    // AJAX: create product
    // -------------------------------------------------------------------------

    public function ajax_create_product() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_create_product' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'skyhs-hosting-solution' ) ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'skyhs-hosting-solution' ) ) );
        }

        $product_name   = isset( $_POST['product_name'] ) ? sanitize_text_field( wp_unslash( $_POST['product_name'] ) ) : '';
        $structure      = isset( $_POST['structure'] ) ? sanitize_text_field( wp_unslash( $_POST['structure'] ) ) : 'simple';
        $payment        = isset( $_POST['payment'] ) ? sanitize_text_field( wp_unslash( $_POST['payment'] ) ) : 'subscription';
        $is_update      = isset( $_POST['product_id'] ) && intval( $_POST['product_id'] ) > 0;
        $existing_id    = $is_update ? intval( $_POST['product_id'] ) : 0;
        $pricing_model  = $structure . '-' . $payment;

        if ( empty( $product_name ) ) {
            wp_send_json_error( array( 'message' => __( 'Product name is required.', 'skyhs-hosting-solution' ) ) );
        }

        $skyhs_type = isset( $_POST['product_type'] ) ? sanitize_text_field( wp_unslash( $_POST['product_type'] ) ) : 'skyhshoso_hosting';

        // Common fields
        $server_id    = isset( $_POST['server_id'] ) ? intval( $_POST['server_id'] ) : 0;
        $hosting_plan = isset( $_POST['hosting_plan'] ) ? sanitize_text_field( wp_unslash( $_POST['hosting_plan'] ) ) : '';
        $features     = isset( $_POST['features'] ) ? sanitize_textarea_field( wp_unslash( $_POST['features'] ) ) : '';

        // Extract pricing/billing values for simple product
        $pricing_data = array();
        if ( 'simple' === $structure ) {
            if ( 'subscription' === $payment ) {
                $pricing_data['price']        = isset( $_POST['sub_price'] ) ? floatval( $_POST['sub_price'] ) : 0.0;
                $pricing_data['period']       = isset( $_POST['sub_period'] ) ? sanitize_text_field( wp_unslash( $_POST['sub_period'] ) ) : 'month';
                $pricing_data['interval']     = isset( $_POST['sub_interval'] ) ? max( 1, intval( $_POST['sub_interval'] ) ) : 1;
                $pricing_data['trial_length'] = isset( $_POST['trial_length'] ) ? intval( $_POST['trial_length'] ) : 0;
                $pricing_data['trial_period'] = isset( $_POST['trial_period'] ) ? sanitize_text_field( wp_unslash( $_POST['trial_period'] ) ) : 'day';
            } else {
                $pricing_data['price']        = isset( $_POST['price'] ) ? floatval( $_POST['price'] ) : 0.0;
            }
        }

        // Extract variations for variable product
        $variations_data = array();
        if ( 'variable' === $structure ) {
            if ( isset( $_POST['variations'] ) && is_array( $_POST['variations'] ) ) {
                $variations_data = wp_unslash( $_POST['variations'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            }
        }

        // Extract WP Site specific fields if simple
        $wp_data = array();
        if ( 'skyhshoso_wp_site' === $skyhs_type ) {
            $wp_data['wp_host_user'] = isset( $_POST['wp_host_user'] ) ? sanitize_text_field( wp_unslash( $_POST['wp_host_user'] ) ) : '';
            $wp_data['wp_storage']   = isset( $_POST['wp_storage'] ) ? intval( $_POST['wp_storage'] ) : 500;
            $wp_data['wp_memory']    = isset( $_POST['wp_memory'] ) ? sanitize_text_field( wp_unslash( $_POST['wp_memory'] ) ) : '128M';
        }

        try {
            switch ( $pricing_model ) {
                case 'simple-one-time':
                    $product_id = $this->create_simple_product( $product_name, $skyhs_type, $server_id, $hosting_plan, $features, false, $pricing_data, $existing_id, $wp_data );
                    break;
                case 'simple-subscription':
                    $product_id = $this->create_simple_product( $product_name, $skyhs_type, $server_id, $hosting_plan, $features, true, $pricing_data, $existing_id, $wp_data );
                    break;
                case 'variable-one-time':
                case 'variable-subscription':
                    $product_id = $this->create_variable_product( $product_name, $skyhs_type, $payment, $server_id, $variations_data, $existing_id );
                    break;
                default:
                    wp_send_json_error( array( 'message' => __( 'Invalid pricing model.', 'skyhs-hosting-solution' ) ) );
            }

            if ( is_wp_error( $product_id ) ) {
                wp_send_json_error( array( 'message' => $product_id->get_error_message() ) );
            }

            $edit_url  = admin_url( 'post.php?post=' . $product_id . '&action=edit' );
            $msg       = $is_update ? __( 'Product updated successfully!', 'skyhs-hosting-solution' ) : __( 'Product created successfully!', 'skyhs-hosting-solution' );

            wp_send_json_success( array(
                'message'    => $msg,
                'product_id' => $product_id,
                'edit_url'   => $edit_url,
            ) );

        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }

    // -------------------------------------------------------------------------
    // AJAX: get products
    // -------------------------------------------------------------------------

    public function ajax_get_products() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_get_products' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'skyhs-hosting-solution' ) ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'skyhs-hosting-solution' ) ) );
        }

        $search       = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
        $product_type = isset( $_POST['product_type'] ) ? sanitize_text_field( wp_unslash( $_POST['product_type'] ) ) : '';
        $payment      = isset( $_POST['payment'] ) ? sanitize_text_field( wp_unslash( $_POST['payment'] ) ) : '';
        $paged        = isset( $_POST['paged'] ) ? intval( $_POST['paged'] ) : 1;
        $limit        = isset( $_POST['limit'] ) ? intval( $_POST['limit'] ) : 10;

        $filter_args = array(
            'search'       => $search,
            'product_type' => $product_type,
            'payment'      => $payment,
            'paged'        => $paged,
            'limit'        => $limit,
        );

        $result = $this->get_existing_products( $filter_args );

        wp_send_json_success( $result );
    }

    // -------------------------------------------------------------------------
    // AJAX: delete product
    // -------------------------------------------------------------------------

    public function ajax_delete_product() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_delete_product' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'skyhs-hosting-solution' ) ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'skyhs-hosting-solution' ) ) );
        }

        $product_id = isset( $_POST['product_id'] ) ? intval( $_POST['product_id'] ) : 0;
        if ( ! $product_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid product ID.', 'skyhs-hosting-solution' ) ) );
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            wp_send_json_error( array( 'message' => __( 'Product not found.', 'skyhs-hosting-solution' ) ) );
        }

        $product->delete( true );
        wp_send_json_success( array( 'message' => __( 'Product deleted successfully.', 'skyhs-hosting-solution' ) ) );
    }

    /**
     * Create or update a simple (or subscription) product.
     */
    private function create_simple_product( $name, $skyhs_type, $server_id, $hosting_plan, $features, $is_subscription, $pricing_data = array(), $product_id = 0, $wp_data = array() ) {
        if ( $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product || $product->is_type( 'variable' ) ) {
                return new WP_Error( 'invalid', __( 'Product not found or wrong type.', 'skyhs-hosting-solution' ) );
            }
        } else {
            $product = new WC_Product_Simple();
            $product->set_status( 'publish' );
            $product->set_catalog_visibility( 'visible' );
            $product->set_virtual( true );
        }

        $product->set_name( $name );

        if ( $is_subscription ) {
            $price      = isset( $pricing_data['price'] ) ? floatval( $pricing_data['price'] ) : 0.0;
            $period     = isset( $pricing_data['period'] ) ? sanitize_text_field( $pricing_data['period'] ) : 'month';
            $interval   = isset( $pricing_data['interval'] ) ? max( 1, intval( $pricing_data['interval'] ) ) : 1;
            $trial_len  = isset( $pricing_data['trial_length'] ) ? intval( $pricing_data['trial_length'] ) : 0;
            $trial_per  = isset( $pricing_data['trial_period'] ) ? sanitize_text_field( $pricing_data['trial_period'] ) : 'day';
        } else {
            $price = isset( $pricing_data['price'] ) ? floatval( $pricing_data['price'] ) : 0.0;
        }

        $product->set_regular_price( $price );
        $product->save();

        $pid = $product->get_id();

        // Save SkyHS meta
        update_post_meta( $pid, '_skyhshoso_product_type', $skyhs_type );
        update_post_meta( $pid, '_skyhshoso_is_subscription', $is_subscription ? 'yes' : '' );
        if ( $server_id ) {
            update_post_meta( $pid, '_skyhshoso_server_id', $server_id );
        }
        if ( $hosting_plan ) {
            update_post_meta( $pid, '_skyhshoso_hosting_plan', $hosting_plan );
        }
        if ( $features ) {
            update_post_meta( $pid, '_skyhshoso_hosting_features', $features );
        }

        if ( 'skyhshoso_wp_site' === $skyhs_type ) {
            $wp_host_user = isset( $wp_data['wp_host_user'] ) ? sanitize_text_field( $wp_data['wp_host_user'] ) : '';
            $wp_storage   = isset( $wp_data['wp_storage'] ) ? intval( $wp_data['wp_storage'] ) : 500;
            $wp_memory    = isset( $wp_data['wp_memory'] ) ? sanitize_text_field( $wp_data['wp_memory'] ) : '128M';

            update_post_meta( $pid, '_skyhshoso_wp_host_user', $wp_host_user );
            update_post_meta( $pid, '_skyhshoso_wp_storage', $wp_storage );
            update_post_meta( $pid, '_skyhshoso_wp_memory', $wp_memory );
        }

        if ( $is_subscription ) {
            update_post_meta( $pid, '_skyhshoso_billing_period', $period );
            update_post_meta( $pid, '_skyhshoso_billing_interval', $interval );
            // Only set trial on new products (form lacks trial fields)
            if ( ! $product_id ) {
                update_post_meta( $pid, '_skyhshoso_trial_length', $trial_len );
                update_post_meta( $pid, '_skyhshoso_trial_period', $trial_per );
            }
        }

        return $pid;
    }

    /**
     * Create or update a variable product with multiple plan options.
     */
    private function create_variable_product( $name, $skyhs_type, $payment, $server_id, $variations_data = array(), $product_id = 0 ) {
        if ( empty( $variations_data ) || ! is_array( $variations_data ) ) {
            return new WP_Error( 'no_variations', __( 'At least one plan is required.', 'skyhs-hosting-solution' ) );
        }

        $raw_variations = $variations_data;
        $is_subscription = 'subscription' === $payment;

        // Sanitize variations
        $variations = array();
        foreach ( $raw_variations as $v ) {
            $plan_name   = isset( $v['name'] ) ? sanitize_text_field( $v['name'] ) : '';
            $price       = isset( $v['price'] ) ? floatval( $v['price'] ) : 0.0;
            $period      = isset( $v['period'] ) ? sanitize_text_field( $v['period'] ) : 'month';
            $interval    = isset( $v['interval'] ) ? max( 1, intval( $v['interval'] ) ) : 1;
            $hplan       = isset( $v['hosting_plan'] ) ? sanitize_text_field( $v['hosting_plan'] ) : '';
            $features    = isset( $v['features'] ) ? sanitize_textarea_field( $v['features'] ) : '';
            $wp_host_user = isset( $v['wp_host_user'] ) ? sanitize_text_field( $v['wp_host_user'] ) : '';
            $wp_storage   = isset( $v['wp_storage'] ) ? intval( $v['wp_storage'] ) : 500;
            $wp_memory    = isset( $v['wp_memory'] ) ? sanitize_text_field( $v['wp_memory'] ) : '128M';

            if ( empty( $plan_name ) ) {
                continue;
            }

            $variations[] = array(
                'name'          => $plan_name,
                'price'         => $price,
                'period'        => $period,
                'interval'      => $interval,
                'hosting_plan'  => $hplan,
                'features'      => $features,
                'wp_host_user'  => $wp_host_user,
                'wp_storage'    => $wp_storage,
                'wp_memory'     => $wp_memory,
            );
        }

        if ( count( $variations ) < 1 ) {
            return new WP_Error( 'no_variations', __( 'No valid plans provided.', 'skyhs-hosting-solution' ) );
        }

        // 1. Create "Plan" attribute
        $attr_label = __( 'Plan', 'skyhs-hosting-solution' );
        $attr_name  = sanitize_title( $attr_label );

        $plan_names = array();
        foreach ( $variations as $v ) {
            $plan_names[] = $v['name'];
        }

        $attribute = new WC_Product_Attribute();
        $attribute->set_id( 0 );
        $attribute->set_name( $attr_label );
        $attribute->set_options( array_unique( $plan_names ) );
        $attribute->set_visible( true );
        $attribute->set_variation( true );

        // 2. Create or update parent variable product
        if ( $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product || ! $product->is_type( 'variable' ) ) {
                return new WP_Error( 'invalid', __( 'Product not found or wrong type.', 'skyhs-hosting-solution' ) );
            }
            $product->set_attributes( array( $attribute ) );

            // Delete existing variations
            foreach ( $product->get_children() as $child_id ) {
                wp_delete_post( $child_id, true );
            }
        } else {
            $product = new WC_Product_Variable();
            $product->set_status( 'publish' );
            $product->set_catalog_visibility( 'visible' );
            $product->set_virtual( true );
            $product->set_attributes( array( $attribute ) );
        }
        $product->set_name( $name );
        $product->save();

        $pid = $product->get_id();

        // Save SkyHS meta on parent
        update_post_meta( $pid, '_skyhshoso_product_type', $skyhs_type );
        update_post_meta( $pid, '_skyhshoso_is_subscription', $is_subscription ? 'yes' : '' );
        if ( $server_id ) {
            update_post_meta( $pid, '_skyhshoso_server_id', $server_id );
        }

        if ( ! empty( $variations ) && 'skyhshoso_wp_site' === $skyhs_type ) {
            $first_v = $variations[0];
            update_post_meta( $pid, '_skyhshoso_wp_host_user', $first_v['wp_host_user'] );
            update_post_meta( $pid, '_skyhshoso_wp_storage', $first_v['wp_storage'] );
            update_post_meta( $pid, '_skyhshoso_wp_memory', $first_v['wp_memory'] );
        }

        // 3. Create each variation
        foreach ( $variations as $v ) {
            $slug = sanitize_title( $v['name'] );

            $variation = new WC_Product_Variation();
            $variation->set_parent_id( $pid );
            $variation->set_attributes( array( $attr_name => $slug ) );
            $variation->set_regular_price( $v['price'] );
            $variation->set_virtual( true );
            $variation->save();

            $vid = $variation->get_id();

            // Per-variation meta
            if ( $v['hosting_plan'] ) {
                update_post_meta( $vid, '_skyhshoso_hosting_plan', $v['hosting_plan'] );
            }
            if ( $v['features'] ) {
                update_post_meta( $vid, '_skyhshoso_hosting_features', $v['features'] );
            }
            if ( $is_subscription ) {
                update_post_meta( $vid, '_skyhshoso_billing_period', $v['period'] );
                update_post_meta( $vid, '_skyhshoso_billing_interval', $v['interval'] );
                update_post_meta( $vid, '_skyhshoso_trial_length', 0 );
                update_post_meta( $vid, '_skyhshoso_trial_period', 'day' );
            }

            if ( 'skyhshoso_wp_site' === $skyhs_type ) {
                update_post_meta( $vid, '_skyhshoso_wp_host_user', $v['wp_host_user'] );
                update_post_meta( $vid, '_skyhshoso_wp_storage', $v['wp_storage'] );
                update_post_meta( $vid, '_skyhshoso_wp_memory', $v['wp_memory'] );
            }
        }

        // Force WooCommerce to sync
        WC_Product_Variable::sync( $pid );

        return $pid;
    }
}

SkyHSHOSO_Product_Manager::instance();
