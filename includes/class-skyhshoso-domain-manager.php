<?php

defined( 'ABSPATH' ) || exit;

class SkyHSHOSO_Domain_Manager {

    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

        add_action( 'wp_ajax_skyhshoso_dm_domain_search', array( $this, 'ajax_domain_search' ) );
        add_action( 'wp_ajax_skyhshoso_register_domain', array( $this, 'ajax_register_domain' ) );
        add_action( 'wp_ajax_skyhshoso_delete_domain', array( $this, 'ajax_delete_domain' ) );
        add_action( 'wp_ajax_skyhshoso_quick_sync_domain', array( $this, 'ajax_quick_sync_domain' ) );
        add_action( 'wp_ajax_skyhshoso_get_domains', array( $this, 'ajax_get_domains' ) );
        add_action( 'wp_ajax_skyhshoso_dm_get_synced_domains', array( $this, 'ajax_get_synced_domains' ) );
        add_action( 'wp_ajax_skyhshoso_dm_lookup_owner', array( $this, 'ajax_lookup_owner' ) );
        add_action( 'wp_ajax_skyhshoso_dm_create_user', array( $this, 'ajax_create_user' ) );
    }

    public function enqueue_scripts( $hook ) {
        $page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( false === strpos( $hook, 'skyhshoso-domains' ) && 'skyhshoso-domains' !== $page ) {
            return;
        }

        wp_enqueue_style(
            'skyhshoso-domain-manager',
            SKYHSHOSO_PLUGIN_URL . 'assets/css/hosting-manager.css',
            array(),
            SKYHSHOSO_VERSION
        );

        wp_enqueue_script(
            'skyhshoso-domain-manager',
            SKYHSHOSO_PLUGIN_URL . 'assets/js/domain-manager.js',
            array( 'jquery' ),
            SKYHSHOSO_VERSION,
            true
        );

        wp_localize_script(
            'skyhshoso-domain-manager',
            'skyhshoso_dm',
            array(
                'ajax_url'            => admin_url( 'admin-ajax.php' ),
                'nonce_search'        => wp_create_nonce( 'skyhshoso_dm_domain_search' ),
                'nonce_register'      => wp_create_nonce( 'skyhshoso_register_domain' ),
                'nonce_delete'        => wp_create_nonce( 'skyhshoso_delete_domain' ),
                'nonce_sync'          => wp_create_nonce( 'skyhshoso_quick_sync_domain' ),
                'nonce_get'           => wp_create_nonce( 'skyhshoso_get_domains' ),
                'nonce_search_subs'   => wp_create_nonce( 'skyhshoso_search_subscriptions' ),
                'nonce_synced'        => wp_create_nonce( 'skyhshoso_dm_get_synced_domains' ),
                'nonce_lookup_owner'  => wp_create_nonce( 'skyhshoso_dm_lookup_owner' ),
                'nonce_create_user'   => wp_create_nonce( 'skyhshoso_dm_create_user' ),
                'nonce_search_users'  => wp_create_nonce( 'skyhshoso_search_users' ),
                'strings'             => array(
                    'searching'      => __( 'Searching domain...', 'skyhs-hosting-solution' ),
                    'registering'    => __( 'Registering domain...', 'skyhs-hosting-solution' ),
                    'deleting'       => __( 'Deleting domain account...', 'skyhs-hosting-solution' ),
                    'saved'          => __( 'Domain registered successfully!', 'skyhs-hosting-solution' ),
                    'deleted'        => __( 'Domain deleted successfully.', 'skyhs-hosting-solution' ),
                    'error'          => __( 'An error occurred.', 'skyhs-hosting-solution' ),
                    'confirm_delete' => __( 'Are you sure you want to delete this domain account?', 'skyhs-hosting-solution' ),
                    'fill_fields'    => __( 'Please select a domain, owner, and configure subscription.', 'skyhs-hosting-solution' ),
                    'select_domain_first' => __( 'Please search and select an available domain first.', 'skyhs-hosting-solution' ),
                    'no_results'     => __( 'No domain registrations found matching the criteria.', 'skyhs-hosting-solution' ),
                    'lookup_owner'   => __( 'Looking up domain owner...', 'skyhs-hosting-solution' ),
                    'owner_found'    => __( 'Owner auto-detected from registrant email.', 'skyhs-hosting-solution' ),
                    'owner_not_found' => __( 'Registrant email %s not found in system.', 'skyhs-hosting-solution' ),
                    'create_user'    => __( 'Create User', 'skyhs-hosting-solution' ),
                    'assign_different' => __( 'Assign Different', 'skyhs-hosting-solution' ),
                    'creating_user'  => __( 'Creating user...', 'skyhs-hosting-solution' ),
                    'user_created'   => __( 'User created successfully.', 'skyhs-hosting-solution' ),
                ),
            )
        );
    }

    public function render_page() {
        ?>
        <div class="wrap skyhshoso-hm-wrap">
            <h1><?php esc_html_e( 'Domain Accounts', 'skyhs-hosting-solution' ); ?></h1>
            <p><?php esc_html_e( 'Search, register, and manage domain names. Search availability via eNom, assign owners, and link or create subscriptions — all from a single screen.', 'skyhs-hosting-solution' ); ?></p>

            <div id="skyhshoso-hm-notice" class="notice" style="display:none;"></div>

            <div id="skyhshoso-hm-app">
                <div class="skyhshoso-hm-form-panel" id="skyhshoso-hm-form-panel" style="display:none;">
                    <div class="skyhshoso-hm-form-header">
                        <h2 id="skyhshoso-hm-form-title"><?php esc_html_e( 'Register a New Domain', 'skyhs-hosting-solution' ); ?></h2>
                    </div>

                    <form id="skyhshoso-hm-form" class="skyhshoso-hm-form">
                        <input type="hidden" id="dm_domain_id" name="domain_id" value="0" />
                        <input type="hidden" id="dm_selected_domain" name="selected_domain" value="" />
                        <input type="hidden" id="dm_domain_price" name="domain_price" value="" />
                        <input type="hidden" id="dm_domain_tld" name="domain_tld" value="" />

                        <div class="skyhshoso-hm-section">
                            <h3><?php esc_html_e( '1. Search Domain', 'skyhs-hosting-solution' ); ?></h3>
                            <div class="skyhshoso-hm-row" style="display:flex;gap:10px;align-items:flex-end;">
                                <div class="skyhshoso-hm-field" style="flex:1;">
                                    <label for="dm_domain_search"><?php esc_html_e( 'Enter a domain name', 'skyhs-hosting-solution' ); ?></label>
                                    <input type="text" id="dm_domain_search" class="hm-input" placeholder="<?php esc_attr_e( 'e.g., example.com', 'skyhs-hosting-solution' ); ?>" autocomplete="off" />
                                </div>
                                <div style="flex-shrink:0;">
                                    <button type="button" id="dm-search-btn" class="button button-secondary" style="height:40px;padding:0 20px;font-weight:600;">
                                        <span class="dashicons dashicons-search" style="vertical-align:middle;margin-right:4px;font-size:16px;"></span>
                                        <?php esc_html_e( 'Search', 'skyhs-hosting-solution' ); ?>
                                    </button>
                                </div>
                            </div>
                            <div id="dm-search-results" style="margin-top:16px;display:none;"></div>
                            <div id="dm-search-loader" style="display:none;margin-top:12px;text-align:center;color:#6b7280;font-size:13px;">
                                <span class="spinner" style="float:none;visibility:visible;margin:0 6px 0 0;"></span>
                                <?php esc_html_e( 'Checking domain availability...', 'skyhs-hosting-solution' ); ?>
                            </div>
                            <div style="margin-top:14px;text-align:center;border-top:1px solid #e5e7eb;padding-top:14px;">
                                <button type="button" id="dm-show-synced-btn" class="button button-secondary" style="font-size:12px;">
                                    <span class="dashicons dashicons-list-view" style="vertical-align:middle;margin-right:4px;font-size:14px;"></span>
                                    <?php esc_html_e( 'Or choose from synced Enom domains', 'skyhs-hosting-solution' ); ?>
                                </button>
                            </div>
                            <div id="dm-synced-list" style="margin-top:12px;display:none;"></div>
                        </div>

                        <div id="dm-registration-section" style="display:none;">
                            <div class="skyhshoso-hm-section">
                                <h3><?php esc_html_e( '2. Account Details', 'skyhs-hosting-solution' ); ?></h3>
                                <div class="skyhshoso-hm-row skyhshoso-hm-row-cols-2">
                                    <div class="skyhshoso-hm-field">
                                        <label for="dm_owner_search"><?php esc_html_e( 'Domain Owner', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                        <div class="hm-search-owner-wrapper" style="position:relative;">
                                            <input type="text" id="dm_owner_search" class="hm-input" placeholder="<?php esc_attr_e( 'Type username, email or name...', 'skyhs-hosting-solution' ); ?>" autocomplete="off" />
                                            <input type="hidden" id="hm_owner_id" name="owner_id" value="" />
                                            <div id="dm_owner_search_results" class="hm-autocomplete-results" style="display:none;position:absolute;z-index:999;width:100%;background:#fff;border:1px solid #ddd;max-height:150px;overflow-y:auto;box-shadow:0 4px 6px rgba(0,0,0,0.1);"></div>
                                        </div>
                                    </div>
                                    <div class="skyhshoso-hm-field">
                                        <label for="dm_title"><?php esc_html_e( 'Domain Label / Title', 'skyhs-hosting-solution' ); ?></label>
                                        <input type="text" id="dm_title" name="title" class="hm-input" placeholder="<?php esc_attr_e( 'Auto-fills from domain name', 'skyhs-hosting-solution' ); ?>" />
                                    </div>
                                </div>
                                <div id="dm-create-user-inline" style="display:none;margin-top:12px;padding:16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;">
                                    <div style="font-size:13px;color:#6b7280;margin-bottom:10px;line-height:1.5;">
                                        <span class="dashicons dashicons-info-outline" style="font-size:14px;width:14px;height:14px;margin-right:4px;"></span>
                                        <span id="dm-inline-owner-msg"></span>
                                    </div>
                                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px;">
                                        <div>
                                            <label style="display:block;font-size:11px;font-weight:600;color:#6b7280;margin-bottom:3px;"><?php esc_html_e( 'First Name', 'skyhs-hosting-solution' ); ?></label>
                                            <input type="text" id="dm-inline-first-name" class="hm-input" style="width:100%;" placeholder="<?php esc_attr_e( 'First name', 'skyhs-hosting-solution' ); ?>">
                                        </div>
                                        <div>
                                            <label style="display:block;font-size:11px;font-weight:600;color:#6b7280;margin-bottom:3px;"><?php esc_html_e( 'Last Name', 'skyhs-hosting-solution' ); ?></label>
                                            <input type="text" id="dm-inline-last-name" class="hm-input" style="width:100%;" placeholder="<?php esc_attr_e( 'Last name', 'skyhs-hosting-solution' ); ?>">
                                        </div>
                                    </div>
                                    <div style="margin-bottom:10px;">
                                        <label style="display:block;font-size:11px;font-weight:600;color:#6b7280;margin-bottom:3px;"><?php esc_html_e( 'Email', 'skyhs-hosting-solution' ); ?></label>
                                        <input type="email" id="dm-inline-email" class="hm-input" style="width:100%;">
                                    </div>
                                    <div id="dm-inline-create-msg" style="font-size:12px;margin-bottom:8px;display:none;"></div>
                                    <div style="display:flex;gap:8px;">
                                        <button type="button" id="dm-inline-create-btn" class="button button-primary" style="font-weight:600;"><?php esc_html_e( 'Create User & Assign', 'skyhs-hosting-solution' ); ?></button>
                                        <button type="button" id="dm-inline-assign-btn" class="button" style="font-weight:600;"><?php esc_html_e( 'Assign a different owner', 'skyhs-hosting-solution' ); ?></button>
                                    </div>
                                </div>
                            </div>

                            <div class="skyhshoso-hm-section">
                                <h3><?php esc_html_e( '3. Billing Subscription', 'skyhs-hosting-solution' ); ?></h3>
                                <div id="dm-current-sub-info" style="display:none;margin-bottom:12px;"></div>
                                <div class="skyhshoso-hm-row skyhshoso-hm-row-cols-2">
                                    <div class="skyhshoso-hm-field">
                                        <label for="dm_sub_action"><?php esc_html_e( 'Subscription Action', 'skyhs-hosting-solution' ); ?></label>
                                        <select id="dm_sub_action" name="sub_action" class="hm-input">
                                            <option value="keep"><?php esc_html_e( 'Keep existing subscription', 'skyhs-hosting-solution' ); ?></option>
                                            <option value="create"><?php esc_html_e( 'Create new subscription on-the-go', 'skyhs-hosting-solution' ); ?></option>
                                            <option value="link"><?php esc_html_e( 'Link to an existing subscription', 'skyhs-hosting-solution' ); ?></option>
                                        </select>
                                    </div>
                                    <div class="skyhshoso-hm-field" id="dm_existing_sub_container" style="display:none;">
                                        <label for="dm_existing_sub_search"><?php esc_html_e( 'Existing Subscription', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                        <div class="hm-search-sub-wrapper" style="position:relative;">
                                            <input type="text" id="dm_existing_sub_search" class="hm-input" placeholder="<?php esc_attr_e( 'Type Sub ID or Owner Email to search...', 'skyhs-hosting-solution' ); ?>" autocomplete="off" />
                                            <input type="hidden" id="dm_existing_sub_id" name="existing_sub_id" value="" />
                                            <div id="dm-sub-search-results" class="hm-autocomplete-results" style="display:none;position:absolute;z-index:999;width:100%;background:#fff;border:1px solid #ddd;max-height:200px;overflow-y:auto;box-shadow:0 4px 6px rgba(0,0,0,0.1);"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="skyhshoso-hm-actions">
                                <div class="skyhshoso-hm-actions-left">
                                    <span id="hm-loader" class="spinner" style="float:none;margin:0;"></span>
                                </div>
                                <div class="skyhshoso-hm-actions-right">
                                    <button type="button" id="dm-cancel-btn" class="button" style="display:none;">
                                        <?php esc_html_e( 'Cancel', 'skyhs-hosting-solution' ); ?>
                                    </button>
                                    <button type="submit" id="dm-register-btn" class="button button-primary button-large">
                                        <span class="dashicons dashicons-yes-alt" style="vertical-align:middle;margin-right:4px;font-size:16px;"></span>
                                        <?php esc_html_e( 'Register Domain', 'skyhs-hosting-solution' ); ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="skyhshoso-hm-list-panel">
                    <div class="skyhshoso-hm-list-header">
                        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
                            <h2 style="margin:0;"><?php esc_html_e( 'Registered Domains', 'skyhs-hosting-solution' ); ?></h2>
                            <button type="button" id="hm-add-hosting-btn" class="button button-primary">
                                <span class="dashicons dashicons-plus-alt2" style="vertical-align:middle;margin-right:4px;font-size:16px;line-height:1.4;"></span>
                                <?php esc_html_e( 'Add Domain', 'skyhs-hosting-solution' ); ?>
                            </button>
                        </div>
                        <div class="skyhshoso-hm-controls" style="display:flex;gap:10px;margin-bottom:15px;flex-wrap:wrap;">
                            <input type="text" id="hm-search-input" class="hm-control-input" placeholder="<?php esc_attr_e( 'Search domain, owner email...', 'skyhs-hosting-solution' ); ?>" style="flex:1;min-width:200px;" />
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

    public function ajax_domain_search() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nonce'] ) ), 'skyhshoso_dm_domain_search' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'skyhs-hosting-solution' ) ) );
        }

        $domain = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
        if ( empty( $domain ) ) {
            wp_send_json_error( array( 'message' => __( 'Please enter a domain name.', 'skyhs-hosting-solution' ) ) );
        }

        $parts = explode( '.', $domain, 2 );
        if ( count( $parts ) !== 2 ) {
            wp_send_json_error( array( 'message' => __( 'Invalid domain format. Use format: example.com', 'skyhs-hosting-solution' ) ) );
        }

        $sld = $parts[0];
        $tld = $parts[1];

		try {
			$result = SkyHSHOSO_Enom_Integration()->check_domain( $sld, $tld );
		} catch ( Exception $e ) {
			SkyHSHOSO_Logger::error( 'Domain search failed for ' . $domain . ': ' . $e->getMessage(), array( 'source' => 'domain_manager' ) );
			wp_send_json_success( array(
				'api_error' => true,
				'domain'    => $domain,
				'error'     => $e->getMessage(),
			) );
		}

		if ( isset( $result['error'] ) ) {
			SkyHSHOSO_Logger::error( 'Domain search API error for ' . $domain . ': ' . $result['error'], array( 'source' => 'domain_manager' ) );
			wp_send_json_success( array(
				'api_error' => true,
				'domain'    => $domain,
				'error'     => $result['error'],
			) );
		}

        $suggestions = array();
        $suggested_tlds = array( 'com', 'net', 'org', 'io' );
        foreach ( $suggested_tlds as $sugg_tld ) {
            if ( $sugg_tld !== $tld ) {
                try {
                    $sugg_result = SkyHSHOSO_Enom_Integration()->check_domain( $sld, $sugg_tld );
                    if ( isset( $sugg_result['available'] ) && $sugg_result['available'] ) {
                        $suggestions[] = $sugg_result;
                    }
                } catch ( Exception $e ) {
                }
            }
        }
        $result['suggestions'] = $suggestions;

        wp_send_json_success( $result );
    }

    public function ajax_register_domain() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nonce'] ) ), 'skyhshoso_register_domain' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'skyhs-hosting-solution' ) ) );
        }

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'skyhs-hosting-solution' ) ) );
        }

        $domain      = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
        $owner_id    = isset( $_POST['owner_id'] ) ? intval( $_POST['owner_id'] ) : 0;
        $title       = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        $domain_id   = isset( $_POST['domain_id'] ) ? intval( $_POST['domain_id'] ) : 0;

		if ( empty( $domain ) || ! $owner_id ) {
			SkyHSHOSO_Logger::error( 'Domain registration failed: domain name and owner are required', array( 'source' => 'domain_manager' ) );
			wp_send_json_error( array( 'message' => __( 'Domain name and owner are required.', 'skyhs-hosting-solution' ) ) );
		}

        if ( empty( $title ) ) {
            $title = $domain;
        }

        $registration_price = 0;
        $renewal_price = 0;
        try {
            $parts = explode( '.', $domain, 2 );
            $sld = $parts[0];
            $tld = $parts[1];
            $domain_check = SkyHSHOSO_Enom_Integration()->check_domain( $sld, $tld );
            if ( isset( $domain_check['available'] ) && $domain_check['available'] ) {
                $registration_price = isset( $domain_check['registration_price'] ) ? $domain_check['registration_price'] : 0;
                $renewal_price = isset( $domain_check['renewal_price'] ) ? $domain_check['renewal_price'] : $registration_price;
            }
        } catch ( Exception $e ) {
        }

        $post_data = array(
            'post_type'   => 'skyhshoso_domain',
            'post_title'  => $title,
            'post_author' => $owner_id,
            'post_status' => 'publish',
        );

        if ( $domain_id ) {
            $post_data['ID'] = $domain_id;
            $result_id = wp_update_post( $post_data );
        } else {
            $result_id = wp_insert_post( $post_data );
        }

		if ( is_wp_error( $result_id ) ) {
			SkyHSHOSO_Logger::error( 'Domain post creation failed: ' . $result_id->get_error_message(), array( 'source' => 'domain_manager' ) );
			wp_send_json_error( array( 'message' => $result_id->get_error_message() ) );
		}

        update_post_meta( $result_id, 'skyhshoso_domain_name', $domain );

        $product_id = SkyHSHOSO_Domain_Cart()->create_domain_product( $domain, false, $registration_price, $renewal_price );
        if ( $product_id ) {
            update_post_meta( $result_id, '_skyhshoso_domain_product_id', $product_id );
        }

        $sub_creation_msg = '';
        $sub_action = isset( $_POST['sub_action'] ) ? sanitize_text_field( wp_unslash( $_POST['sub_action'] ) ) : 'create';
        $existing_sub_id = isset( $_POST['existing_sub_id'] ) ? intval( $_POST['existing_sub_id'] ) : 0;

        if ( 'keep' === $sub_action ) {
            $existing_sub = get_post_meta( $result_id, 'skyhshoso_subscription_id', true );
            if ( ! empty( $existing_sub ) ) {
                $sub_creation_msg = sprintf( __( ' Keeping existing subscription #%d.', 'skyhs-hosting-solution' ), $existing_sub );
            }
        } elseif ( 'link' === $sub_action ) {
			if ( ! empty( $existing_sub_id ) ) {
				$sub = skyhshoso_get_subscription( $existing_sub_id );
				if ( ! $sub ) {
					SkyHSHOSO_Logger::error( 'Domain subscription link failed: subscription #' . $existing_sub_id . ' does not exist', array( 'source' => 'domain_manager' ) );
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
            delete_post_meta( $result_id, 'skyhshoso_subscription_id' );
            delete_post_meta( $result_id, '_skyhshoso_subscription_creation_error' );
            update_post_meta( $result_id, '_skyhshoso_subscription_creation_pending', 'yes' );

            if ( class_exists( 'SkyHSHOSO_Domain_Meta_Boxes' ) ) {
                $created = SkyHSHOSO_Domain_Meta_Boxes::instance()->process_subscription_creation( $result_id );
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
        }

        wp_send_json_success( array(
            'message'   => sprintf( __( 'Domain %s registered successfully.', 'skyhs-hosting-solution' ), $domain ) . $sub_creation_msg,
            'domain_id' => $result_id,
        ) );
    }

    public function ajax_delete_domain() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nonce'] ) ), 'skyhshoso_delete_domain' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'skyhs-hosting-solution' ) ) );
        }

        if ( ! current_user_can( 'delete_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'skyhs-hosting-solution' ) ) );
        }

        $domain_id = isset( $_POST['hosting_id'] ) ? intval( $_POST['hosting_id'] ) : 0;
        if ( ! $domain_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid domain ID.', 'skyhs-hosting-solution' ) ) );
        }

        $post = get_post( $domain_id );
        if ( ! $post || 'skyhshoso_domain' !== $post->post_type ) {
            wp_send_json_error( array( 'message' => __( 'Domain account not found.', 'skyhs-hosting-solution' ) ) );
        }

        wp_delete_post( $domain_id, true );
        wp_send_json_success( array( 'message' => __( 'Domain account permanently removed.', 'skyhs-hosting-solution' ) ) );
    }

    public function ajax_quick_sync_domain() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nonce'] ) ), 'skyhshoso_quick_sync_domain' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security token expired.', 'skyhs-hosting-solution' ) ) );
        }

        $domain_id = isset( $_POST['hosting_id'] ) ? intval( $_POST['hosting_id'] ) : 0;
        if ( ! $domain_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid request.', 'skyhs-hosting-solution' ) ) );
        }

        $d = get_post( $domain_id );
        if ( ! $d || 'skyhshoso_domain' !== $d->post_type ) {
            wp_send_json_error( array( 'message' => __( 'Domain deployment missing.', 'skyhs-hosting-solution' ) ) );
        }

        $formatted = $this->format_domain_data( $d );
        wp_send_json_success( $formatted );
    }

    public function ajax_get_domains() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nonce'] ) ), 'skyhshoso_get_domains' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security verification failed.', 'skyhs-hosting-solution' ) ) );
        }

        global $wpdb;
        $table_posts = $wpdb->posts;
        $table_postmeta = $wpdb->postmeta;
        $table_users = $wpdb->users;
        $table_subs = $wpdb->prefix . 'skyhshoso_subscriptions';

        $search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
        $status_filter = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';

        $where = array( "p.post_type = 'skyhshoso_domain'", "p.post_status IN ('publish', 'draft', 'pending')" );
        $join = "";

        if ( ! empty( $search ) ) {
            $search_like = '%' . $wpdb->esc_like( $search ) . '%';
            $join .= " LEFT JOIN {$table_users} u ON p.post_author = u.ID ";
            $join .= " LEFT JOIN {$table_postmeta} pm_domain ON (p.ID = pm_domain.post_id AND pm_domain.meta_key = 'skyhshoso_domain_name') ";

            $where[] = $wpdb->prepare(
                "(p.post_title LIKE %s OR pm_domain.meta_value LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s OR u.user_login LIKE %s)",
                $search_like, $search_like, $search_like, $search_like, $search_like
            );
        }

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

        $total_records = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT COUNT(DISTINCT p.ID) FROM {$table_posts} p {$join_sql} WHERE {$where_sql}" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );

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

        $query_sql = "SELECT DISTINCT p.* FROM {$table_posts} p {$join_sql} WHERE {$where_sql} ORDER BY p.post_date DESC LIMIT %d OFFSET %d";
        $results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare( $query_sql, $limit, $offset ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        $domain_list = array();

        foreach ( $results as $row ) {
            $domain_list[] = $this->format_domain_data( $row );
        }

        wp_send_json_success( array(
            'domains'       => $domain_list,
            'total_records' => intval( $total_records ),
            'total_pages'   => intval( $total_pages ),
            'current_page'  => intval( $page ),
        ) );
    }

    public function ajax_get_synced_domains() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nonce'] ) ), 'skyhshoso_dm_get_synced_domains' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ) );
        }

        global $wpdb;
        $cache_table = $wpdb->prefix . 'skyhshoso_enom_cache';
        $page = isset( $_POST['page'] ) ? max( 1, intval( $_POST['page'] ) ) : 1;
        $per_page = 10;
        $offset = ( $page - 1 ) * $per_page;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total = $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$cache_table} c
             WHERE NOT EXISTS (
                 SELECT 1 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'skyhshoso_domain'
                 WHERE pm.meta_key = 'skyhshoso_domain_name' AND pm.meta_value = c.domain_name
             )"
        );

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.domain_name, c.sld, c.tld, c.expiration_date, c.reg_lock, c.renew_flag
             FROM {$cache_table} c
             WHERE NOT EXISTS (
                 SELECT 1 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'skyhshoso_domain'
                 WHERE pm.meta_key = 'skyhshoso_domain_name' AND pm.meta_value = c.domain_name
             )
             ORDER BY c.domain_name ASC
             LIMIT %d OFFSET %d",
                $per_page, $offset
            ), ARRAY_A );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $synced = array();
        foreach ( $results as $row ) {
            $synced[] = array(
                'domain'          => $row['domain_name'],
                'sld'             => $row['sld'],
                'tld'             => $row['tld'],
                'expiration_date' => $row['expiration_date'],
                'reg_lock'        => $row['reg_lock'],
                'renew_flag'      => $row['renew_flag'],
            );
        }

        $total = intval( $total );

        wp_send_json_success( array(
            'domains'  => $synced,
            'total'    => $total,
            'page'     => $page,
            'has_more' => ( $offset + $per_page ) < $total,
        ) );
    }

    public function ajax_lookup_owner() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nonce'] ) ), 'skyhshoso_dm_lookup_owner' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ) );
        }

        $domain = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
        if ( empty( $domain ) ) {
            wp_send_json_error( array( 'message' => 'No domain specified.' ) );
        }

        $parts = explode( '.', $domain, 2 );
        if ( count( $parts ) !== 2 ) {
            wp_send_json_error( array( 'message' => 'Invalid domain format.' ) );
        }

        try {
            $contacts = SkyHSHOSO_Enom_Domain_Sync::instance()->get_contacts( $parts[0], $parts[1] );
        } catch ( Exception $e ) {
            wp_send_json_success( array( 'email' => '', 'user' => null, 'error' => $e->getMessage() ) );
            return;
        }

        if ( isset( $contacts['error'] ) ) {
            wp_send_json_success( array( 'email' => '', 'user' => null, 'error' => $contacts['error'] ) );
            return;
        }

        $registrant_email = isset( $contacts['registrant']['email'] ) ? $contacts['registrant']['email'] : '';
        if ( empty( $registrant_email ) ) {
            wp_send_json_success( array( 'email' => '', 'user' => null, 'error' => 'No registrant email found.' ) );
            return;
        }

        $user = get_user_by( 'email', $registrant_email );
        if ( $user ) {
            wp_send_json_success( array(
                'email' => $registrant_email,
                'user'  => array(
                    'id'    => $user->ID,
                    'name'  => $user->display_name . ' (' . $user->user_login . ')',
                    'email' => $user->user_email,
                ),
            ) );
        } else {
            wp_send_json_success( array(
                'email' => $registrant_email,
                'user'  => null,
            ) );
        }
    }

    public function ajax_create_user() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nonce'] ) ), 'skyhshoso_dm_create_user' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ) );
        }

        if ( ! current_user_can( 'create_users' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        if ( empty( $email ) || ! is_email( $email ) ) {
            wp_send_json_error( array( 'message' => 'A valid email address is required.' ) );
        }

        if ( email_exists( $email ) ) {
            wp_send_json_error( array( 'message' => 'A user with this email already exists.' ) );
        }

        $parts = explode( '@', $email );
        $username = sanitize_user( $parts[0], true );
        $username = str_replace( array( '-', '.' ), '_', $username );
        $username = $username ?: 'user_' . wp_generate_password( 6, false );

        if ( username_exists( $username ) ) {
            $username = $username . '_' . wp_generate_password( 4, false );
        }

        $password = wp_generate_password();
        $first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
        $last_name = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';

        $user_id = wp_insert_user( array(
            'user_login'   => $username,
            'user_email'   => $email,
            'user_pass'    => $password,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'display_name' => trim( "$first_name $last_name" ) ?: $email,
            'role'         => 'customer',
        ) );

        if ( is_wp_error( $user_id ) ) {
            wp_send_json_error( array( 'message' => $user_id->get_error_message() ) );
        }

        wp_send_json_success( array(
            'user_id'  => $user_id,
            'username' => $username,
            'name'     => $first_name . ' ' . $last_name,
            'email'    => $email,
            'password' => $password,
        ) );
    }

    public function format_domain_data( $d ) {
        $owner_id = get_post_field( 'post_author', $d->ID );
        $owner = get_user_by( 'id', $owner_id );

        $domain = get_post_meta( $d->ID, 'skyhshoso_domain_name', true );
        $product_id = get_post_meta( $d->ID, '_skyhshoso_domain_product_id', true );
        $subscription_id = get_post_meta( $d->ID, 'skyhshoso_subscription_id', true );

        $sub_status = '—';
        $sub_status_label = __( 'No Subscription', 'skyhs-hosting-solution' );
        if ( $subscription_id ) {
            $sub = skyhshoso_get_subscription( $subscription_id );
            if ( $sub ) {
                $sub_status = $sub->get_status();
                $sub_status_label = skyhshoso_get_subscription_status_name( $sub_status );
            }
        }

        $product_title = '—';
        $product_price = '';
        if ( $product_id ) {
            $prod = wc_get_product( $product_id );
            if ( $prod ) {
                $product_title = $prod->get_name();
                $product_price = html_entity_decode( wp_strip_all_tags( wc_price( $prod->get_price() ) ), ENT_QUOTES, 'UTF-8' );
            }
        }

        return array(
            'id'               => $d->ID,
            'title'            => $d->post_title,
            'status'           => $d->post_status,
            'owner_id'         => $owner_id,
            'owner_name'       => $owner ? $owner->display_name . ' (' . $owner->user_email . ')' : '—',
            'domain'           => $domain ?: $d->post_title,
            'product_id'       => $product_id,
            'product_title'    => $product_title,
            'product_price'    => $product_price,
            'subscription_id'  => $subscription_id ?: '',
            'sub_status'       => $sub_status,
            'sub_status_label' => $sub_status_label,
        );
    }
}

SkyHSHOSO_Domain_Manager::instance();
