<?php
/**
 * SkyHS WP Site Manager
 *
 * Admin management page for WordPress site installations.
 *
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

class SkyHSHOSO_WP_Site_Manager {

    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'wp_ajax_skyhshoso_wp_admin_get_sites',       array( $this, 'ajax_get_sites' ) );
        add_action( 'wp_ajax_skyhshoso_wp_admin_get_details',     array( $this, 'ajax_get_details' ) );
        add_action( 'wp_ajax_skyhshoso_wp_admin_save',            array( $this, 'ajax_save_wp_site' ) );
        add_action( 'wp_ajax_skyhshoso_wp_admin_terminate',       array( $this, 'ajax_terminate' ) );
        add_action( 'wp_ajax_skyhshoso_wp_admin_suspend',         array( $this, 'ajax_suspend' ) );
        add_action( 'wp_ajax_skyhshoso_wp_admin_unsuspend',       array( $this, 'ajax_unsuspend' ) );
        add_action( 'wp_ajax_skyhshoso_wp_admin_delete',          array( $this, 'ajax_delete' ) );
    }

    public function enqueue_scripts( $hook ) {
        $page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
        if ( false === strpos( $hook, 'skyhshoso-wp-sites' ) && 'skyhshoso-wp-sites' !== $page ) {
            return;
        }

        wp_enqueue_style(
            'skyhshoso-hosting-manager',
            SKYHSHOSO_PLUGIN_URL . 'assets/css/hosting-manager.css',
            array(),
            SKYHSHOSO_VERSION
        );

        wp_enqueue_script(
            'skyhshoso-wp-site-manager',
            SKYHSHOSO_PLUGIN_URL . 'assets/js/wp-site-manager.js',
            array( 'jquery' ),
            SKYHSHOSO_VERSION,
            true
        );

        wp_localize_script(
            'skyhshoso-wp-site-manager',
            'skyhshoso_wpm',
            array(
                'ajax_url'          => admin_url( 'admin-ajax.php' ),
                'nonce'             => wp_create_nonce( 'skyhshoso_wp_admin' ),
                'nonce_save'        => wp_create_nonce( 'skyhshoso_wp_admin_save' ),
                'nonce_search_subs' => wp_create_nonce( 'skyhshoso_search_subscriptions' ),
                'nonce_search_users' => wp_create_nonce( 'skyhshoso_search_users' ),
                'nonce_search_products' => wp_create_nonce( 'skyhshoso_search_products' ),
                'product_type'      => 'wp_site',
                'strings'           => array(
                    'loading'          => __( 'Loading...', 'skyhs-hosting-solution' ),
                    'no_sites'         => __( 'No WordPress sites found matching the criteria.', 'skyhs-hosting-solution' ),
                    'error'            => __( 'An error occurred.', 'skyhs-hosting-solution' ),
                    'saving'           => __( 'Saving WordPress site...', 'skyhs-hosting-solution' ),
                    'saved'            => __( 'WordPress site saved successfully!', 'skyhs-hosting-solution' ),
                    'fill_fields'      => __( 'Please select a product and owner.', 'skyhs-hosting-solution' ),
                    'confirm_delete'   => __( 'Are you sure you want to delete this WordPress site record? The server files will NOT be removed.', 'skyhs-hosting-solution' ),
                    'confirm_terminate' => __( 'Are you sure? This will permanently delete the WordPress site, database, and addon domain.', 'skyhs-hosting-solution' ),
                    'suspending'       => __( 'Suspending...', 'skyhs-hosting-solution' ),
                    'reactivating'     => __( 'Reactivating...', 'skyhs-hosting-solution' ),
                    'terminating'      => __( 'Terminating...', 'skyhs-hosting-solution' ),
                    'deleting'         => __( 'Deleting...', 'skyhs-hosting-solution' ),
                ),
            )
        );
    }

    public function render_page() {
        ?>
        <div class="wrap skyhshoso-hm-wrap">
            <h1><?php esc_html_e( 'WordPress Sites', 'skyhs-hosting-solution' ); ?></h1>
            <p><?php esc_html_e( 'Manage WordPress site installations across your servers.', 'skyhs-hosting-solution' ); ?></p>

            <div id="skyhshoso-wpm-notice" class="notice" style="display:none;"></div>

            <div id="skyhshoso-wpm-app">
                <div class="skyhshoso-hm-form-panel" id="skyhshoso-wpm-form-panel" style="display:none;">
                    <div class="skyhshoso-hm-form-header">
                        <h2 id="skyhshoso-wpm-form-title"><?php esc_html_e( 'Create WordPress Site', 'skyhs-hosting-solution' ); ?></h2>
                    </div>

                    <form id="skyhshoso-wpm-form" class="skyhshoso-hm-form">
                        <input type="hidden" id="wpm_wp_site_id" name="wp_site_id" value="0" />

                        <div class="skyhshoso-hm-section">
                            <h3><?php esc_html_e( 'Site Details', 'skyhs-hosting-solution' ); ?></h3>

                            <div class="skyhshoso-hm-row skyhshoso-hm-row-cols-4">
                                <div class="skyhshoso-hm-field">
                                    <label for="wpm_title"><?php esc_html_e( 'Site Label / Title', 'skyhs-hosting-solution' ); ?></label>
                                    <input type="text" id="wpm_title" name="title" class="hm-input" placeholder="<?php esc_attr_e( 'Auto-fills from product name', 'skyhs-hosting-solution' ); ?>" />
                                </div>

                                <div class="skyhshoso-hm-field">
                                    <label for="wpm_product_search"><?php esc_html_e( 'WP Site Product', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                    <div class="hm-search-product-wrapper" style="position:relative;">
                                        <input type="text" id="wpm_product_search" class="hm-input" placeholder="<?php esc_attr_e( 'Type product name...', 'skyhs-hosting-solution' ); ?>" autocomplete="off" />
                                        <input type="hidden" id="wpm_product_id" name="product_id" value="" />
                                        <div id="wpm_product_search_results" class="hm-autocomplete-results" style="display:none;position:absolute;z-index:999;width:100%;background:#fff;border:1px solid #ddd;max-height:200px;overflow-y:auto;box-shadow:0 4px 6px rgba(0,0,0,0.1);"></div>
                                    </div>
                                </div>

                                <div class="skyhshoso-hm-field">
                                    <label for="wpm_owner_search"><?php esc_html_e( 'Site Owner', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                    <div class="hm-search-owner-wrapper" style="position:relative;">
                                        <input type="text" id="wpm_owner_search" class="hm-input" placeholder="<?php esc_attr_e( 'Type username, email or name...', 'skyhs-hosting-solution' ); ?>" autocomplete="off" />
                                        <input type="hidden" id="wpm_owner_id" name="owner_id" value="" />
                                        <div id="wpm_owner_search_results" class="hm-autocomplete-results" style="display:none;position:absolute;z-index:999;width:100%;background:#fff;border:1px solid #ddd;max-height:150px;overflow-y:auto;box-shadow:0 4px 6px rgba(0,0,0,0.1);"></div>
                                    </div>
                                </div>

                                <div class="skyhshoso-hm-field">
                                    <label for="wpm_domain"><?php esc_html_e( 'Domain Name', 'skyhs-hosting-solution' ); ?></label>
                                    <input type="text" id="wpm_domain" name="domain" class="hm-input" placeholder="<?php esc_attr_e( 'e.g., mysite.com', 'skyhs-hosting-solution' ); ?>" />
                                </div>
                            </div>
                        </div>

                        <div class="skyhshoso-hm-section">
                            <h3><?php esc_html_e( 'Billing Subscription', 'skyhs-hosting-solution' ); ?></h3>
                            <div id="wpm-current-sub-info" style="display:none;margin-bottom:12px;"></div>
                            <div class="skyhshoso-hm-row skyhshoso-hm-row-cols-2">
                                <div class="skyhshoso-hm-field">
                                    <label for="wpm_sub_action"><?php esc_html_e( 'Subscription Action', 'skyhs-hosting-solution' ); ?></label>
                                    <select id="wpm_sub_action" name="sub_action" class="hm-input">
                                        <option value="keep"><?php esc_html_e( 'Keep existing subscription', 'skyhs-hosting-solution' ); ?></option>
                                        <option value="create"><?php esc_html_e( 'Create new subscription on-the-go', 'skyhs-hosting-solution' ); ?></option>
                                        <option value="link"><?php esc_html_e( 'Link to an existing subscription', 'skyhs-hosting-solution' ); ?></option>
                                    </select>
                                </div>
                                <div class="skyhshoso-hm-field" id="wpm_existing_sub_container" style="display:none;">
                                    <label for="wpm_existing_sub_search"><?php esc_html_e( 'Existing Subscription', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                    <div class="hm-search-sub-wrapper" style="position:relative;">
                                        <input type="text" id="wpm_existing_sub_search" class="hm-input" placeholder="<?php esc_attr_e( 'Type Sub ID or Owner Email to search...', 'skyhs-hosting-solution' ); ?>" autocomplete="off" />
                                        <input type="hidden" id="wpm_existing_sub_id" name="existing_sub_id" value="" />
                                        <div id="wpm-sub-search-results" class="hm-autocomplete-results" style="display:none;position:absolute;z-index:999;width:100%;background:#fff;border:1px solid #ddd;max-height:200px;overflow-y:auto;box-shadow:0 4px 6px rgba(0,0,0,0.1);"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="skyhshoso-hm-actions">
                            <div class="skyhshoso-hm-actions-left">
                                <span id="wpm-loader" class="spinner" style="float:none;margin:0;"></span>
                            </div>
                            <div class="skyhshoso-hm-actions-right">
                                <button type="button" id="wpm-cancel-btn" class="button" style="display:none;">
                                    <?php esc_html_e( 'Cancel Edit', 'skyhs-hosting-solution' ); ?>
                                </button>
                                <button type="submit" id="wpm-submit" class="button button-primary button-large">
                                    <?php esc_html_e( 'Save WordPress Site', 'skyhs-hosting-solution' ); ?>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="skyhshoso-hm-list-panel">
                    <div class="skyhshoso-hm-list-header">
                        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
                            <h2 style="margin:0;"><?php esc_html_e( 'WordPress Site Deployments', 'skyhs-hosting-solution' ); ?></h2>
                            <button type="button" id="wpm-add-wp-site-btn" class="button button-primary">
                                <span class="dashicons dashicons-plus-alt2" style="vertical-align:middle;margin-right:4px;font-size:16px;line-height:1.4;"></span>
                                <?php esc_html_e( 'Add WordPress Site', 'skyhs-hosting-solution' ); ?>
                            </button>
                        </div>
                        <div class="skyhshoso-hm-controls" style="display:flex;gap:10px;margin-bottom:15px;flex-wrap:wrap;">
                            <input type="text" id="wpm-search-input" class="hm-control-input" placeholder="<?php esc_attr_e( 'Search title, domain, cPanel user...', 'skyhs-hosting-solution' ); ?>" style="flex:1;min-width:200px;" />
                            <select id="wpm-status-filter" class="hm-control-select" style="min-width:180px;">
                                <option value=""><?php esc_html_e( 'All Statuses', 'skyhs-hosting-solution' ); ?></option>
                                <option value="active"><?php esc_html_e( 'Active', 'skyhs-hosting-solution' ); ?></option>
                                <option value="pending"><?php esc_html_e( 'Pending', 'skyhs-hosting-solution' ); ?></option>
                                <option value="on-hold"><?php esc_html_e( 'On Hold', 'skyhs-hosting-solution' ); ?></option>
                                <option value="cancelled"><?php esc_html_e( 'Cancelled', 'skyhs-hosting-solution' ); ?></option>
                            </select>
                        </div>
                    </div>

                    <div id="skyhshoso-wpm-container">
                        </div>

                    <div class="skyhshoso-hm-pagination" style="display:flex;justify-content:space-between;align-items:center;margin-top:15px;padding-top:15px;border-top:1px solid #eee;">
                        <button type="button" id="wpm-prev-page" class="button" disabled>&laquo; <?php esc_html_e( 'Previous', 'skyhs-hosting-solution' ); ?></button>
                        <span id="wpm-page-info"><?php esc_html_e( 'Page 1 of 1', 'skyhs-hosting-solution' ); ?></span>
                        <button type="button" id="wpm-next-page" class="button" disabled><?php esc_html_e( 'Next', 'skyhs-hosting-solution' ); ?> &raquo;</button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function ajax_get_sites() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_wp_admin' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        global $wpdb;
        $table_posts    = $wpdb->posts;
        $table_postmeta = $wpdb->postmeta;

        $search       = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
        $status_filter = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';

        $where = array( "p.post_type = 'skyhshoso_wp_site'" );
        $join  = '';

        if ( ! empty( $search ) ) {
            $search_like = '%' . $wpdb->esc_like( $search ) . '%';
            $join .= " LEFT JOIN {$table_postmeta} pm_domain ON (p.ID = pm_domain.post_id AND pm_domain.meta_key = 'skyhshoso_wp_domain') ";
            $join .= " LEFT JOIN {$table_postmeta} pm_cpanel ON (p.ID = pm_cpanel.post_id AND pm_cpanel.meta_key = 'skyhshoso_wp_cpanel_user') ";

            $where[] = $wpdb->prepare(
                "(p.post_title LIKE %s OR pm_domain.meta_value LIKE %s OR pm_cpanel.meta_value LIKE %s)",
                $search_like, $search_like, $search_like
            );
        }

        if ( ! empty( $status_filter ) ) {
            $join .= " LEFT JOIN {$table_postmeta} pm_sub ON (p.ID = pm_sub.post_id AND pm_sub.meta_key = 'skyhshoso_subscription_id') ";
            $table_subs = $wpdb->prefix . 'skyhshoso_subscriptions';
            $join .= " LEFT JOIN {$table_subs} s ON pm_sub.meta_value = s.id ";

            if ( 'no_sub' === $status_filter ) {
                $where[] = "(pm_sub.meta_value IS NULL OR pm_sub.meta_value = '' OR s.status IS NULL)";
            } else {
                $where[] = $wpdb->prepare( "s.status = %s", $status_filter );
            }
        }

        $where_sql = implode( ' AND ', $where );
        $join_sql  = $join;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $total_records = $wpdb->get_var( "SELECT COUNT(DISTINCT p.ID) FROM {$table_posts} p {$join_sql} WHERE {$where_sql}" );
        // phpcs:enable

        $limit       = isset( $_POST['limit'] ) ? intval( $_POST['limit'] ) : 10;
        $page        = isset( $_POST['paged'] ) ? intval( $_POST['paged'] ) : 1;
        if ( $page < 1 ) {
            $page = 1;
        }
        $offset     = ( $page - 1 ) * $limit;
        $total_pages = ceil( $total_records / $limit );
        if ( $total_pages < 1 ) {
            $total_pages = 1;
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $query_sql = "SELECT DISTINCT p.* FROM {$table_posts} p {$join_sql} WHERE {$where_sql} ORDER BY p.post_date DESC LIMIT %d OFFSET %d";
        $posts     = $wpdb->get_results( $wpdb->prepare( $query_sql, $limit, $offset ) );
        // phpcs:enable

        $sites = array();
        foreach ( $posts as $post ) {
            $server_id   = get_post_meta( $post->ID, 'skyhshoso_server_id', true );
            $server_name = $server_id ? get_the_title( $server_id ) : '—';
            $cpanel_user = get_post_meta( $post->ID, 'skyhshoso_wp_cpanel_user', true );
            $domain      = get_post_meta( $post->ID, 'skyhshoso_wp_domain', true );
            $provisioned = get_post_meta( $post->ID, '_skyhshoso_wp_provisioned', true );
            $site_url    = get_post_meta( $post->ID, '_skyhshoso_wp_site_url', true );

            $sub_id = get_post_meta( $post->ID, 'skyhshoso_subscription_id', true );
            $status = 'pending';
            $sub_status_label = __( 'Pending', 'skyhs-hosting-solution' );
            if ( ! empty( $sub_id ) ) {
                $sub = skyhshoso_get_subscription( $sub_id );
                if ( $sub ) {
                    $status = $sub->get_status();
                    $sub_status_label = skyhshoso_get_subscription_status_name( $status );
                }
            }

            $sites[] = array(
                'id'              => $post->ID,
                // THE FIX: Decode HTML entities so "cPanel &#8211; Hosting" becomes "cPanel - Hosting"
                'title'           => html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' ), 
                'domain'          => $domain ?: '',
                'server'          => $server_name,
                'server_id'       => $server_id ?: '',
                'cpanel_user'     => $cpanel_user ?: '',
                'status'          => $status,
                'sub_status_label' => $sub_status_label,
                'provisioned'     => ! empty( $provisioned ),
                'site_url'        => $site_url ?: '',
                'subscription_id' => $sub_id ?: '',
            );
        }

        wp_send_json_success( array(
            'sites'        => $sites,
            'total_records' => intval( $total_records ),
            'total_pages'  => intval( $total_pages ),
            'current_page' => intval( $page ),
        ) );
    }

    public function ajax_get_details() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_wp_admin' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        $wp_site_id = isset( $_POST['wp_site_id'] ) ? intval( $_POST['wp_site_id'] ) : 0;
        if ( ! $wp_site_id ) {
            wp_send_json_error( array( 'message' => 'Invalid ID.' ) );
        }

        $post = get_post( $wp_site_id );
        if ( ! $post || 'skyhshoso_wp_site' !== $post->post_type ) {
            wp_send_json_error( array( 'message' => 'WP site not found.' ) );
        }

        $product_id   = get_post_meta( $wp_site_id, '_skyhshoso_hosting_product_id', true );
        $variation_id = get_post_meta( $wp_site_id, '_skyhshoso_variation_id', true );
        $server_id    = get_post_meta( $wp_site_id, 'skyhshoso_server_id', true );
        $domain       = get_post_meta( $wp_site_id, 'skyhshoso_wp_domain', true );
        $sub_id       = get_post_meta( $wp_site_id, 'skyhshoso_subscription_id', true );

        $owner = get_userdata( $post->post_author );
        $owner_name = $owner ? $owner->display_name . ' (' . $owner->user_login . ')' : '';

        // Build product title for edit pre-fill
        $product_title = '';
        if ( $product_id ) {
            $prod = wc_get_product( $product_id );
            if ( $prod ) {
                $product_title = $prod->get_name();
                if ( $variation_id ) {
                    $var = wc_get_product( $variation_id );
                    if ( $var ) {
                        $var_attrs = $var->get_variation_attributes();
                        $plan_name = '';
                        foreach ( $var_attrs as $attr_key => $attr_val ) {
                            $tax = str_replace( 'attribute_', '', $attr_key );
                            if ( taxonomy_exists( $tax ) ) {
                                $term_obj = get_term_by( 'slug', $attr_val, $tax );
                                $plan_name = $term_obj ? $term_obj->name : $attr_val;
                            } else {
                                $plan_name = $attr_val;
                            }
                            break;
                        }
                        $price = wp_strip_all_tags( wc_price( $var->get_price() ) );
                        $product_title .= ' » ' . $plan_name . ' — ' . $price;
                    }
                } else {
                    $price = wp_strip_all_tags( wc_price( $prod->get_price() ) );
                    $product_title .= ' — ' . $price;
                }
            }
        }

        $data = array(
            'id'              => $post->ID,
            // THE FIX: Decode HTML entities here as well
            'title'           => html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' ),
            'product_id'      => $product_id ?: '',
            'variation_id'    => $variation_id ?: '',
            'product_title'   => html_entity_decode( $product_title, ENT_QUOTES, 'UTF-8' ),
            'owner_id'        => $post->post_author,
            'owner_name'      => $owner_name,
            'server_id'       => $server_id ?: '',
            'domain'          => $domain ?: '',
            'subscription_id' => $sub_id ?: '',
        );

        wp_send_json_success( $data );
    }

    public function ajax_save_wp_site() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_wp_admin_save' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'skyhs-hosting-solution' ) ) );
        }
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'skyhs-hosting-solution' ) ) );
        }

        $wp_site_id = isset( $_POST['wp_site_id'] ) ? intval( $_POST['wp_site_id'] ) : 0;
        $title      = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        $product_id = isset( $_POST['product_id'] ) ? sanitize_text_field( wp_unslash( $_POST['product_id'] ) ) : '';
        $owner_id   = isset( $_POST['owner_id'] ) ? intval( $_POST['owner_id'] ) : 0;
        $domain     = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';

		if ( empty( $product_id ) || ! $owner_id ) {
			SkyHSHOSO_Logger::error( 'WP site save failed: product and owner fields are required', array( 'source' => 'wp_site_manager' ) );
			wp_send_json_error( array( 'message' => __( 'Product and Owner fields are required.', 'skyhs-hosting-solution' ) ) );
		}

		// Split product ID / variation ID
		$prod_id = $product_id;
		$var_id  = 0;
		if ( strpos( $product_id, '|' ) !== false ) {
			list( $prod_id, $var_id ) = explode( '|', $product_id );
		}

		$product = wc_get_product( $var_id ?: $prod_id );
		if ( ! $product ) {
			SkyHSHOSO_Logger::error( 'WP site save failed: WooCommerce product not found (ID: ' . ( $var_id ?: $prod_id ) . ')', array( 'source' => 'wp_site_manager' ) );
			wp_send_json_error( array( 'message' => __( 'WooCommerce Product not found.', 'skyhs-hosting-solution' ) ) );
		}

        // Set default title
        if ( empty( $title ) ) {
            $title = $product->get_name();
        }

        $post_data = array(
            'post_type'   => 'skyhshoso_wp_site',
            'post_title'  => $title,
            'post_author' => $owner_id,
            'post_status' => 'publish',
        );

        if ( $wp_site_id ) {
            $post_data['ID'] = $wp_site_id;
            $result_id = wp_update_post( $post_data );
        } else {
            $result_id = wp_insert_post( $post_data );
        }

		if ( is_wp_error( $result_id ) ) {
			SkyHSHOSO_Logger::error( 'WP site post save failed: ' . $result_id->get_error_message(), array( 'source' => 'wp_site_manager' ) );
			wp_send_json_error( array( 'message' => $result_id->get_error_message() ) );
		}

        // Save metadata
        update_post_meta( $result_id, '_skyhshoso_hosting_product_id', $prod_id );
        if ( $var_id ) {
            update_post_meta( $result_id, '_skyhshoso_variation_id', $var_id );
        } else {
            delete_post_meta( $result_id, '_skyhshoso_variation_id' );
        }

        // Copy server ID from product
        $server_id = get_post_meta( $prod_id, '_skyhshoso_server_id', true );
        if ( ! empty( $server_id ) ) {
            update_post_meta( $result_id, 'skyhshoso_server_id', $server_id );
        }

        // Copy cPanel user from product
        $cpanel_user = get_post_meta( $prod_id, '_skyhshoso_wp_host_user', true );
        if ( $var_id ) {
            $var_cpanel_user = get_post_meta( $var_id, '_skyhshoso_wp_host_user', true );
            if ( ! empty( $var_cpanel_user ) ) {
                $cpanel_user = $var_cpanel_user;
            }
        }
        if ( ! empty( $cpanel_user ) ) {
            update_post_meta( $result_id, 'skyhshoso_wp_cpanel_user', $cpanel_user );
        }

        // Copy storage and memory from product
        $storage = get_post_meta( $var_id ?: $prod_id, '_skyhshoso_wp_storage', true ) ?: 500;
        $memory  = get_post_meta( $var_id ?: $prod_id, '_skyhshoso_wp_memory', true ) ?: '64M';
        if ( empty( $storage ) && $var_id ) {
            $storage = get_post_meta( $prod_id, '_skyhshoso_wp_storage', true ) ?: 500;
        }
        if ( empty( $memory ) && $var_id ) {
            $memory = get_post_meta( $prod_id, '_skyhshoso_wp_memory', true ) ?: '64M';
        }
        update_post_meta( $result_id, '_skyhshoso_wp_storage', $storage );
        update_post_meta( $result_id, '_skyhshoso_wp_memory', $memory );

        if ( ! empty( $domain ) ) {
            update_post_meta( $result_id, 'skyhshoso_wp_domain', $domain );
        }

        // Handle subscription action
        $sub_creation_msg = '';
        $sub_action       = isset( $_POST['sub_action'] ) ? sanitize_text_field( wp_unslash( $_POST['sub_action'] ) ) : 'create';
        $existing_sub_id  = isset( $_POST['existing_sub_id'] ) ? intval( $_POST['existing_sub_id'] ) : 0;

        if ( 'keep' === $sub_action ) {
            $existing_sub = get_post_meta( $result_id, 'skyhshoso_subscription_id', true );
            if ( ! empty( $existing_sub ) ) {
                $sub_creation_msg = sprintf( __( ' Keeping existing subscription #%d.', 'skyhs-hosting-solution' ), $existing_sub );
            }
		} elseif ( 'link' === $sub_action ) {
			if ( ! empty( $existing_sub_id ) ) {
				$sub = skyhshoso_get_subscription( $existing_sub_id );
				if ( ! $sub ) {
					SkyHSHOSO_Logger::error( 'WP site subscription link failed: subscription #' . $existing_sub_id . ' does not exist', array( 'source' => 'wp_site_manager' ) );
					wp_send_json_error( array( 'message' => __( 'The selected subscription ID does not exist.', 'skyhs-hosting-solution' ) ) );
				}
                update_post_meta( $result_id, 'skyhshoso_subscription_id', $existing_sub_id );
                $sub_creation_msg = sprintf( __( ' Linked to existing subscription #%d.', 'skyhs-hosting-solution' ), $existing_sub_id );
            } else {
                wp_send_json_error( array( 'message' => __( 'Please search and select an existing subscription to link.', 'skyhs-hosting-solution' ) ) );
            }
        } else {
            // Create new subscription synchronously
            delete_post_meta( $result_id, 'skyhshoso_subscription_id' );
            delete_post_meta( $result_id, '_skyhshoso_subscription_creation_error' );
            update_post_meta( $result_id, '_skyhshoso_subscription_creation_pending', 'yes' );

            $created = $this->process_subscription_creation( $result_id );
            if ( $created ) {
                $new_sub_id = get_post_meta( $result_id, 'skyhshoso_subscription_id', true );
                $sub_creation_msg = sprintf( __( ' Billing subscription #%d successfully created.', 'skyhs-hosting-solution' ), $new_sub_id );
            } else {
                $error = get_post_meta( $result_id, '_skyhshoso_subscription_creation_error', true );
                $sub_creation_msg = __( ' Billing subscription creation failed.', 'skyhs-hosting-solution' );
                if ( $error ) {
                    $sub_creation_msg .= ' ' . $error;
                }
            }
        }

        // Auto-generate UUID
        if ( class_exists( 'SkyHSHOSO_UUID' ) ) {
            SkyHSHOSO_UUID::set_post_uuid( $result_id );
        }

        wp_send_json_success( array(
            'message'  => __( 'WordPress site saved successfully.', 'skyhs-hosting-solution' ) . $sub_creation_msg,
            'site_id'  => $result_id,
        ) );
    }

    public function ajax_search_subscriptions() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_search_subscriptions' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security verification failed.', 'skyhs-hosting-solution' ) ) );
        }

        $term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
        if ( empty( $term ) ) {
            wp_send_json_success( array() );
        }

        global $wpdb;
        $table_subs  = $wpdb->prefix . 'skyhshoso_subscriptions';
        $table_users = $wpdb->users;

        $where  = array();
        $params = array();

        if ( is_numeric( $term ) ) {
            $where[]  = "s.id = %d";
            $params[] = intval( $term );
        }

        $like    = '%' . $wpdb->esc_like( $term ) . '%';
        $where[] = "u.user_email LIKE %s OR u.display_name LIKE %s OR u.user_login LIKE %s";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;

        $where_sql = implode( ' OR ', $where );
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $sql = "SELECT s.id, s.amount, s.billing_period, s.billing_interval, s.status, u.display_name, u.user_email 
                FROM {$table_subs} s
                LEFT JOIN {$table_users} u ON s.user_id = u.ID
                WHERE {$where_sql}
                ORDER BY s.id DESC
                LIMIT 15";
        $results = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

        $list = array();
        foreach ( $results as $row ) {
            $label = sprintf(
                '#%d - %s (%s) - %s/%s [%s]',
                $row->id,
                $row->display_name,
                $row->user_email,
                wp_strip_all_tags( wc_price( $row->amount ) ),
                $row->billing_period,
                ucfirst( $row->status )
            );
            $list[] = array(
                'id'    => $row->id,
                'label' => $label,
            );
        }

        wp_send_json_success( $list );
    }

    public function ajax_delete() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_wp_admin' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        $wp_site_id = isset( $_POST['wp_site_id'] ) ? intval( $_POST['wp_site_id'] ) : 0;
        if ( ! $wp_site_id ) {
            wp_send_json_error( array( 'message' => 'Invalid ID.' ) );
        }

        $post = get_post( $wp_site_id );
        if ( ! $post || 'skyhshoso_wp_site' !== $post->post_type ) {
            wp_send_json_error( array( 'message' => 'WP site not found.' ) );
        }

        wp_delete_post( $wp_site_id, true );

        if ( class_exists( 'SkyHSHOSO_Activity_Log' ) ) {
            SkyHSHOSO_Activity_Log::log( 'wp_site', 'WP site record deleted: #' . $wp_site_id, 'info' );
        }

        wp_send_json_success( array( 'message' => 'WordPress site record deleted.' ) );
    }

    private function get_wp_manager( $wp_site_id ) {
        $server_id   = get_post_meta( $wp_site_id, 'skyhshoso_server_id', true );
        $cpanel_user = get_post_meta( $wp_site_id, 'skyhshoso_wp_cpanel_user', true );

        if ( ! $server_id || ! $cpanel_user ) {
            return null;
        }

        $whm_user  = get_post_meta( $server_id, '_skyhshoso_whm_user_id', true );
        $whm_token = get_post_meta( $server_id, '_skyhshoso_whm_token', true );
        $whm_host  = get_post_meta( $server_id, '_skyhshoso_whm_host', true );

        if ( empty( $whm_user ) || empty( $whm_token ) || empty( $whm_host ) ) {
            return null;
        }

        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-skyhshoso-wordpress-manager.php';
        return new SkyHSHOSO_WordPress_Manager( $whm_user, $whm_token, $whm_host, $cpanel_user );
    }

    public function ajax_terminate() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_wp_admin' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        $wp_site_id = isset( $_POST['wp_site_id'] ) ? intval( $_POST['wp_site_id'] ) : 0;
        if ( ! $wp_site_id ) {
            wp_send_json_error( array( 'message' => 'Invalid ID.' ) );
        }

        $manager = $this->get_wp_manager( $wp_site_id );
        if ( ! $manager ) {
            wp_delete_post( $wp_site_id, true );
            wp_send_json_success( array( 'message' => 'WP site deleted (no WHM connection).' ) );
        }

        $doc_root = get_post_meta( $wp_site_id, '_skyhshoso_wp_doc_root', true );
        $db_name  = get_post_meta( $wp_site_id, 'skyhshoso_wp_db_name', true );
        $db_user  = get_post_meta( $wp_site_id, 'skyhshoso_wp_db_user', true );
        $domain   = get_post_meta( $wp_site_id, 'skyhshoso_wp_domain', true );

        $manager->delete_wp_site( $doc_root, $db_name, $db_user, $domain );

        wp_delete_post( $wp_site_id, true );

        if ( class_exists( 'SkyHSHOSO_Activity_Log' ) ) {
            SkyHSHOSO_Activity_Log::log( 'wp_site', 'WP Site terminated and deleted: #' . $wp_site_id, 'info' );
        }

        wp_send_json_success( array( 'message' => 'WordPress site terminated.' ) );
    }

    public function ajax_suspend() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_wp_admin' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        $wp_site_id = isset( $_POST['wp_site_id'] ) ? intval( $_POST['wp_site_id'] ) : 0;
        $manager    = $this->get_wp_manager( $wp_site_id );
        if ( ! $manager ) {
            wp_send_json_error( array( 'message' => 'Could not connect to WHM.' ) );
        }

        $doc_root = get_post_meta( $wp_site_id, '_skyhshoso_wp_doc_root', true );
        if ( $manager->suspend_wp_site( $doc_root ) ) {
            wp_send_json_success( array( 'message' => 'WordPress site suspended.' ) );
        } else {
            wp_send_json_error( array( 'message' => 'Failed to suspend.' ) );
        }
    }

    public function ajax_unsuspend() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_wp_admin' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        $wp_site_id = isset( $_POST['wp_site_id'] ) ? intval( $_POST['wp_site_id'] ) : 0;
        $manager    = $this->get_wp_manager( $wp_site_id );
        if ( ! $manager ) {
            wp_send_json_error( array( 'message' => 'Could not connect to WHM.' ) );
        }

        $doc_root = get_post_meta( $wp_site_id, '_skyhshoso_wp_doc_root', true );
        if ( $manager->unsuspend_wp_site( $doc_root ) ) {
            wp_send_json_success( array( 'message' => 'WordPress site reactivated.' ) );
        } else {
            wp_send_json_error( array( 'message' => 'Failed to reactivate.' ) );
        }
    }

    /**
     * Process subscription creation for a WP site post.
     */
    public function process_subscription_creation( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'skyhshoso_wp_site' ) {
            return;
        }

        $subscription_id = get_post_meta( $post_id, 'skyhshoso_subscription_id', true );
        if ( ! empty( $subscription_id ) ) {
            delete_post_meta( $post_id, '_skyhshoso_subscription_creation_pending' );
            return;
        }

        try {
            $product_id = get_post_meta( $post_id, '_skyhshoso_hosting_product_id', true );
            $variation_id = get_post_meta( $post_id, '_skyhshoso_variation_id', true );

            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                update_post_meta( $post_id, '_skyhshoso_subscription_creation_error', 'Product not found' );
                return;
            }

            if ( ! $product->is_type( array( 'subscription', 'variable-subscription' ) ) &&
                ! SkyHSHOSO_Subscriptions_Product::is_subscription( $product ) &&
                get_post_meta( $product->get_id(), '_skyhshoso_product_type', true ) !== 'skyhshoso_wp_site' ) {
                update_post_meta( $post_id, '_skyhshoso_subscription_creation_error', 'Product is not a subscription' );
                return;
            }

            $user_id = get_post_field( 'post_author', $post_id );
            $user = get_user_by( 'id', $user_id );
            if ( ! $user ) {
                update_post_meta( $post_id, '_skyhshoso_subscription_creation_error', 'User not found' );
                return;
            }

            $subscription = $this->create_subscription( $user, $product, $post, $variation_id );

            if ( $subscription ) {
                update_post_meta( $post_id, 'skyhshoso_subscription_id', $subscription->get_id() );
                delete_post_meta( $post_id, '_skyhshoso_subscription_creation_pending' );
                delete_post_meta( $post_id, '_skyhshoso_subscription_creation_error' );
                $subscription->add_order_note( sprintf( __( 'Subscription created automatically from WP site post #%d', 'skyhs-hosting-solution' ), $post->ID ) );
                return true;
            } else {
                update_post_meta( $post_id, '_skyhshoso_subscription_creation_error', 'Failed to create subscription' );
                return false;
            }
        } catch ( Exception $e ) {
            update_post_meta( $post_id, '_skyhshoso_subscription_creation_error', $e->getMessage() );
            return false;
        }
    }

    /**
     * Create a WooCommerce subscription for a WP site.
     */
    private function create_subscription( $user, $product, $post, $variation_id = null ) {
        try {
            $product_to_add = $product;
            if ( $variation_id && $product->is_type( array( 'variable', 'variable-subscription' ) ) ) {
                $variation = wc_get_product( $variation_id );
                if ( $variation ) {
                    $product_to_add = $variation;
                }
            }

            if ( ! SkyHSHOSO_Subscriptions_Product::is_subscription( $product_to_add ) &&
                ! $product_to_add->is_type( array( 'subscription', 'subscription_variation', 'variable-subscription' ) ) &&
                get_post_meta( $product->get_id(), '_skyhshoso_product_type', true ) !== 'skyhshoso_wp_site' ) {
                return false;
            }

            $billing_period   = 'month';
            $billing_interval = 1;

            $product_period   = SkyHSHOSO_Subscriptions_Product::get_period( $product_to_add );
            $product_interval = SkyHSHOSO_Subscriptions_Product::get_interval( $product_to_add );
            if ( ! empty( $product_period ) ) {
                $billing_period = $product_period;
            }
            if ( ! empty( $product_interval ) && $product_interval > 0 ) {
                $billing_interval = $product_interval;
            }

            // Create a parent WooCommerce order so the subscription has a related order
            // for switcher compatibility.
            $parent_order = wc_create_order( array(
                'customer_id' => $user->ID,
                'status'      => 'completed',
                'created_via' => 'hosting_solution',
            ) );

            if ( is_wp_error( $parent_order ) ) {
                $parent_order = false;
            }

            if ( $parent_order ) {
                $order_item_id = $parent_order->add_product( $product_to_add );
                if ( $order_item_id ) {
                    $parent_order->calculate_totals();
                }
                $parent_order->save();
            }

            $subscription = skyhshoso_create_subscription( array(
                'customer_id'      => $user->ID,
                'product_id'       => $product_to_add->is_type( array( 'variation', 'subscription_variation' ) ) ? $product_to_add->get_parent_id() : $product_to_add->get_id(),
                'variation_id'     => $product_to_add->is_type( array( 'variation', 'subscription_variation' ) ) ? $product_to_add->get_id() : 0,
                'amount'           => (float) $product_to_add->get_price(),
                'order_id'         => $parent_order ? $parent_order->get_id() : 0,
                'billing_period'   => $billing_period,
                'billing_interval' => $billing_interval,
                'start_date'       => gmdate( 'Y-m-d H:i:s' ),
                'created_via'      => 'hosting_solution',
            ) );

            if ( is_wp_error( $subscription ) ) {
                if ( $parent_order ) {
                    $parent_order->delete( true );
                }
                return false;
            }

            $item_id = $subscription->add_product( $product_to_add );
            if ( ! $item_id ) {
                $subscription->delete( true );
                if ( $parent_order ) {
                    $parent_order->delete( true );
                }
                return false;
            }

            $address_fields = array(
                'first_name', 'last_name', 'company', 'address_1',
                'address_2', 'city', 'state', 'postcode', 'country',
                'email', 'phone',
            );

            foreach ( $address_fields as $field ) {
                $billing_field = 'billing_' . $field;
                $value = get_user_meta( $user->ID, $billing_field, true );
                if ( ! empty( $value ) ) {
                    $subscription->update_meta_data( '_' . $billing_field, $value );
                }
            }

            $subscription->calculate_totals();
            $subscription->update_status( 'active' );
            $subscription->set_requires_manual_renewal( true );
            $subscription->save();

            return $subscription;
        } catch ( Exception $e ) {
            return false;
        }
    }
}

SkyHSHOSO_WP_Site_Manager::instance();