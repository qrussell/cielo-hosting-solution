<?php
/**
 * SkyHS Server Manager
 *
 * Custom admin page for managing WHM, HestiaCP, and WordOps servers.
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
        // AJAX: test connection + sync
        add_action( 'wp_ajax_skyhshoso_test_whm', array( $this, 'ajax_test_whm' ) );
    }

    /**
     * Enqueue scripts/styles.
     */
    public function enqueue_scripts( $hook ) {
        $page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; 
        if ( false === strpos( $hook, 'skyhshoso-servers' ) && 'skyhshoso-servers' !== $page ) {
            return;
        }

        wp_enqueue_style(
            'skyhshoso-server-manager',
            SKYHSHOSO_PLUGIN_URL . 'assets/css/server-manager.css',
            array(),
            SKYHSHOSO_VERSION
        );

        // CLOUDFLARE BYPASS: We deregister the old physical file to prevent collisions
        wp_deregister_script('skyhshoso-server-manager');

        // Register our new "virtual" script to hold the localized variables
        wp_register_script( 'skyhshoso-server-manager-inline', false );
        wp_enqueue_script( 'skyhshoso-server-manager-inline' );

        $servers = get_posts( array(
            'post_type'      => 'skyhshoso_server',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );

        $server_list = array();
        foreach ( $servers as $s ) {
            $type = get_post_meta( $s->ID, '_skyhshoso_server_type', true ) ?: 'whm';
            $port = get_post_meta( $s->ID, '_skyhshoso_server_port', true );
            
            if (empty($port)) {
                $port = ($type === 'hestiacp') ? '8083' : (($type === 'wordops') ? '22' : '2087');
            }

            $plans = get_post_meta( $s->ID, '_skyhshoso_whm_default_package_names', true );
            $last_sync = get_post_meta( $s->ID, '_skyhshoso_whm_last_sync_time', true );
            $last_error = get_post_meta( $s->ID, '_skyhshoso_whm_last_error', true );
            $server_ns = get_post_meta( $s->ID, '_skyhshoso_server_nameservers', true );
            
            $server_list[] = array(
                'id'          => $s->ID,
                'name'        => $s->post_title,
                'type'        => $type,
                'host'        => get_post_meta( $s->ID, '_skyhshoso_whm_host', true ),
                'port'        => $port,
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
            'skyhshoso-server-manager-inline',
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
                                        <option value="wordops">WordOps</option>
                                    </select>
                                    <p class="sm-field-desc"><?php esc_html_e( 'Select the server control panel API.', 'skyhs-hosting-solution' ); ?></p>
                                </div>
                            </div>
                        </div>

                        <div class="skyhshoso-sm-section">
                            <h3 id="sm_section_connection_title"><?php esc_html_e( 'Connection Settings', 'skyhs-hosting-solution' ); ?></h3>
                            <p class="skyhshoso-sm-desc"><?php esc_html_e( 'Enter your API credentials to allow the dashboard to connect.', 'skyhs-hosting-solution' ); ?></p>

                            <div class="skyhshoso-sm-row skyhshoso-sm-row-cols-2">
                                <div class="skyhshoso-sm-field">
                                    <label id="label_sm_host" for="sm_host"><?php esc_html_e( 'Host / IP Address', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                    <input type="text" id="sm_host" name="host" class="sm-input" placeholder="<?php esc_attr_e( 'e.g., node1.example.com', 'skyhs-hosting-solution' ); ?>" />
                                </div>
                                <div class="skyhshoso-sm-field">
                                    <label for="sm_port"><?php esc_html_e( 'API Port', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                    <input type="text" id="sm_port" name="port" class="sm-input" value="2087" />
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
                                <span id="sm-loader" class="spinner" style="float:none;margin:0;display:none;"></span>
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
                                $port = get_post_meta( $server->ID, '_skyhshoso_server_port', true );
                                
                                if (empty($port)) {
                                    $port = ($type === 'hestiacp') ? '8083' : (($type === 'wordops') ? '22' : '2087');
                                }

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
                                            <span class="sm-detail-value"><?php echo esc_html( $host ?: '—' ); ?> : <strong><?php echo esc_html($port); ?></strong></span>
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
            'use strict';
            var data = window.skyhshoso_sm || {};

            $('#skyhshoso-sm-form').off('submit');
            $(document).off('click', '.sm-edit-server');
            $(document).off('click', '.sm-sync-server');
            $('#sm-test-btn').off('click');

            function showNotice(type, msg) {
                var $n = $('#skyhshoso-sm-notice');
                $n.removeClass('notice-success notice-error notice-info').addClass('notice-' + type).html('<p>' + msg + '</p>').show();
                if (type === 'success') $n.delay(6000).fadeOut();
            }

            $(document).on('click', '.sm-edit-server', function() {
                var id = $(this).data('id');
                var server = null;
                $.each(data.servers, function(i, s) {
                    if (String(s.id) === String(id)) { server = s; return false; }
                });

                if (!server) return;

                $('#sm_server_id').val(server.id);
                $('#sm_name').val(server.name);
                $('#sm_host').val(server.host);
                $('#sm_port').val(server.port || '2087');
                $('#sm_user').val(server.user);
                $('#sm_token').val(server.token);
                $('#sm_server_ip').val(server.server_ip || '');
                
                if (server.type) {
                    $('#sm_type').val(server.type).trigger('change');
                } else {
                    $('#sm_type').val('whm').trigger('change');
                }

                if (server.nameservers && server.nameservers.length > 0) {
                    $('.sm-ns-input').each(function(i) {
                        $(this).val(server.nameservers[i] || '');
                    });
                }
                $('#skyhshoso-sm-form-title').text('Edit Server');
                $('#sm-submit').text('Update Server');
                $('#skyhshoso-sm-form').data('edit-mode', '1');

                if (server.plan_list && server.plan_list.length > 0) {
                    var html = '<p><strong>Saved packages:</strong></p><div class="sm-plan-tags">';
                    $.each(server.plan_list, function(i, pkg) {
                        html += '<span class="sm-plan-tag">' + pkg.replace(/_/g, ' ') + '</span>';
                    });
                    html += '</div>';
                    $('#sm-test-plans').html(html);
                    $('#sm-test-status').html('<div class="notice notice-success inline"><p>Server has ' + server.plan_list.length + ' packages.</p></div>');
                    $('#sm-test-results').show();
                } else {
                    $('#sm-test-results').hide();
                }

                $('html, body').animate({ scrollTop: $('#skyhshoso-sm-form').offset().top - 40 }, 400);
            });

            $('#sm_type').on('change', function() {
                var type = $(this).val();
                var $port = $('#sm_port');
                var currentPort = $port.val();

                if (type === 'hestiacp') {
                    $('#label_sm_host').html('HestiaCP Host / IP <span class="req">*</span>');
                    $('#label_sm_user').html('HestiaCP Access Key ID <span class="req">*</span>');
                    $('#label_sm_token').html('HestiaCP Secret Key <span class="req">*</span>');
                    $('#sm_user').attr('placeholder', 'e.g., admin_xxxxxx');
                    if (currentPort === '2087' || currentPort === '22' || currentPort === '') $port.val('8083');

                } else if (type === 'wordops') {
                    $('#label_sm_host').html('WordOps Host / IP <span class="req">*</span>');
                    $('#label_sm_user').html('SSH Username <span class="req">*</span>');
                    $('#label_sm_token').html('SSH Key / Password <span class="req">*</span>');
                    $('#sm_user').attr('placeholder', 'e.g., root');
                    if (currentPort === '2087' || currentPort === '8083' || currentPort === '') $port.val('22');

                } else {
                    $('#label_sm_host').html('WHM Host <span class="req">*</span>');
                    $('#label_sm_user').html('WHM Username <span class="req">*</span>');
                    $('#label_sm_token').html('WHM API Token <span class="req">*</span>');
                    $('#sm_user').attr('placeholder', 'e.g., root');
                    if (currentPort === '8083' || currentPort === '22' || currentPort === '') $port.val('2087');
                }
            });

            $('#sm_type').trigger('change');

            $('#sm-test-btn').on('click', function(e) {
                e.preventDefault();
                var type = $('#sm_type').val(); 
                var host = $('#sm_host').val().trim();
                var port = $('#sm_port').val().trim(); 
                var user = $('#sm_user').val().trim();
                var token = $('#sm_token').val().trim();

                if (!host || !user || !token) {
                    $('#sm-test-result').text('Fill credentials first.').addClass('error');
                    return;
                }

                var $btn = $(this);
                var $result = $('#sm-test-result');
                $btn.prop('disabled', true).text('Testing...');
                $result.text(data.strings.testing).removeClass('success error');
                $('#sm-loader').css('display', 'inline-block');
                $('#sm-test-results').hide();

                $.post(data.ajax_url, {
                    action: 'skyhshoso_test_whm',
                    nonce: data.nonce_test,
                    server_type: type, 
                    host: host,
                    port: port, 
                    user: user,
                    token: token
                }, function(resp) {
                    if (resp.success) {
                        $result.text(resp.data.message).addClass('success');
                        var $plans = $('#sm-test-plans');
                        $plans.empty();
                        if (resp.data.plans && Object.keys(resp.data.plans).length > 0) {
                            var html = '<p><strong>Packages found:</strong></p><div class="sm-plan-tags">';
                            $.each(resp.data.plans, function(key, label) {
                                html += '<span class="sm-plan-tag">' + label + '</span>';
                            });
                            html += '</div>';
                            $plans.html(html);
                        } else {
                            $plans.html('<p>No packages with default feature list found.</p>');
                        }
                        $('#sm-test-results').show();
                        $('#sm-test-status').html('<div class="notice notice-success inline"><p>' + resp.data.message + '</p></div>');
                    } else {
                        $result.text(resp.data.message || 'Connection failed.').addClass('error');
                        $('#sm-test-results').show();
                        $('#sm-test-status').html('<div class="notice notice-error inline"><p>' + (resp.data.message || 'Connection failed.') + '</p></div>');
                        $('#sm-test-plans').empty();
                    }
                }).always(function() {
                    $btn.prop('disabled', false).text('Test & Sync');
                    $('#sm-loader').hide();
                });
            });

            $(document).on('click', '.sm-sync-server', function() {
                var id = $(this).data('id');
                var $btn = $(this);
                var server = null;
                $.each(data.servers, function(i, s) {
                    if (String(s.id) === String(id)) { server = s; return false; }
                });

                if (!server) return;
                $btn.prop('disabled', true).text('Syncing...');

                $.post(data.ajax_url, {
                    action: 'skyhshoso_save_server',
                    nonce: data.nonce_save,
                    server_id: id,
                    name: server.name,
                    server_type: server.type, 
                    host: server.host,
                    port: server.port || '', 
                    user: server.user,
                    token: 'EXISTING_TOKEN_PLACEHOLDER', 
                    server_ip: server.server_ip || '',
                    nameservers: server.nameservers || []
                }, function(resp) {
                    if (resp.success) {
                        showNotice('success', resp.data.message);
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        showNotice('error', resp.data.message);
                        $btn.prop('disabled', false).text('Sync');
                    }
                });
            });

            $('#skyhshoso-sm-form').on('submit', function(e) {
                e.preventDefault();

                var name = $('#sm_name').val().trim();
                var type = $('#sm_type').val(); 
                var host = $('#sm_host').val().trim();
                var port = $('#sm_port').val().trim(); 
                var user = $('#sm_user').val().trim();
                var token = $('#sm_token').val().trim();
                var serverIp = $('#sm_server_ip').val().trim();
                var nameservers = [];
                $('.sm-ns-input').each(function() {
                    if ($(this).val().trim() !== '') {
                        nameservers.push($(this).val().trim());
                    }
                });
                var serverId = $('#sm_server_id').val();

                var isEdit = $('#skyhshoso-sm-form').data('edit-mode') === '1';

                if (!name || !host || !user) {
                    showNotice('error', data.strings.fill_fields);
                    return;
                }

                if (!isEdit && !token) {
                    showNotice('error', data.strings.fill_fields);
                    return;
                }

                if (isEdit && !token) {
                    token = 'EXISTING_TOKEN_PLACEHOLDER';
                }

                var $btn = $('#sm-submit');
                var $loader = $('#sm-loader'); 
                $btn.prop('disabled', true).text('Saving...');
                $loader.css('display', 'inline-block');
                showNotice('info', data.strings.saving);

                $.post(data.ajax_url, {
                    action: 'skyhshoso_save_server',
                    nonce: data.nonce_save,
                    server_id: serverId,
                    name: name,
                    server_type: type, 
                    host: host,
                    port: port, 
                    user: user,
                    token: token,
                    server_ip: serverIp,
                    nameservers: nameservers
                }, function(resp) {
                    if (resp.success) {
                        showNotice('success', resp.data.message);
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        showNotice('error', resp.data.message);
                    }
                }).always(function() {
                    $btn.prop('disabled', false).text('Save Server');
                    $loader.hide();
                });
            });
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
        $port      = isset( $_POST['port'] ) ? sanitize_text_field( wp_unslash( $_POST['port'] ) ) : ''; 
        $user      = isset( $_POST['user'] ) ? sanitize_text_field( wp_unslash( $_POST['user'] ) ) : '';
        $token     = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
        $server_ip = isset( $_POST['server_ip'] ) ? sanitize_text_field( wp_unslash( $_POST['server_ip'] ) ) : '';
        $nameservers = isset( $_POST['nameservers'] ) && is_array( $_POST['nameservers'] ) ? $_POST['nameservers'] : array();

        if ( empty( $name ) || empty( $host ) || empty( $user ) ) {
            wp_send_json_error( array( 'message' => __( 'All fields are required.', 'skyhs-hosting-solution' ) ) );
        }

        if ( empty($port) ) {
            $port = ($type === 'hestiacp') ? '8083' : (($type === 'wordops') ? '22' : '2087');
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

        update_post_meta( $server_id, '_skyhshoso_server_type', $type );
        update_post_meta( $server_id, '_skyhshoso_server_port', $port );
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

        $this->sync_server_packages( $server_id );

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
        $port  = isset( $_POST['port'] ) ? sanitize_text_field( wp_unslash( $_POST['port'] ) ) : '';
        $user  = isset( $_POST['user'] ) ? sanitize_text_field( wp_unslash( $_POST['user'] ) ) : '';
        $token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

        if ( empty($port) ) {
            $port = ($type === 'hestiacp') ? '8083' : (($type === 'wordops') ? '22' : '2087');
        }

        if ( empty( $host ) || empty( $user ) || empty( $token ) ) {
            wp_send_json_error( array( 'message' => __( 'Fill credentials first.', 'skyhs-hosting-solution' ) ) );
        }

        if (!class_exists('SkyHSHOSO_Provider_Factory')) {
            require_once dirname(__FILE__) . '/class-hosting-provider-factory.php';
        }

        if ( $type === 'hestiacp' ) {
            if (!class_exists('SkyHSHOSO_HestiaCP_Driver')) {
                require_once dirname(__FILE__) . '/drivers/class-hestiacp-driver.php';
            }
            $driver = new SkyHSHOSO_HestiaCP_Driver($host, $user, $token, $port);
        } elseif ( $type === 'wordops' ) {
            wp_send_json_error( array( 'message' => 'WordOps driver is currently under development.' ) );
            return;
        } else {
            if (!class_exists('SkyHSHOSO_WHM_Driver')) {
                require_once dirname(__FILE__) . '/drivers/class-whm-driver.php';
            }
            $driver = new SkyHSHOSO_WHM_Driver($host, $user, $token, $port);
        }

        $test = $driver->test_connection();
        if ( is_wp_error( $test ) ) {
            wp_send_json_error( array( 'message' => $test->get_error_message() ) );
        }

        $packages = $driver->get_packages();
        if ( is_wp_error( $packages ) ) {
            wp_send_json_error( array( 'message' => $packages->get_error_message() ) );
        }

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
        if (!class_exists('SkyHSHOSO_Provider_Factory')) {
            require_once dirname(__FILE__) . '/class-hosting-provider-factory.php';
        }
        
        $driver = SkyHSHOSO_Provider_Factory::get_driver($server_id);
        
        if ( is_wp_error($driver) ) {
            update_post_meta( $server_id, '_skyhshoso_whm_last_error', $driver->get_error_message() );
            return;
        }

        $packages = $driver->get_packages();
        update_post_meta( $server_id, '_skyhshoso_whm_last_sync_time', current_time( 'mysql' ) );

        if ( ! is_wp_error($packages) && is_array($packages) ) {
            update_post_meta( $server_id, '_skyhshoso_whm_default_package_names', $packages );
            delete_post_meta( $server_id, '_skyhshoso_whm_last_error' );
        } else {
            $err = is_wp_error($packages) ? $packages->get_error_message() : __( 'No packages found.', 'skyhs-hosting-solution' );
            update_post_meta( $server_id, '_skyhshoso_whm_last_error', $err );
        }
    }
}

SkyHSHOSO_Server_Manager::instance();