<?php
/**
 * SkyHS Hosting Manager
 *
 * Custom admin page for managing hosting accounts.
 * Replaces native CPT screen with a premium, sleek guided UI.
 *
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

class SkyHSHOSO_Hosting_Manager {

    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

        // AJAX handlers
        add_action( 'wp_ajax_skyhshoso_save_hosting', array( $this, 'ajax_save_hosting' ) );
        add_action( 'wp_ajax_skyhshoso_delete_hosting', array( $this, 'ajax_delete_hosting' ) );
        add_action( 'wp_ajax_skyhshoso_quick_sync_hosting', array( $this, 'ajax_quick_sync_hosting' ) );
        add_action( 'wp_ajax_skyhshoso_get_hostings', array( $this, 'ajax_get_hostings' ) );
        add_action( 'wp_ajax_skyhshoso_search_subscriptions', array( $this, 'ajax_search_subscriptions' ) );

        // AJAX: get cached cPanel accounts for a server
        add_action( 'wp_ajax_skyhshoso_get_cpanel_accounts', array( $this, 'ajax_get_cpanel_accounts' ) );

        // AJAX: search users by term
        add_action( 'wp_ajax_skyhshoso_search_users', array( $this, 'ajax_search_users' ) );

        // AJAX: search products by term
        add_action( 'wp_ajax_skyhshoso_search_products', array( $this, 'ajax_search_products' ) );

        // Subscription creation hook (legacy scheduled events)
        add_action( 'create_hosting_subscription', array( $this, 'process_subscription_creation' ) );
    }

    /**
     * Enqueue CSS and JS
     */
    public function enqueue_scripts( $hook ) {
        $page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( false === strpos( $hook, 'skyhshoso-hosting' ) && 'skyhshoso-hosting' !== $page ) {
            return;
        }

        wp_enqueue_style(
            'skyhshoso-hosting-manager',
            SKYHSHOSO_PLUGIN_URL . 'assets/css/hosting-manager.css',
            array(),
            SKYHSHOSO_VERSION
        );

        wp_enqueue_script(
            'skyhshoso-hosting-manager',
            SKYHSHOSO_PLUGIN_URL . 'assets/js/hosting-manager.js',
            array( 'jquery' ),
            SKYHSHOSO_VERSION,
            true
        );

        wp_localize_script(
            'skyhshoso-hosting-manager',
            'skyhshoso_hm',
            array(
                'ajax_url'            => admin_url( 'admin-ajax.php' ),
                'nonce_save'          => wp_create_nonce( 'skyhshoso_save_hosting' ),
                'nonce_cpanel_accounts' => wp_create_nonce( 'skyhshoso_get_cpanel_accounts' ),
                'nonce_delete'        => wp_create_nonce( 'skyhshoso_delete_hosting' ),
                'nonce_sync'          => wp_create_nonce( 'skyhshoso_quick_sync' ),
                'nonce_get'           => wp_create_nonce( 'skyhshoso_get_hostings' ),
                'nonce_search_subs'   => wp_create_nonce( 'skyhshoso_search_subscriptions' ),
                'nonce_search_users'  => wp_create_nonce( 'skyhshoso_search_users' ),
                'nonce_search_products' => wp_create_nonce( 'skyhshoso_search_products' ),
                'product_type'        => 'hosting',
                'strings'             => array(
                    'saving'         => __( 'Saving hosting account...', 'skyhs-hosting-solution' ),
                    'deleting'       => __( 'Deleting hosting account...', 'skyhs-hosting-solution' ),
                    'saved'          => __( 'Hosting saved successfully!', 'skyhs-hosting-solution' ),
                    'deleted'        => __( 'Hosting deleted successfully.', 'skyhs-hosting-solution' ),
                    'error'          => __( 'An error occurred.', 'skyhs-hosting-solution' ),
                    'confirm_delete' => __( 'Are you sure you want to delete this hosting account?', 'skyhs-hosting-solution' ),
                    'fill_fields'    => __( 'Please select a product and owner.', 'skyhs-hosting-solution' ),
                ),
            )
        );
    }

    /**
     * Render management page
     */
    public function render_page() {
        ?>
        <div class="wrap skyhshoso-hm-wrap">
            <h1><?php esc_html_e( 'Hosting Accounts', 'skyhs-hosting-solution' ); ?></h1>
            <p><?php esc_html_e( 'Manage your client hosting accounts, map custom domain names, configure pricing links, and sync active recurring subscriptions from a single admin screen.', 'skyhs-hosting-solution' ); ?></p>

            <div id="skyhshoso-hm-notice" class="notice" style="display:none;"></div>

            <div id="skyhshoso-hm-app">
                <!-- Guided edit/create panel -->
                <div class="skyhshoso-hm-form-panel" id="skyhshoso-hm-form-panel" style="display:none;">
                    <div class="skyhshoso-hm-form-header">
                        <h2 id="skyhshoso-hm-form-title"><?php esc_html_e( 'Create Hosting Account', 'skyhs-hosting-solution' ); ?></h2>
                    </div>

                    <form id="skyhshoso-hm-form" class="skyhshoso-hm-form">
                        <input type="hidden" id="hm_hosting_id" name="hosting_id" value="0" />

                        <div class="skyhshoso-hm-section">
                            <h3><?php esc_html_e( 'Account Details', 'skyhs-hosting-solution' ); ?></h3>

                            <div class="skyhshoso-hm-row skyhshoso-hm-row-cols-3">
                                <div class="skyhshoso-hm-field">
                                    <label for="hm_title"><?php esc_html_e( 'Hosting Label / Title', 'skyhs-hosting-solution' ); ?></label>
                                    <input type="text" id="hm_title" name="title" class="hm-input" placeholder="<?php esc_attr_e( 'Auto-fills from product name', 'skyhs-hosting-solution' ); ?>" />
                                </div>

                                <div class="skyhshoso-hm-field">
                                    <label for="hm_product_search"><?php esc_html_e( 'Hosting Product', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                    <div class="hm-search-product-wrapper" style="position:relative;">
                                        <input type="text" id="hm_product_search" class="hm-input" placeholder="<?php esc_attr_e( 'Type product name...', 'skyhs-hosting-solution' ); ?>" autocomplete="off" />
                                        <input type="hidden" id="hm_product_id" name="product_id" value="" />
                                        <div id="hm_product_search_results" class="hm-autocomplete-results" style="display:none;position:absolute;z-index:999;width:100%;background:#fff;border:1px solid #ddd;max-height:200px;overflow-y:auto;box-shadow:0 4px 6px rgba(0,0,0,0.1);"></div>
                                    </div>
                                </div>

                                <div class="skyhshoso-hm-field">
                                    <label for="hm_owner_search"><?php esc_html_e( 'Hosting Owner', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                    <div class="hm-search-owner-wrapper" style="position:relative;">
                                        <input type="text" id="hm_owner_search" class="hm-input" placeholder="<?php esc_attr_e( 'Type username, email or name...', 'skyhs-hosting-solution' ); ?>" autocomplete="off" />
                                        <input type="hidden" id="hm_owner_id" name="owner_id" value="" />
                                        <div id="hm_owner_search_results" class="hm-autocomplete-results" style="display:none;position:absolute;z-index:999;width:100%;background:#fff;border:1px solid #ddd;max-height:150px;overflow-y:auto;box-shadow:0 4px 6px rgba(0,0,0,0.1);"></div>
                                    </div>
                                </div>

                            </div>
                        </div>

                        <div class="skyhshoso-hm-section">
                            <h3><?php esc_html_e( 'Billing Subscription', 'skyhs-hosting-solution' ); ?></h3>
                            <div id="hm-current-sub-info" style="display:none;margin-bottom:12px;"></div>
                            <div class="skyhshoso-hm-row skyhshoso-hm-row-cols-2">
                                <div class="skyhshoso-hm-field">
                                    <label for="hm_sub_action"><?php esc_html_e( 'Subscription Action', 'skyhs-hosting-solution' ); ?></label>
                                    <select id="hm_sub_action" name="sub_action" class="hm-input">
                                        <option value="keep"><?php esc_html_e( 'Keep existing subscription', 'skyhs-hosting-solution' ); ?></option>
                                        <option value="create"><?php esc_html_e( 'Create new subscription on-the-go', 'skyhs-hosting-solution' ); ?></option>
                                        <option value="link"><?php esc_html_e( 'Link to an existing subscription', 'skyhs-hosting-solution' ); ?></option>
                                    </select>
                                </div>
                                <div class="skyhshoso-hm-field" id="hm_existing_sub_container" style="display:none;">
                                    <label for="hm_existing_sub_search"><?php esc_html_e( 'Existing Subscription', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                    <div class="hm-search-sub-wrapper" style="position:relative;">
                                        <input type="text" id="hm_existing_sub_search" class="hm-input" placeholder="<?php esc_attr_e( 'Type Sub ID or Owner Email to search...', 'skyhs-hosting-solution' ); ?>" autocomplete="off" />
                                        <input type="hidden" id="hm_existing_sub_id" name="existing_sub_id" value="" />
                                        <div id="hm-sub-search-results" class="hm-autocomplete-results" style="display:none;position:absolute;z-index:999;width:100%;background:#fff;border:1px solid #ddd;max-height:200px;overflow-y:auto;box-shadow:0 4px 6px rgba(0,0,0,0.1);"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="skyhshoso-hm-section">
                            <h3><?php esc_html_e( 'cPanel Account', 'skyhs-hosting-solution' ); ?></h3>
                            <div class="skyhshoso-hm-row skyhshoso-hm-row-cols-2">
                                <div class="skyhshoso-hm-field">
                                    <label for="hm_account_source"><?php esc_html_e( 'Account Source', 'skyhs-hosting-solution' ); ?></label>
                                    <select id="hm_account_source" name="account_source" class="hm-input">
                                        <option value="new"><?php esc_html_e( 'Create new cPanel account', 'skyhs-hosting-solution' ); ?></option>
                                        <option value="existing"><?php esc_html_e( 'Connect existing cPanel account', 'skyhs-hosting-solution' ); ?></option>
                                        <option value="none"><?php esc_html_e( 'Create hosting only - no cPanel', 'skyhs-hosting-solution' ); ?></option>
                                    </select>
                                </div>
                                <div class="skyhshoso-hm-field" id="hm_existing_account_container" style="display:none;">
                                    <label for="hm_existing_cpanel_user_search"><?php esc_html_e( 'Existing cPanel Account', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                    <div class="hm-search-sub-wrapper" style="position:relative;">
                                        <input type="text" id="hm_existing_cpanel_user_search" class="hm-input hm-existing-cpanel-search" placeholder="<?php esc_attr_e( 'Type cPanel username to search...', 'skyhs-hosting-solution' ); ?>" autocomplete="off" disabled />
                                        <input type="hidden" id="hm_existing_cpanel_user" name="existing_cpanel_user" value="" />
                                        <div id="hm_existing_cpanel_search_results" class="hm-autocomplete-results" style="display:none;position:absolute;z-index:999;width:100%;background:#fff;border:1px solid #ddd;max-height:150px;overflow-y:auto;box-shadow:0 4px 6px rgba(0,0,0,0.1);"></div>
                                        <p class="sm-field-desc" id="hm-no-accounts-msg" style="display:none;color:#dc2626;font-size:11px;"><?php esc_html_e( 'No cached accounts for this server. Sync accounts in cPanel Sync first.', 'skyhs-hosting-solution' ); ?></p>
                                    </div>
                                </div>
                            </div>
                            <div id="hm_domain_container" style="display:none;margin-top:12px;">
                                <div class="skyhshoso-hm-row skyhshoso-hm-row-cols-1">
                                    <div class="skyhshoso-hm-field">
                                        <label for="hm_domain"><?php esc_html_e( 'Domain Name', 'skyhs-hosting-solution' ); ?></label>
                                        <input type="text" id="hm_domain" name="domain" class="hm-input" placeholder="<?php esc_attr_e( 'e.g., mysite.com', 'skyhs-hosting-solution' ); ?>" />
                                    </div>
                                </div>
                            </div>
                            <div id="hm-cpanel-user-display" style="display:none;margin-top:8px;">
                                <span style="display:inline-flex;align-items:center;gap:6px;padding:6px 10px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;font-size:12px;color:#166534;">
                                    <strong><?php esc_html_e( 'cPanel User:', 'skyhs-hosting-solution' ); ?></strong>
                                    <span id="hm-cpanel-user-value"></span>
                                </span>
                            </div>
                        </div>

                        <div class="skyhshoso-hm-actions">
                            <div class="skyhshoso-hm-actions-left">
                                <span id="hm-loader" class="spinner" style="float:none;margin:0;"></span>
                            </div>
                            <div class="skyhshoso-hm-actions-right">
                                <button type="button" id="hm-cancel-btn" class="button" style="display:none;">
                                    <?php esc_html_e( 'Cancel Edit', 'skyhs-hosting-solution' ); ?>
                                </button>
                                <button type="submit" id="hm-submit" class="button button-primary button-large">
                                    <?php esc_html_e( 'Save Hosting', 'skyhs-hosting-solution' ); ?>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Account listing panel -->
                <div class="skyhshoso-hm-list-panel">
                    <div class="skyhshoso-hm-list-header">
                        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
                            <h2 style="margin:0;"><?php esc_html_e( 'Active Hosting Deployments', 'skyhs-hosting-solution' ); ?></h2>
                            <button type="button" id="hm-add-hosting-btn" class="button button-primary">
                                <span class="dashicons dashicons-plus-alt2" style="vertical-align:middle;margin-right:4px;font-size:16px;line-height:1.4;"></span>
                                <?php esc_html_e( 'Add Hosting', 'skyhs-hosting-solution' ); ?>
                            </button>
                        </div>
                        <div class="skyhshoso-hm-controls" style="display:flex;gap:10px;margin-bottom:15px;flex-wrap:wrap;">
                            <input type="text" id="hm-search-input" class="hm-control-input" placeholder="<?php esc_attr_e( 'Search title, domain, email...', 'skyhs-hosting-solution' ); ?>" style="flex:1;min-width:200px;" />
                            <select id="hm-status-filter" class="hm-control-select" style="min-width:180px;">
                                <option value=""><?php esc_html_e( 'All Billing Statuses', 'skyhs-hosting-solution' ); ?></option>
                                <option value="active"><?php esc_html_e( 'Active', 'skyhs-hosting-solution' ); ?></option>
                                <option value="pending"><?php esc_html_e( 'Pending', 'skyhs-hosting-solution' ); ?></option>
                                <option value="on-hold"><?php esc_html_e( 'On Hold', 'skyhs-hosting-solution' ); ?></option>
                                <option value="cancelled"><?php esc_html_e( 'Cancelled', 'skyhs-hosting-solution' ); ?></option>
                                <option value="no_sub"><?php esc_html_e( 'No Subscription', 'skyhs-hosting-solution' ); ?></option>
                            </select>
                        </div>
                    </div>

                    <div id="skyhshoso-hm-container">
                        <!-- Loaded dynamically via AJAX -->
                    </div>

                    <div class="skyhshoso-hm-pagination" style="display:flex;justify-content:space-between;align-items:center;margin-top:15px;padding-top:15px;border-top:1px solid #eee;">
                        <button type="button" id="hm-prev-page" class="button" disabled>&laquo; <?php esc_html_e( 'Previous', 'skyhs-hosting-solution' ); ?></button>
                        <span id="hm-page-info"><?php esc_html_e( 'Page 1 of 1', 'skyhs-hosting-solution' ); ?></span>
                        <button type="button" id="hm-next-page" class="button" disabled><?php esc_html_e( 'Next', 'skyhs-hosting-solution' ); ?> &raquo;</button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: save hosting account (create or update)
     */
    public function ajax_save_hosting() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_save_hosting' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'skyhs-hosting-solution' ) ) );
        }

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'skyhs-hosting-solution' ) ) );
        }

        $hosting_id = isset( $_POST['hosting_id'] ) ? intval( $_POST['hosting_id'] ) : 0;
        $title      = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        $product_id = isset( $_POST['product_id'] ) ? sanitize_text_field( wp_unslash( $_POST['product_id'] ) ) : '';
        $owner_id   = isset( $_POST['owner_id'] ) ? intval( $_POST['owner_id'] ) : 0;
        $domain     = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';

		if ( empty( $product_id ) || ! $owner_id ) {
			SkyHSHOSO_Logger::error( 'Hosting save failed: product and owner fields are required', array( 'source' => 'hosting_manager' ) );
			wp_send_json_error( array( 'message' => __( 'Product and Owner fields are required.', 'skyhs-hosting-solution' ) ) );
		}

        // Split product ID / variation ID
        $prod_id = $product_id;
        $var_id = 0;
        if ( strpos( $product_id, '|' ) !== false ) {
            list( $prod_id, $var_id ) = explode( '|', $product_id );
        }

		$product = wc_get_product( $var_id ?: $prod_id );
		if ( ! $product ) {
			SkyHSHOSO_Logger::error( 'Hosting save failed: WooCommerce product not found (ID: ' . ( $var_id ?: $prod_id ) . ')', array( 'source' => 'hosting_manager' ) );
			wp_send_json_error( array( 'message' => __( 'WooCommerce Product not found.', 'skyhs-hosting-solution' ) ) );
		}

        // Set default title
        if ( empty( $title ) ) {
            $title = $product->get_name();
        }

        $post_data = array(
            'post_type'   => 'skyhshoso_hosting',
            'post_title'  => $title,
            'post_author' => $owner_id,
            'post_status' => 'publish',
        );

        if ( $hosting_id ) {
            $post_data['ID'] = $hosting_id;
            $result_id = wp_update_post( $post_data );
        } else {
            $result_id = wp_insert_post( $post_data );
        }

		if ( is_wp_error( $result_id ) ) {
			SkyHSHOSO_Logger::error( 'Hosting post save failed: ' . $result_id->get_error_message(), array( 'source' => 'hosting_manager' ) );
			wp_send_json_error( array( 'message' => $result_id->get_error_message() ) );
		}

        // Save metadata
        update_post_meta( $result_id, '_skyhshoso_hosting_product_id', $prod_id );
        if ( $var_id ) {
            update_post_meta( $result_id, '_skyhshoso_variation_id', $var_id );
        } else {
            delete_post_meta( $result_id, '_skyhshoso_variation_id' );
        }

        // Copy server ID & hosting plan from product
        $server_id = get_post_meta( $prod_id, '_skyhshoso_server_id', true );
        $hosting_plan = get_post_meta( $var_id ?: $prod_id, '_skyhshoso_hosting_plan', true );

        if ( ! empty( $server_id ) ) {
            update_post_meta( $result_id, 'skyhshoso_server_id', $server_id );
        }
        if ( ! empty( $hosting_plan ) ) {
            update_post_meta( $result_id, 'skyhshoso_hosting_plan', $hosting_plan );
        }

        // Handle account source
        $account_source = isset( $_POST['account_source'] ) ? sanitize_text_field( wp_unslash( $_POST['account_source'] ) ) : 'new';
        $existing_cpanel_user = isset( $_POST['existing_cpanel_user'] ) ? sanitize_text_field( wp_unslash( $_POST['existing_cpanel_user'] ) ) : '';

        // Auto-assign domain from cached cPanel account when connecting existing
        if ( empty( $domain ) && ! empty( $existing_cpanel_user ) && ! empty( $server_id ) ) {
            $cached_domain = $this->get_cached_cpanel_domain( $server_id, $existing_cpanel_user );
            if ( $cached_domain ) {
                $domain = $cached_domain;
            }
        }

        if ( 'new' === $account_source && ! empty( $domain ) ) {
            update_post_meta( $result_id, 'skyhshoso_hosting_domain', $domain );
        } else {
            delete_post_meta( $result_id, 'skyhshoso_hosting_domain' );
        }

        if ( ! empty( $existing_cpanel_user ) ) {
            update_post_meta( $result_id, 'skyhshoso_hosting_username', $existing_cpanel_user );
            update_post_meta( $result_id, '_skyhshoso_hosting_account_source', 'existing' );
            delete_post_meta( $result_id, '_skyhshoso_hosting_temp_password' );
        } else {
            $is_new = ! get_post_meta( $result_id, 'skyhshoso_hosting_username', true );
            if ( $is_new ) {
                update_post_meta( $result_id, '_skyhshoso_hosting_account_source', $account_source );
                if ( 'none' === $account_source ) {
                    delete_post_meta( $result_id, 'skyhshoso_hosting_username' );
                    delete_post_meta( $result_id, '_skyhshoso_hosting_temp_password' );
                }
            }
        }

        // Trigger manual subscription creation or linking synchronously
        $sub_creation_msg = '';
        $sub_action = isset( $_POST['sub_action'] ) ? sanitize_text_field( wp_unslash( $_POST['sub_action'] ) ) : 'create';
        $existing_sub_id = isset( $_POST['existing_sub_id'] ) ? intval( $_POST['existing_sub_id'] ) : 0;

        if ( 'keep' === $sub_action ) {
            // Keep existing subscription — do nothing
            $existing_sub = get_post_meta( $result_id, 'skyhshoso_subscription_id', true );
            if ( ! empty( $existing_sub ) ) {
                $sub_creation_msg = sprintf( __( ' Keeping existing subscription #%d.', 'skyhs-hosting-solution' ), $existing_sub );
            }
        } elseif ( 'link' === $sub_action ) {
			if ( ! empty( $existing_sub_id ) ) {
				$sub = skyhshoso_get_subscription( $existing_sub_id );
				if ( ! $sub ) {
					SkyHSHOSO_Logger::error( 'Hosting subscription link failed: subscription #' . $existing_sub_id . ' does not exist', array( 'source' => 'hosting_manager' ) );
					wp_send_json_error( array( 'message' => __( 'The selected subscription ID does not exist.', 'skyhs-hosting-solution' ) ) );
				}
                update_post_meta( $result_id, 'skyhshoso_subscription_id', $existing_sub_id );
                delete_post_meta( $result_id, '_skyhshoso_subscription_creation_pending' );
                delete_post_meta( $result_id, '_skyhshoso_subscription_creation_error' );
                $sub_creation_msg = sprintf( __( ' Linked to existing subscription #%d.', 'skyhs-hosting-solution' ), $existing_sub_id );
            } else {
                wp_send_json_error( array( 'message' => __( 'Please search and select an existing subscription to link.', 'skyhs-hosting-solution' ) ) );
            }
        } else {
            // Create on the go — always attempt creation
            // Clear any existing subscription ID so creation logic proceeds
            delete_post_meta( $result_id, 'skyhshoso_subscription_id' );
            delete_post_meta( $result_id, '_skyhshoso_subscription_creation_error' );

            // Mark as pending
            update_post_meta( $result_id, '_skyhshoso_subscription_creation_pending', 'yes' );

            // Attempt synchronous creation
            $created = $this->process_subscription_creation( $result_id );
            if ( $created ) {
                $new_sub_id = get_post_meta( $result_id, 'skyhshoso_subscription_id', true );
                $sub_creation_msg = sprintf( __( ' Billing subscription #%d successfully provisioned.', 'skyhs-hosting-solution' ), $new_sub_id );
            } else {
                $error = get_post_meta( $result_id, '_skyhshoso_subscription_creation_error', true );
                $sub_creation_msg = __( ' Billing subscription creation failed.', 'skyhs-hosting-solution' );
                if ( $error ) {
                    $sub_creation_msg .= ' ' . $error;
                }
            }
        }

        wp_send_json_success( array(
            'message'    => __( 'Hosting details successfully saved.', 'skyhs-hosting-solution' ) . $sub_creation_msg,
            'hosting_id' => $result_id,
        ) );
    }

    /**
     * AJAX: delete hosting
     */
    public function ajax_delete_hosting() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_delete_hosting' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'skyhs-hosting-solution' ) ) );
        }

        if ( ! current_user_can( 'delete_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'skyhs-hosting-solution' ) ) );
        }

        $hosting_id = isset( $_POST['hosting_id'] ) ? intval( $_POST['hosting_id'] ) : 0;
        if ( ! $hosting_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid hosting ID.', 'skyhs-hosting-solution' ) ) );
        }

        $post = get_post( $hosting_id );
        if ( ! $post || 'skyhshoso_hosting' !== $post->post_type ) {
            wp_send_json_error( array( 'message' => __( 'Hosting account not found.', 'skyhs-hosting-solution' ) ) );
        }

        wp_delete_post( $hosting_id, true );
        wp_send_json_success( array( 'message' => __( 'Hosting account permanently removed.', 'skyhs-hosting-solution' ) ) );
    }

    /**
     * AJAX: sync/fetch individual hosting detail
     */
    public function ajax_quick_sync_hosting() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_quick_sync' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security token expired.', 'skyhs-hosting-solution' ) ) );
        }

        $hosting_id = isset( $_POST['hosting_id'] ) ? intval( $_POST['hosting_id'] ) : 0;
        if ( ! $hosting_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid request.', 'skyhs-hosting-solution' ) ) );
        }

        $h = get_post( $hosting_id );
        if ( ! $h || 'skyhshoso_hosting' !== $h->post_type ) {
            wp_send_json_error( array( 'message' => __( 'Deployment missing.', 'skyhs-hosting-solution' ) ) );
        }

        $formatted = $this->format_hosting_data( $h );
        wp_send_json_success( $formatted );
    }

    /**
     * AJAX: Fetch paginated, filtered, and searched hostings
     */
    public function ajax_get_hostings() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_get_hostings' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security verification failed.', 'skyhs-hosting-solution' ) ) );
        }

        global $wpdb;
        $table_posts = $wpdb->posts;
        $table_postmeta = $wpdb->postmeta;
        $table_users = $wpdb->users;
        $table_subs = $wpdb->prefix . 'skyhshoso_subscriptions';

        $search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
        $status_filter = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';

        $where = array( "p.post_type = 'skyhshoso_hosting'", "p.post_status IN ('publish', 'draft', 'pending')" );
        $join = "";

        // Search filter (Matches hosting title, mapped domain, owner email, user login or display name)
        if ( ! empty( $search ) ) {
            $search_like = '%' . $wpdb->esc_like( $search ) . '%';
            $join .= " LEFT JOIN {$table_users} u ON p.post_author = u.ID ";
            $join .= " LEFT JOIN {$table_postmeta} pm_domain ON (p.ID = pm_domain.post_id AND pm_domain.meta_key = 'skyhshoso_hosting_domain') ";

            $where[] = $wpdb->prepare(
                "(p.post_title LIKE %s OR pm_domain.meta_value LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s OR u.user_login LIKE %s)",
                $search_like, $search_like, $search_like, $search_like, $search_like
            );
        }

        // Subscription status filter (Join CPT meta -> custom subscription DB table)
        $join .= " LEFT JOIN {$table_postmeta} pm_sub ON (p.ID = pm_sub.post_id AND pm_sub.meta_key = 'skyhshoso_subscription_id') ";
        $join .= " LEFT JOIN {$table_subs} s ON pm_sub.meta_value = s.id ";

        if ( ! empty( $status_filter ) ) {
            if ( 'no_sub' === $status_filter ) {
                $where[] = "(pm_sub.meta_value IS NULL OR pm_sub.meta_value = '' OR s.status IS NULL)";
            } else {
                $where[] = $wpdb->prepare( "s.status = %s", $status_filter );
            }
        }

        $where_sql = implode( ' AND ', $where );
        $join_sql = $join;

        // Fetch counts
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $total_records = $wpdb->get_var( "SELECT COUNT(DISTINCT p.ID) FROM {$table_posts} p {$join_sql} WHERE {$where_sql}" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

        $limit = isset( $_POST['limit'] ) ? intval( $_POST['limit'] ) : 10;
        $page = isset( $_POST['paged'] ) ? intval( $_POST['paged'] ) : 1;
        if ( $page < 1 ) {
            $page = 1;
        }
        $offset = ( $page - 1 ) * $limit;
        $total_pages = ceil( $total_records / $limit );
        if ( $total_pages < 1 ) {
            $total_pages = 1;
        }

        // Fetch results
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $query_sql = "SELECT DISTINCT p.* FROM {$table_posts} p {$join_sql} WHERE {$where_sql} ORDER BY p.post_date DESC LIMIT %d OFFSET %d";
        $results = $wpdb->get_results( $wpdb->prepare( $query_sql, $limit, $offset ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $hosting_list = array();

        foreach ( $results as $row ) {
            $hosting_list[] = $this->format_hosting_data( $row );
        }

        wp_send_json_success( array(
            'hostings'      => $hosting_list,
            'total_records' => intval( $total_records ),
            'total_pages'   => intval( $total_pages ),
            'current_page'  => intval( $page ),
        ) );
    }

    /**
     * AJAX: Autocomplete search of custom subscriptions
     */
    public function ajax_search_subscriptions() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_search_subscriptions' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security verification failed.', 'skyhs-hosting-solution' ) ) );
        }

        $term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
        if ( empty( $term ) ) {
            wp_send_json_success( array() );
        }

        global $wpdb;
        $table_subs = $wpdb->prefix . 'skyhshoso_subscriptions';
        $table_users = $wpdb->users;

        $where = array();
        $params = array();

        if ( is_numeric( $term ) ) {
            $where[] = "s.id = %d";
            $params[] = intval( $term );
        }

        $like = '%' . $wpdb->esc_like( $term ) . '%';
        $where[] = "u.user_email LIKE %s OR u.display_name LIKE %s OR u.user_login LIKE %s";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;

        $where_sql = implode( ' OR ', $where );
        $sql = "SELECT s.id, s.amount, s.billing_period, s.billing_interval, s.status, u.display_name, u.user_email 
                FROM {$table_subs} s
                LEFT JOIN {$table_users} u ON s.user_id = u.ID
                WHERE {$where_sql}
                ORDER BY s.id DESC
                LIMIT 15";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
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

    /**
     * AJAX: Search users by term for autocomplete owner field.
     */
    public function ajax_search_users() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_search_users' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'skyhs-hosting-solution' ) ) );
        }
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'skyhs-hosting-solution' ) ) );
        }

        $term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
        if ( empty( $term ) ) {
            wp_send_json_success( array() );
        }

        global $wpdb;
        $like = '%' . $wpdb->esc_like( $term ) . '%';
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, user_login, display_name, user_email FROM {$wpdb->users} 
            WHERE user_login LIKE %s OR user_email LIKE %s OR display_name LIKE %s 
            ORDER BY user_login ASC LIMIT 20",
            $like,
            $like,
            $like
        ) );

        $users = array();
        if ( $results ) {
            foreach ( $results as $row ) {
                $users[] = array(
                    'id'    => intval( $row->ID ),
                    'label' => $row->display_name . ' (' . $row->user_login . ')',
                    'email' => $row->user_email,
                );
            }
        }

        wp_send_json_success( $users );
    }

    /**
     * AJAX: Search products by term for autocomplete product field.
     */
    public function ajax_search_products() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_search_products' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'skyhs-hosting-solution' ) ) );
        }
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'skyhs-hosting-solution' ) ) );
        }

        $term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
        $product_type = isset( $_POST['product_type'] ) ? sanitize_key( $_POST['product_type'] ) : '';

        global $wpdb;
        $like = '%' . $wpdb->esc_like( $term ) . '%';

        $meta_key = '';
        if ( 'hosting' === $product_type ) {
            $meta_key = 'skyhshoso_hosting';
        } elseif ( 'wp_site' === $product_type ) {
            $meta_key = 'skyhshoso_wp_site';
        }

        if ( empty( $meta_key ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid product type.', 'skyhs-hosting-solution' ) ) );
        }

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_skyhshoso_product_type' AND pm.meta_value = %s
            WHERE p.post_type = 'product' AND p.post_status = 'publish' AND p.post_title LIKE %s
            ORDER BY p.post_title ASC LIMIT 20",
            $meta_key,
            $like
        ) );

        $products = array();
        foreach ( $results as $row ) {
            $product = wc_get_product( $row->ID );
            if ( ! $product ) {
                continue;
            }

            if ( $product->is_type( 'simple' ) || $product->is_type( 'subscription' ) ) {
                $price = wp_strip_all_tags( wc_price( $product->get_price() ) );
                $server_id = get_post_meta( $product->get_id(), '_skyhshoso_server_id', true );
                $plan = get_post_meta( $product->get_id(), '_skyhshoso_hosting_plan', true );

                $products[] = array(
                    'id'           => strval( $product->get_id() ),
                    'label'        => $product->get_name() . ' — ' . $price,
                    'server_id'    => $server_id ?: '',
                    'plan'         => $plan ?: '',
                );
            } elseif ( $product->is_type( 'variable' ) || $product->is_type( 'variable-subscription' ) ) {
                $variations = $product->get_available_variations();
                foreach ( $variations as $var_data ) {
                    $variation = wc_get_product( $var_data['variation_id'] );
                    if ( ! $variation ) {
                        continue;
                    }

                    $var_attributes = $variation->get_variation_attributes();
                    $plan_name = '';
                    foreach ( $var_attributes as $attr_key => $attr_val ) {
                        $tax = str_replace( 'attribute_', '', $attr_key );
                        if ( taxonomy_exists( $tax ) ) {
                            $term_obj = get_term_by( 'slug', $attr_val, $tax );
                            $plan_name = $term_obj ? $term_obj->name : $attr_val;
                        } else {
                            $plan_name = $attr_val;
                        }
                        break; // Use first attribute as plan name
                    }

                    $price = wp_strip_all_tags( wc_price( $variation->get_price() ) );
                    $server_id = get_post_meta( $product->get_id(), '_skyhshoso_server_id', true );
                    $hosting_plan = get_post_meta( $variation->get_id(), '_skyhshoso_hosting_plan', true );

                    $label = $product->get_name() . ' » ' . $plan_name . ' — ' . $price;

                    $products[] = array(
                        'id'           => $product->get_id() . '|' . $variation->get_id(),
                        'label'        => $label,
                        'server_id'    => $server_id ?: '',
                        'plan'         => $hosting_plan ?: $plan_name,
                    );
                }
            }
        }

        wp_send_json_success( $products );
    }

    /**
     * AJAX: Get cached cPanel accounts for a server (for "Connect Existing" dropdown).
     */
    public function ajax_get_cpanel_accounts() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_get_cpanel_accounts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'skyhs-hosting-solution' ) ) );
        }
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'skyhs-hosting-solution' ) ) );
        }

        $server_id = isset( $_POST['server_id'] ) ? intval( $_POST['server_id'] ) : 0;
        if ( ! $server_id ) {
            wp_send_json_error( array( 'message' => __( 'No server selected.', 'skyhs-hosting-solution' ) ) );
        }

        $term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';

        global $wpdb;
        $table = $wpdb->prefix . 'skyhshoso_cpanel_cache';
        if ( ! empty( $term ) ) {
            $like = '%' . $wpdb->esc_like( $term ) . '%';
            $results = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, username, domain, plan FROM {$table} WHERE server_id = %d AND (username LIKE %s OR domain LIKE %s) ORDER BY username ASC LIMIT 50",
                $server_id,
                $like,
                $like
            ) );
        } else {
            $results = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, username, domain, plan FROM {$table} WHERE server_id = %d ORDER BY username ASC",
                $server_id
            ) );
        }

        $accounts = array();
        if ( $results ) {
            foreach ( $results as $row ) {
                $accounts[] = array(
                    'id'       => intval( $row->id ),
                    'username' => $row->username,
                    'domain'   => $row->domain,
                    'plan'     => $row->plan,
                    'label'    => $row->username . ' — ' . $row->domain . ( $row->plan ? ' (' . $row->plan . ')' : '' ),
                );
            }
        }

        wp_send_json_success( $accounts );
    }

    /**
     * Look up the primary domain for a cPanel account from the cache table.
     */
    private function get_cached_cpanel_domain( $server_id, $username ) {
        global $wpdb;
        $table = $wpdb->prefix . 'skyhshoso_cpanel_cache';
        $domain = $wpdb->get_var( $wpdb->prepare(
            "SELECT domain FROM {$table} WHERE server_id = %d AND username = %s LIMIT 1",
            $server_id,
            $username
        ) );
        return $domain ?: '';
    }

    /**
     * Format hosting post into display details
     */
    public function format_hosting_data( $h ) {
        $owner_id = get_post_field( 'post_author', $h->ID );
        $owner = get_user_by( 'id', $owner_id );

        $product_id = get_post_meta( $h->ID, '_skyhshoso_hosting_product_id', true );
        $variation_id = get_post_meta( $h->ID, '_skyhshoso_variation_id', true );
        $domain = get_post_meta( $h->ID, 'skyhshoso_hosting_domain', true );
        $server_id = get_post_meta( $h->ID, 'skyhshoso_server_id', true );
        $plan = get_post_meta( $h->ID, 'skyhshoso_hosting_plan', true );
        $cpanel_username = get_post_meta( $h->ID, 'skyhshoso_hosting_username', true );
        $account_source = get_post_meta( $h->ID, '_skyhshoso_hosting_account_source', true );
        $subscription_id = get_post_meta( $h->ID, 'skyhshoso_subscription_id', true );

        $sub_status = '—';
        $sub_status_label = __( 'No Subscription', 'skyhs-hosting-solution' );
        if ( $subscription_id ) {
            $sub = skyhshoso_get_subscription( $subscription_id );
            if ( $sub ) {
                $sub_status = $sub->get_status();
                $sub_status_label = skyhshoso_get_subscription_status_name( $sub_status );
            }
        }

        $server_title = '—';
        if ( $server_id ) {
            $server = get_post( $server_id );
            $server_title = $server ? $server->post_title : $server_id;
        }

        $product_title = '—';
        if ( $product_id ) {
            $prod = wc_get_product( $product_id );
            $product_title = $prod ? $prod->get_name() : $product_id;
        }

        return array(
            'id'              => $h->ID,
            'title'           => $h->post_title,
            'status'          => $h->post_status,
            'owner_id'        => $owner_id,
            'owner_name'      => $owner ? $owner->display_name . ' (' . $owner->user_email . ')' : '—',
            'product_id'      => $variation_id ? $product_id . '|' . $variation_id : $product_id,
            'product_title'   => $product_title,
            'domain'          => $domain ?: '',
            'server_id'       => $server_id ?: '',
            'server_title'    => $server_title,
            'plan'             => $plan ?: '—',
            'cpanel_username'  => $cpanel_username ?: '',
            'account_source'   => $account_source ?: 'new',
            'subscription_id'  => $subscription_id ?: '',
            'sub_status'      => $sub_status,
            'sub_status_label'=> $sub_status_label,
        );
    }

    // -------------------------------------------------------------------------
    // Subscription creation methods (moved from class-hosting-meta-boxes.php)
    // -------------------------------------------------------------------------

    /**
     * Process subscription creation for a hosting post.
     */
    public function process_subscription_creation( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'skyhshoso_hosting' ) {
            return;
        }

        $subscription_id = get_post_meta( $post_id, 'skyhshoso_subscription_id', true );
        if ( ! empty( $subscription_id ) ) {
            delete_post_meta( $post_id, '_skyhshoso_subscription_creation_pending' );
            return;
        }

        try {
            $product_id = get_post_meta( $post_id, '_skyhshoso_hosting_product_id', true );

            $variation_id = null;
            if ( strpos( $product_id, '|' ) !== false ) {
                list( $product_id, $variation_id ) = explode( '|', $product_id );
            } else {
                $variation_id = get_post_meta( $post_id, '_skyhshoso_variation_id', true );
            }

            $product = wc_get_product( $product_id );
			if ( ! $product ) {
				update_post_meta( $post_id, '_skyhshoso_subscription_creation_error', 'Product not found' );
				SkyHSHOSO_Logger::error( 'Hosting subscription creation failed for post #' . $post_id . ': product not found (ID: ' . $product_id . ')', array( 'source' => 'hosting_manager' ) );
				return;
			}

			if ( ! $product->is_type( array( 'subscription', 'variable-subscription' ) ) && ! SkyHSHOSO_Subscriptions_Product::is_subscription( $product ) && ( get_post_meta( $product->get_id(), '_skyhshoso_product_type', true ) !== 'skyhshoso_hosting' ) ) {
				update_post_meta( $post_id, '_skyhshoso_subscription_creation_error', 'Product is not a subscription' );
				SkyHSHOSO_Logger::error( 'Hosting subscription creation failed for post #' . $post_id . ': product is not a subscription', array( 'source' => 'hosting_manager' ) );
				return;
			}

			$user_id = get_post_field( 'post_author', $post_id );
			$user = get_user_by( 'id', $user_id );
			if ( ! $user ) {
				update_post_meta( $post_id, '_skyhshoso_subscription_creation_error', 'User not found' );
				SkyHSHOSO_Logger::error( 'Hosting subscription creation failed for post #' . $post_id . ': user not found (ID: ' . $user_id . ')', array( 'source' => 'hosting_manager' ) );
				return;
			}

            $subscription = $this->create_subscription( $user, $product, $post, $variation_id );

			if ( $subscription ) {
				update_post_meta( $post_id, 'skyhshoso_subscription_id', $subscription->get_id() );
				delete_post_meta( $post_id, '_skyhshoso_subscription_creation_pending' );
				delete_post_meta( $post_id, '_skyhshoso_subscription_creation_error' );
				$subscription->add_order_note( sprintf( __( 'Subscription created automatically from hosting post #%d', 'skyhs-hosting-solution' ), $post->ID ) );
				return true;
			} else {
				$error_msg = 'Failed to create subscription';
				update_post_meta( $post_id, '_skyhshoso_subscription_creation_error', $error_msg );
				SkyHSHOSO_Logger::error( 'Hosting subscription creation failed for post #' . $post_id . ': ' . $error_msg, array( 'source' => 'hosting_manager' ) );
				return false;
			}
		} catch ( Exception $e ) {
			update_post_meta( $post_id, '_skyhshoso_subscription_creation_error', $e->getMessage() );
			SkyHSHOSO_Logger::error( 'Hosting subscription creation failed for post #' . $post_id . ': ' . $e->getMessage(), array( 'source' => 'hosting_manager' ) );
			return false;
		}
    }

    /**
     * Create a WooCommerce subscription for a hosting account.
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
                get_post_meta( $product->get_id(), '_skyhshoso_product_type', true ) !== 'skyhshoso_hosting' ) {
                return false;
            }

            $billing_period = 'month';
            $billing_interval = 1;

            $product_period = SkyHSHOSO_Subscriptions_Product::get_period( $product_to_add );
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

SkyHSHOSO_Hosting_Manager::instance();
