<?php
/**
 * SkyHS Server Manager
 *
 * Custom admin page for managing WHM servers.
 * Replaces native post-new.php with guided UI.
 *
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

class SkyHSHOSO_Server_Manager {

    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

        // AJAX: create/update server
        add_action( 'wp_ajax_skyhshoso_save_server', array( $this, 'ajax_save_server' ) );
        // AJAX: delete server
        add_action( 'wp_ajax_skyhshoso_delete_server', array( $this, 'ajax_delete_server' ) );
        // AJAX: test WHM connection + sync
        add_action( 'wp_ajax_skyhshoso_test_whm', array( $this, 'ajax_test_whm' ) );
    }

    /**
     * Enqueue scripts/styles.
     */
    public function enqueue_scripts( $hook ) {
        $page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( false === strpos( $hook, 'skyhshoso-servers' ) && 'skyhshoso-servers' !== $page ) {
            return;
        }

        wp_enqueue_style(
            'skyhshoso-server-manager',
            SKYHSHOSO_PLUGIN_URL . 'assets/css/server-manager.css',
            array(),
            SKYHSHOSO_VERSION
        );

        wp_enqueue_script(
            'skyhshoso-server-manager',
            SKYHSHOSO_PLUGIN_URL . 'assets/js/server-manager.js',
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
            $last_sync = get_post_meta( $s->ID, '_skyhshoso_whm_last_sync_time', true );
            $last_error = get_post_meta( $s->ID, '_skyhshoso_whm_last_error', true );

            $server_ns = get_post_meta( $s->ID, '_skyhshoso_server_nameservers', true );
            $server_list[] = array(
                'id'          => $s->ID,
                'name'        => $s->post_title,
                'type'        => get_post_meta( $s->ID, '_skyhshoso_server_type', true ) ?: 'whm', // Added Type
                'host'        => get_post_meta( $s->ID, '_skyhshoso_whm_host', true ),
                'user'        => get_post_meta( $s->ID, '_skyhshoso_whm_user_id', true ),
                'token'       => get_post_meta( $s->ID, '_skyhshoso_whm_token', true ),
                'server_ip'   => get_post_meta( $s->ID, '_skyhshoso_server_ip', true ),
                'nameservers' => is_array( $server_ns ) ? $server_ns : array(),
                'plans'       => is_array( $plans ) ? count( $plans ) : 0,
                'plan_list'   => is_array( $plans ) ? $plans : array(),
                'last_sync'   => $last_sync ?: '',
                'error'       => $last_error ?: '',
                'has_token'   => ! empty( get_post_meta( $s->ID, '_skyhshoso_whm_token', true ) ),
            );
        }

        wp_localize_script(
            'skyhshoso-server-manager',
            'skyhshoso_sm',
            array(
                'ajax_url'          => admin_url( 'admin-ajax.php' ),
                'nonce_save'        => wp_create_nonce( 'skyhshoso_save_server' ),
                'nonce_delete'      => wp_create_nonce( 'skyhshoso_delete_server' ),
                'nonce_test'        => wp_create_nonce( 'skyhshoso_test_whm' ),
                'nonce_cpanel_sync' => wp_create_nonce( 'skyhshoso_cpanel_sync_fetch' ),
                'servers'           => $server_list,
                'strings'     => array(
                    'saving'       => __( 'Saving server...', 'skyhs-hosting-solution' ),
                    'testing'      => __( 'Testing connection...', 'skyhs-hosting-solution' ),
                    'deleting'     => __( 'Deleting server...', 'skyhs-hosting-solution' ),
                    'saved'        => __( 'Server saved successfully!', 'skyhs-hosting-solution' ),
                    'error'        => __( 'Error saving server.', 'skyhs-hosting-solution' ),
                    'confirm_delete' => __( 'Are you sure? This will remove this server. Hosting products using this server will need reassignment.', 'skyhs-hosting-solution' ),
                    'fill_fields'  => __( 'Please fill in all required fields.', 'skyhs-hosting-solution' ),
                ),
            )
        );
    }

    // -------------------------------------------------------------------------
    // Render page
    // -------------------------------------------------------------------------

    public function render_page() {
        $servers = get_posts( array(
            'post_type'      => 'skyhshoso_server',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );
        ?>
        <div class="wrap skyhshoso-sm-wrap">
            <h1><?php esc_html_e( 'Servers', 'skyhs-hosting-solution' ); ?></h1>
            <p><?php esc_html_e( 'Manage your WHM servers. Add a server to connect it, then create hosting products linked to its packages.', 'skyhs-hosting-solution' ); ?></p>

            <div id="skyhshoso-sm-notice" class="notice" style="display:none;"></div>

            <div id="skyhshoso-sm-app">
                <div class="skyhshoso-sm-form-panel">
                    <div class="skyhshoso-sm-form-header">
                        <h2 id="skyhshoso-sm-form-title"><?php esc_html_e( 'Add New Server', 'skyhs-hosting-solution' ); ?></h2>
                    </div>

                    <form id="skyhshoso-sm-form" class="skyhshoso-sm-form">
                        <input type="hidden" id="sm_server_id" name="server_id" value="0" />

                        <div class="skyhshoso-sm-section">
                            <h3><?php esc_html_e( 'Server Info', 'skyhs-hosting-solution' ); ?></h3>

                            <div class="skyhshoso-sm-row">
                                <div class="skyhshoso-sm-field">
                                    <label for="sm_name"><?php esc_html_e( 'Server Name', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                    <input type="text" id="sm_name" name="name" class="sm-input" placeholder="<?php esc_attr_e( 'e.g., US Server 1, EU Server', 'skyhs-hosting-solution' ); ?>" />
                                    <p class="sm-field-desc"><?php esc_html_e( 'A label to identify this server in dropdowns.', 'skyhs-hosting-solution' ); ?></p>
                                </div>
                            </div>

                            <div class="skyhshoso-sm-row">
                                <div class="skyhshoso-sm-field">
                                    <label for="sm_type"><?php esc_html_e( 'Control Panel Type', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                    <select id="sm_type" name="server_type" class="sm-input">
                                        <option value="whm">cPanel / WHM</option>
                                        <option value="hestiacp">HestiaCP</option>
                                    </select>
                                    <p class="sm-field-desc"><?php esc_html_e( 'Select the server control panel API.', 'skyhs-hosting-solution' ); ?></p>
                                </div>
                            </div>
                        </div>

                        <div class="skyhshoso-sm-section">
                            <h3 id="sm_section_connection_title"><?php esc_html_e( 'Connection Settings', 'skyhs-hosting-solution' ); ?></h3>
                            <p class="skyhshoso-sm-desc"><?php esc_html_e( 'Enter your API credentials to allow the dashboard to connect.', 'skyhs-hosting-solution' ); ?></p>

                            <div class="skyhshoso-sm-row">
                                <div class="skyhshoso-sm-field">
                                    <label id="label_sm_host" for="sm_host"><?php esc_html_e( 'Host / IP Address', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                    <input type="text" id="sm_host" name="host" class="sm-input" placeholder="<?php esc_attr_e( 'e.g., node1.example.com', 'skyhs-hosting-solution' ); ?>" />
                                    <p class="sm-field-desc"><?php esc_html_e( 'Server hostname or IP.', 'skyhs-hosting-solution' ); ?></p>
                                </div>
                            </div>

                            <div class="skyhshoso-sm-row">
                                <div class="skyhshoso-sm-field">
                                    <label for="sm_server_ip"><?php esc_html_e( 'Server IP', 'skyhs-hosting-solution' ); ?></label>
                                    <input type="text" id="sm_server_ip" name="server_ip" class="sm-input" placeholder="<?php esc_attr_e( 'e.g., 203.0.113.1', 'skyhs-hosting-solution' ); ?>" />
                                    <p class="sm-field-desc"><?php esc_html_e( 'Optional. Shown in the provisioning email so customers can point their domain to this IP instead of using nameservers.', 'skyhs-hosting-solution' ); ?></p>
                                </div>
                            </div>

                            <div class="skyhshoso-sm-row">
                                <div class="skyhshoso-sm-field">
                                    <label><?php esc_html_e( 'Default Nameservers', 'skyhs-hosting-solution' ); ?></label>
                                    <div id="sm-ns-fields">
                                        <input type="text" name="nameservers[]" class="sm-input sm-ns-input" placeholder="ns1.example.com" value="" style="margin-bottom:4px;" />
                                        <input type="text" name="nameservers[]" class="sm-input sm-ns-input" placeholder="ns2.example.com" value="" style="margin-bottom:4px;" />
                                        <input type="text" name="nameservers[]" class="sm-input sm-ns-input" placeholder="ns3.example.com" value="" style="margin-bottom:4px;" />
                                        <input type="text" name="nameservers[]" class="sm-input sm-ns-input" placeholder="ns4.example.com" value="" style="margin-bottom:4px;" />
                                    </div>
                                    <p class="sm-field-desc"><?php esc_html_e( 'Optional. Used in the provisioning email. Overrides the global eNom nameservers for this server.', 'skyhs-hosting-solution' ); ?></p>
                                </div>
                            </div>

                            <div class="skyhshoso-sm-row skyhshoso-sm-row-cols-2">
                                <div class="skyhshoso-sm-field">
                                    <label id="label_sm_user" for="sm_user"><?php esc_html_e( 'Username', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                    <input type="text" id="sm_user" name="user" class="sm-input" placeholder="<?php esc_attr_e( 'e.g., root', 'skyhs-hosting-solution' ); ?>" />
                                </div>
                                <div class="skyhshoso-sm-field">
                                    <label id="label_sm_token" for="sm_token"><?php esc_html_e( 'API Token', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                    <input type="password" id="sm_token" name="token" class="sm-input" />
                                    <p id="desc_sm_token" class="sm-field-desc"><?php esc_html_e( 'Generate from WHM → API Tokens.', 'skyhs-hosting-solution' ); ?></p>
                                </div>
                            </div>
                        </div>

                        <div class="skyhshoso-sm-actions">
                            <div class="skyhshoso-sm-actions-left">
                                <span id="sm-loader" class="spinner" style="float:none;margin:0;"></span>
                                <span id="sm-test-result" class="sm-test-result"></span>
                            </div>
                            <div class="skyhshoso-sm-actions-right">
                                <button type="button" id="sm-test-btn" class="button">
                                    <?php esc_html_e( 'Test & Sync', 'skyhs-hosting-solution' ); ?>
                                </button>
                                <button type="submit" id="sm-submit" class="button button-primary button-large">
                                    <?php esc_html_e( 'Save Server', 'skyhs-hosting-solution' ); ?>
                                </button>
                            </div>
                        </div>

                        <div id="sm-test-results" class="skyhshoso-sm-test-results" style="display:none;">
                            <h4><?php esc_html_e( 'Connection Result', 'skyhs-hosting-solution' ); ?></h4>
                            <div id="sm-test-status"></div>
                            <div id="sm-test-plans"></div>
                        </div>
                    </form>
                </div>

                <div class="skyhshoso-sm-list-panel">
                    <div class="skyhshoso-sm-list-header">
                        <h2><?php esc_html_e( 'Connected Servers', 'skyhs-hosting-solution' ); ?></h2>
                    </div>

                    <?php if ( empty( $servers ) ) : ?>
                        <div class="skyhshoso-sm-empty">
                            <p><?php esc_html_e( 'No servers added yet. Fill out the form and save your first server.', 'skyhs-hosting-solution' ); ?></p>
                        </div>
                    <?php else : ?>
                        <div id="skyhshoso-sm-server-list" class="skyhshoso-sm-server-list">
                            <?php foreach ( $servers as $server ) :
                                $type = get_post_meta( $server->ID, '_skyhshoso_server_type', true ) ?: 'whm';
                                $plans = get_post_meta( $server->ID, '_skyhshoso_whm_default_package_names', true );
                                $plans = is_array( $plans ) ? $plans : array();
                                $last_error = get_post_meta( $server->ID, '_skyhshoso_whm_last_error', true );
                                $host = get_post_meta( $server->ID, '_skyhshoso_whm_host', true );
                            ?>
                                <div class="skyhshoso-sm-server-card" data-id="<?php echo esc_attr( $server->ID ); ?>">
                                    <div class="sm-card-top">
                                        <h3>
                                            <?php echo esc_html( $server->post_title ); ?> 
                                            <span style="font-size:10px; background:#e2e8f0; padding:2px 6px; border-radius:4px; vertical-align:middle; font-weight:600; text-transform:uppercase; margin-left:8px;"><?php echo esc_html($type); ?></span>
                                        </h3>
                                        <div class="sm-card-status">
                                            <?php if ( ! empty( $last_error ) ) : ?>
                                                <span class="sm-status-dot sm-status-error"></span>
                                                <span class="sm-status-text error"><?php esc_html_e( 'Sync Error', 'skyhs-hosting-solution' ); ?></span>
                                            <?php else : ?>
                                                <span class="sm-status-dot sm-status-ok"></span>
                                                <span class="sm-status-text ok"><?php esc_html_e( 'Connected', 'skyhs-hosting-solution' ); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="sm-card-body">
                                        <div class="sm-card-detail">
                                            <span class="sm-detail-label"><?php esc_html_e( 'Host:', 'skyhs-hosting-solution' ); ?></span>
                                            <span class="sm-detail-value"><?php echo esc_html( $host ?: '—' ); ?></span>
                                        </div>
                                        <div class="sm-card-detail">
                                            <span class="sm-detail-label"><?php esc_html_e( 'IP:', 'skyhs-hosting-solution' ); ?></span>
                                            <span class="sm-detail-value"><?php echo esc_html( get_post_meta( $server->ID, '_skyhshoso_server_ip', true ) ?: '—' ); ?></span>
                                        </div>
                                        <?php if ($type === 'whm') : ?>
                                            <div class="sm-card-detail">
                                                <span class="sm-detail-label"><?php esc_html_e( 'Packages:', 'skyhs-hosting-solution' ); ?></span>
                                                <span class="sm-detail-value"><?php echo count( $plans ) . ' ' . esc_html__( 'found', 'skyhs-hosting-solution' ); ?></span>
                                            </div>
                                            <?php if ( ! empty( $plans ) ) : ?>
                                                <div class="sm-card-plans">
                                                    <?php foreach ( array_slice( $plans, 0, 4 ) as $pkg ) : ?>
                                                        <span class="sm-plan-tag"><?php echo esc_html( ucwords( str_replace( '_', ' ', $pkg ) ) ); ?></span>
                                                    <?php endforeach; ?>
                                                    <?php if ( count( $plans ) > 4 ) : ?>
                                                        <span class="sm-plan-tag sm-plan-more">+<?php echo count( $plans ) - 4; ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="sm-card-actions">
                                        <button type="button" class="button button-small sm-edit-server" data-id="<?php echo esc_attr( $server->ID ); ?>">
                                            <?php esc_html_e( 'Edit', 'skyhs-hosting-solution' ); ?>
                                        </button>
                                        <button type="button" class="button button-small sm-sync-server" data-id="<?php echo esc_attr( $server->ID ); ?>">
                                            <?php esc_html_e( 'Sync', 'skyhs-hosting-solution' ); ?>
                                        </button>
                                        <button type="button" class="button button-small sm-delete-server" data-id="<?php echo esc_attr( $server->ID ); ?>">
                                            <?php esc_html_e( 'Delete', 'skyhs-hosting-solution' ); ?>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Dynamic Label Swapping based on Server Type
            $('#sm_type').on('change', function() {
                var type = $(this).val();
                if (type === 'hestiacp') {
                    $('#label_sm_host').html('HestiaCP Host / IP <span class="req">*</span>');
                    $('#label_sm_user').html('HestiaCP Access Key ID <span class="req">*</span>');
                    $('#label_sm_token').html('HestiaCP Secret Key <span class="req">*</span>');
                    $('#desc_sm_token').html('Generate from HestiaCP Admin -> Access Keys.');
                    $('#sm_user').attr('placeholder', 'e.g., admin_xxxxxx');
                } else {
                    $('#label_sm_host').html('WHM Host <span class="req">*</span>');
                    $('#label_sm_user').html('WHM Username <span class="req">*</span>');
                    $('#label_sm_token').html('WHM API Token <span class="req">*</span>');
                    $('#desc_sm_token').html('Generate from WHM → API Tokens.');
                    $('#sm_user').attr('placeholder', 'e.g., root');
                }
            });

            // Hijack the "Edit Server" button to prepopulate the type dropdown properly!
            $(document).on('click', '.sm-edit-server', function() {
                var serverId = $(this).data('id');
                // Find the server object in the JS array that was printed via wp_localize_script
                var serverData = skyhshoso_sm.servers.find(s => s.id == serverId);
                
                if (serverData && serverData.type) {
                    $('#sm_type').val(serverData.type).trigger('change');
                } else {
                    $('#sm_type').val('whm').trigger('change');
                }
            });
            
            // Run once on load to ensure defaults are set
            $('#sm_type').trigger('change');
        });
        </script>
        <?php
    }

// -------------------------------------------------------------------------
    // AJAX handlers
    // -------------------------------------------------------------------------

    public function ajax_save_server() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_save_server' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'skyhs-hosting-solution' ) ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'skyhs-hosting-solution' ) ) );
        }

        $server_id = isset( $_POST['server_id'] ) ? intval( $_POST['server_id'] ) : 0;
        $name      = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $type      = isset( $_POST['server_type'] ) ? sanitize_text_field( wp_unslash( $_POST['server_type'] ) ) : 'whm';
        $host      = isset( $_POST['host'] ) ? sanitize_text_field( wp_unslash( $_POST['host'] ) ) : '';
        $user      = isset( $_POST['user'] ) ? sanitize_text_field( wp_unslash( $_POST['user'] ) ) : '';
        $token     = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
        $server_ip = isset( $_POST['server_ip'] ) ? sanitize_text_field( wp_unslash( $_POST['server_ip'] ) ) : '';
        $nameservers = isset( $_POST['nameservers'] ) && is_array( $_POST['nameservers'] ) ? $_POST['nameservers'] : array();

        if ( empty( $name ) || empty( $host ) || empty( $user ) ) {
            wp_send_json_error( array( 'message' => __( 'All fields are required.', 'skyhs-hosting-solution' ) ) );
        }

        if ( $server_id ) {
            wp_update_post( array( 'ID' => $server_id, 'post_title' => $name ) );
        } else {
            if ( empty( $token ) ) {
                wp_send_json_error( array( 'message' => __( 'Token is required for new servers.', 'skyhs-hosting-solution' ) ) );
            }
            $server_id = wp_insert_post( array(
                'post_type'   => 'skyhshoso_server',
                'post_title'  => $name,
                'post_status' => 'publish',
            ) );
            if ( is_wp_error( $server_id ) ) {
                wp_send_json_error( array( 'message' => $server_id->get_error_message() ) );
            }
        }

        // Save server metadata
        update_post_meta( $server_id, '_skyhshoso_server_type', $type );
        update_post_meta( $server_id, '_skyhshoso_whm_user_id', $user );

        if ( $token === 'EXISTING_TOKEN_PLACEHOLDER' || ( $server_id && empty( $token ) ) ) {
            $token = get_post_meta( $server_id, '_skyhshoso_whm_token', true );
        }
        update_post_meta( $server_id, '_skyhshoso_whm_token', $token );
        update_post_meta( $server_id, '_skyhshoso_whm_host', $host );

        if ( ! empty( $server_ip ) ) {
            update_post_meta( $server_id, '_skyhshoso_server_ip', $server_ip );
        } else {
            delete_post_meta( $server_id, '_skyhshoso_server_ip' );
        }

        $sanitized_ns = array();
        foreach ( $nameservers as $ns ) {
            $sanitized_ns[] = sanitize_text_field( wp_unslash( $ns ) );
        }
        if ( ! empty( array_filter( $sanitized_ns ) ) ) {
            update_post_meta( $server_id, '_skyhshoso_server_nameservers', $sanitized_ns );
        } else {
            delete_post_meta( $server_id, '_skyhshoso_server_nameservers' );
        }

        // 1. Unified Factory Package Sync!
        $this->sync_server_packages( $server_id );

        // 2. WHM specific cPanel account cache sync (we will adapt this for Hestia later)
        if ( $type === 'whm' && class_exists( 'SkyHSHOSO_CPanel_Sync' ) ) {
            SkyHSHOSO_CPanel_Sync::instance()->sync_server_accounts( $server_id );
        }

        $plan_count = count( get_post_meta( $server_id, '_skyhshoso_whm_default_package_names', true ) ?: array() );

        wp_send_json_success( array(
            'message'   => sprintf( __( 'Server "%s" saved. %d packages synced.', 'skyhs-hosting-solution' ), $name, $plan_count ),
            'server_id' => $server_id,
        ) );
    }

    public function ajax_delete_server() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_delete_server' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'skyhs-hosting-solution' ) ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'skyhs-hosting-solution' ) ) );
        }

        $server_id = isset( $_POST['server_id'] ) ? intval( $_POST['server_id'] ) : 0;
        if ( ! $server_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid server.', 'skyhs-hosting-solution' ) ) );
        }

        wp_delete_post( $server_id, true );
        wp_send_json_success( array( 'message' => __( 'Server deleted.', 'skyhs-hosting-solution' ) ) );
    }

    public function ajax_test_whm() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_test_whm' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'skyhs-hosting-solution' ) ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'skyhs-hosting-solution' ) ) );
        }

        $type  = isset( $_POST['server_type'] ) ? sanitize_text_field( wp_unslash( $_POST['server_type'] ) ) : 'whm';
        $host  = isset( $_POST['host'] ) ? sanitize_text_field( wp_unslash( $_POST['host'] ) ) : '';
        $user  = isset( $_POST['user'] ) ? sanitize_text_field( wp_unslash( $_POST['user'] ) ) : '';
        $token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

        if ( empty( $host ) || empty( $user ) || empty( $token ) ) {
            wp_send_json_error( array( 'message' => __( 'Fill credentials first.', 'skyhs-hosting-solution' ) ) );
        }

        // Initialize the correct Driver manually for the connection test
        if ( $type === 'hestiacp' ) {
            $driver = new SkyHSHOSO_HestiaCP_Driver($host, $user, $token);
        } else {
            $driver = new SkyHSHOSO_WHM_Driver($host, $user, $token);
        }

        // 1. Test Connection
        $test = $driver->test_connection();
        if ( is_wp_error( $test ) ) {
            wp_send_json_error( array( 'message' => $test->get_error_message() ) );
        }

        // 2. Fetch Packages
        $packages = $driver->get_packages();
        if ( is_wp_error( $packages ) ) {
            wp_send_json_error( array( 'message' => $packages->get_error_message() ) );
        }

        // 3. Format Packages for the UI Dropdown
        $formatted = array();
        foreach ( $packages as $pkg ) {
            $formatted[ $pkg ] = ucwords( str_replace( '_', ' ', $pkg ) );
        }

        wp_send_json_success( array(
            'message' => sprintf( __( 'Connected! Found %d packages ready to sync.', 'skyhs-hosting-solution' ), count( $formatted ) ),
            'plans'   => $formatted,
        ) );
    }

    private function sync_server_packages( $server_id ) {
        $driver = SkyHSHOSO_Provider_Factory::get_driver($server_id);
        
        if ( is_wp_error($driver) ) {
            update_post_meta( $server_id, '_skyhshoso_whm_last_error', $driver->get_error_message() );
            return;
        }

        $packages = $driver->get_packages();
        update_post_meta( $server_id, '_skyhshoso_whm_last_sync_time', current_time( 'mysql' ) );

        if ( ! is_wp_error($packages) && is_array($packages) ) {
            // By using the original WHM meta key, the WooCommerce products editor will automatically list HestiaCP packages perfectly!
            update_post_meta( $server_id, '_skyhshoso_whm_default_package_names', $packages );
            delete_post_meta( $server_id, '_skyhshoso_whm_last_error' );
        } else {
            $err = is_wp_error($packages) ? $packages->get_error_message() : __( 'No packages found.', 'skyhs-hosting-solution' );
            update_post_meta( $server_id, '_skyhshoso_whm_last_error', $err );
        }
    }
}

SkyHSHOSO_Server_Manager::instance();