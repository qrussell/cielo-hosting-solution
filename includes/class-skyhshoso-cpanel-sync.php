<?php

defined( 'ABSPATH' ) || exit;

class SkyHSHOSO_CPanel_Sync {

    private static $instance = null;

    const DB_TABLE = 'skyhshoso_cpanel_cache';
    const DB_VERSION_KEY = 'skyhshoso_cpanel_cache_db_version';
    const DB_VERSION = '1.0';

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'wp_ajax_skyhshoso_cpanel_sync_fetch', array( $this, 'ajax_sync_fetch' ) );
        add_action( 'wp_ajax_skyhshoso_cpanel_get_cached', array( $this, 'ajax_get_cached' ) );
        add_action( 'wp_ajax_skyhshoso_cpanel_delete_cached', array( $this, 'ajax_delete_cached' ) );
        add_action( 'admin_init', array( $this, 'maybe_create_table' ) );
    }

    public function maybe_create_table() {
        global $wpdb;
        $current = get_option( self::DB_VERSION_KEY, '' );
        if ( $current !== self::DB_VERSION ) {
            $table_name = $wpdb->prefix . self::DB_TABLE;
            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                server_id bigint(20) unsigned NOT NULL DEFAULT 0,
                username varchar(64) NOT NULL,
                domain varchar(255) DEFAULT '',
                plan varchar(255) DEFAULT '',
                suspended tinyint(1) DEFAULT 0,
                disk_used decimal(12,2) DEFAULT 0,
                disk_limit decimal(12,2) DEFAULT 0,
                startdate varchar(32) DEFAULT '',
                email varchar(255) DEFAULT '',
                raw_data longtext DEFAULT '',
                last_updated datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY server_username (server_id, username),
                KEY server_id (server_id),
                KEY domain (domain)
            ) {$charset_collate};";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta( $sql );

            update_option( self::DB_VERSION_KEY, self::DB_VERSION );
        }
    }

    public function enqueue_scripts( $hook ) {
        $page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
        if ( false === strpos( $hook, 'skyhshoso-cpanel-sync' ) && 'skyhshoso-cpanel-sync' !== $page ) {
            return;
        }

        wp_enqueue_style(
            'skyhshoso-cpanel-sync',
            SKYHSHOSO_PLUGIN_URL . 'assets/css/hosting-manager.css',
            array(),
            SKYHSHOSO_VERSION
        );

        wp_enqueue_script(
            'skyhshoso-cpanel-sync',
            SKYHSHOSO_PLUGIN_URL . 'assets/js/cpanel-sync.js',
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
            $host = get_post_meta( $s->ID, '_skyhshoso_whm_host', true );
            $last_sync = get_post_meta( $s->ID, '_skyhshoso_cpanel_last_sync_time', true );
            $server_list[] = array(
                'id'        => $s->ID,
                'name'      => $s->post_title,
                'host'      => $host ?: '',
                'last_sync' => $last_sync ?: '',
            );
        }

        wp_localize_script(
            'skyhshoso-cpanel-sync',
            'skyhshoso_cpanel_sync',
            array(
                'ajax_url'        => admin_url( 'admin-ajax.php' ),
                'nonce_fetch'     => wp_create_nonce( 'skyhshoso_cpanel_sync_fetch' ),
                'nonce_cached'    => wp_create_nonce( 'skyhshoso_cpanel_get_cached' ),
                'nonce_delete'    => wp_create_nonce( 'skyhshoso_cpanel_delete_cached' ),
                'servers'         => $server_list,
                'strings'         => array(
                    'syncing'       => __( 'Syncing cPanel accounts...', 'skyhs-hosting-solution' ),
                    'deleting'      => __( 'Deleting...', 'skyhs-hosting-solution' ),
                    'deleted'       => __( 'Account removed from cache.', 'skyhs-hosting-solution' ),
                    'synced'        => __( 'Sync complete!', 'skyhs-hosting-solution' ),
                    'error'         => __( 'An error occurred.', 'skyhs-hosting-solution' ),
                    'loading'       => __( 'Loading...', 'skyhs-hosting-solution' ),
                    'no_results'    => __( 'No cached accounts found for this server. Click "Sync from Server" to fetch cPanel accounts.', 'skyhs-hosting-solution' ),
                    'select_server' => __( 'Select a server above to view its cached cPanel accounts.', 'skyhs-hosting-solution' ),
                    'confirm_delete' => __( 'Delete this cached account entry? The actual cPanel account on the server will not be affected.', 'skyhs-hosting-solution' ),
                    'never_synced'  => __( 'Never synced', 'skyhs-hosting-solution' ),
                    'account'       => __( 'account', 'skyhs-hosting-solution' ),
                    'accounts'      => __( 'accounts', 'skyhs-hosting-solution' ),
                ),
            )
        );
    }

    public function render_page() {
        ?>
        <div class="wrap skyhshoso-hm-wrap">
            <h1><?php esc_html_e( 'cPanel Accounts Sync', 'skyhs-hosting-solution' ); ?></h1>
            <p><?php esc_html_e( 'Sync cPanel accounts from your WHM servers and view them in a local cache. Select a server below to manage its accounts.', 'skyhs-hosting-solution' ); ?></p>

            <div id="skyhshoso-cpanel-notice" class="notice" style="display:none;"></div>

            <div id="skyhshoso-cpanel-app">
                <!-- Toolbar: server selector + sync button + stats -->
                <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:16px;padding:16px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;">
                    <div style="min-width:220px;flex:1;">
                        <label for="cpanel-server-select" style="display:block;font-weight:600;margin-bottom:4px;color:#374151;font-size:13px;">
                            <?php esc_html_e( 'Filter by Server', 'skyhs-hosting-solution' ); ?>
                        </label>
                        <select id="cpanel-server-select" style="width:100%;" class="hm-control-select">
                            <option value=""><?php esc_html_e( '— All Servers —', 'skyhs-hosting-solution' ); ?></option>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;color:#374151;font-size:13px;">&nbsp;</label>
                        <button type="button" id="cpanel-sync-btn" class="button button-primary" disabled>
                            <span class="dashicons dashicons-update" style="vertical-align:middle;margin-right:4px;font-size:16px;line-height:1.4;"></span>
                            <?php esc_html_e( 'Sync from Server', 'skyhs-hosting-solution' ); ?>
                        </button>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;margin-left:auto;">
                        <span id="cpanel-loader" class="spinner" style="float:none;margin:0;"></span>
                        <span id="cpanel-sync-status" style="font-size:13px;color:#6b7280;"></span>
                    </div>
                </div>

                <!-- Stats bar -->
                <div id="cpanel-stats-bar" style="display:none;padding:12px 16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:16px;font-size:13px;color:#374151;">
                    <span id="cpanel-total-count" style="font-weight:600;"></span>
                    <span id="cpanel-last-sync" style="margin-left:20px;color:#6b7280;"></span>
                    <span id="cpanel-server-name" style="margin-left:20px;color:#6b7280;"></span>
                </div>

                <!-- Search -->
                <div style="margin-bottom:12px;">
                    <input type="text" id="cpanel-search-input" class="hm-control-input" placeholder="<?php esc_attr_e( 'Search by username, domain, or plan...', 'skyhs-hosting-solution' ); ?>" style="width:100%;max-width:400px;" />
                </div>

                <!-- Table -->
                <div id="cpanel-accounts-container">
                    <div class="skyhshoso-hm-empty">
                        <p><?php esc_html_e( 'Select a server above to view cached cPanel accounts, or click "Sync from Server" to fetch accounts for the first time.', 'skyhs-hosting-solution' ); ?></p>
                    </div>
                </div>

                <!-- Pagination -->
                <div class="skyhshoso-hm-pagination" style="display:flex;justify-content:space-between;align-items:center;margin-top:15px;padding-top:15px;border-top:1px solid #eee;">
                    <button type="button" id="cpanel-prev-page" class="button" disabled>&laquo; <?php esc_html_e( 'Previous', 'skyhs-hosting-solution' ); ?></button>
                    <span id="cpanel-page-info"><?php esc_html_e( 'Page 1 of 1', 'skyhs-hosting-solution' ); ?></span>
                    <button type="button" id="cpanel-next-page" class="button" disabled><?php esc_html_e( 'Next', 'skyhs-hosting-solution' ); ?> &raquo;</button>
                </div>
            </div>
        </div>
        <?php
    }

    private function get_whm_for_server( $server_id ) {
        $whm_user  = get_post_meta( $server_id, '_skyhshoso_whm_user_id', true );
        $whm_token = get_post_meta( $server_id, '_skyhshoso_whm_token', true );
        $whm_host  = get_post_meta( $server_id, '_skyhshoso_whm_host', true );

        if ( ! $whm_user || ! $whm_token || ! $whm_host ) {
            return false;
        }

        require_once SKYHSHOSO_PLUGIN_DIR . 'includes/class-whm-integration.php';
        return new SkyHSHOSO_WHM_Integration( $whm_user, $whm_token, $whm_host );
    }

    public function sync_server_accounts( $server_id ) {
        $whm = $this->get_whm_for_server( $server_id );
        if ( ! $whm ) {
            return false;
        }

        $accounts = $whm->get_accounts();
        if ( is_wp_error( $accounts ) ) {
            return false;
        }

        global $wpdb;
        $table = $wpdb->prefix . self::DB_TABLE;

        foreach ( $accounts as $acct ) {
            $username = isset( $acct['user'] ) ? sanitize_text_field( $acct['user'] ) : '';
            if ( empty( $username ) ) {
                continue;
            }

            $domain     = isset( $acct['domain'] ) ? sanitize_text_field( $acct['domain'] ) : '';
            $plan       = isset( $acct['plan'] ) ? sanitize_text_field( $acct['plan'] ) : '';
            $suspended  = isset( $acct['suspended'] ) ? intval( $acct['suspended'] ) : 0;
            $startdate  = isset( $acct['startdate'] ) ? sanitize_text_field( $acct['startdate'] ) : '';
            $email      = isset( $acct['email'] ) ? sanitize_text_field( $acct['email'] ) : '';
            $disk_used  = isset( $acct['diskused'] ) ? floatval( $acct['diskused'] ) : 0;
            $disk_limit = isset( $acct['disklimit'] ) ? floatval( $acct['disklimit'] ) : 0;

            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE server_id = %d AND username = %s",
                $server_id,
                $username
            ) );

            $data = array(
                'server_id'   => $server_id,
                'username'    => $username,
                'domain'      => $domain,
                'plan'        => $plan,
                'suspended'   => $suspended,
                'disk_used'   => $disk_used,
                'disk_limit'  => $disk_limit,
                'startdate'   => $startdate,
                'email'       => $email,
                'raw_data'    => wp_json_encode( $acct ),
            );

            if ( $existing ) {
                $wpdb->update( $table, $data, array( 'id' => $existing ) );
            } else {
                $wpdb->insert( $table, $data );
            }
        }

        update_post_meta( $server_id, '_skyhshoso_cpanel_last_sync_time', current_time( 'mysql' ) );
        return true;
    }

    public function ajax_sync_fetch() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_cpanel_sync_fetch' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'skyhs-hosting-solution' ) ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'skyhs-hosting-solution' ) ) );
        }

        $server_id = isset( $_POST['server_id'] ) ? intval( $_POST['server_id'] ) : 0;
        if ( ! $server_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid server.', 'skyhs-hosting-solution' ) ) );
        }

        $whm = $this->get_whm_for_server( $server_id );
        if ( ! $whm ) {
            wp_send_json_error( array( 'message' => __( 'Server WHM credentials not configured.', 'skyhs-hosting-solution' ) ) );
        }

        $accounts = $whm->get_accounts();
        if ( is_wp_error( $accounts ) ) {
            wp_send_json_error( array( 'message' => $accounts->get_error_message() ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . self::DB_TABLE;
        $inserted = 0;
        $updated = 0;

        foreach ( $accounts as $acct ) {
            $username = isset( $acct['user'] ) ? sanitize_text_field( $acct['user'] ) : '';
            if ( empty( $username ) ) {
                continue;
            }

            $domain     = isset( $acct['domain'] ) ? sanitize_text_field( $acct['domain'] ) : '';
            $plan       = isset( $acct['plan'] ) ? sanitize_text_field( $acct['plan'] ) : '';
            $suspended  = isset( $acct['suspended'] ) ? intval( $acct['suspended'] ) : 0;
            $startdate  = isset( $acct['startdate'] ) ? sanitize_text_field( $acct['startdate'] ) : '';
            $email      = isset( $acct['email'] ) ? sanitize_text_field( $acct['email'] ) : '';
            $disk_used  = isset( $acct['diskused'] ) ? floatval( $acct['diskused'] ) : 0;
            $disk_limit = isset( $acct['disklimit'] ) ? floatval( $acct['disklimit'] ) : 0;

            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE server_id = %d AND username = %s",
                $server_id,
                $username
            ) );

            $data = array(
                'server_id'   => $server_id,
                'username'    => $username,
                'domain'      => $domain,
                'plan'        => $plan,
                'suspended'   => $suspended,
                'disk_used'   => $disk_used,
                'disk_limit'  => $disk_limit,
                'startdate'   => $startdate,
                'email'       => $email,
                'raw_data'    => wp_json_encode( $acct ),
            );

            if ( $existing ) {
                $wpdb->update( $table, $data, array( 'id' => $existing ) );
                $updated++;
            } else {
                $wpdb->insert( $table, $data );
                $inserted++;
            }
        }

        update_post_meta( $server_id, '_skyhshoso_cpanel_last_sync_time', current_time( 'mysql' ) );

        wp_send_json_success( array(
            'message'  => sprintf(
                __( 'Synced %d accounts — %d new, %d updated.', 'skyhs-hosting-solution' ),
                count( $accounts ),
                $inserted,
                $updated
            ),
            'inserted' => $inserted,
            'updated'  => $updated,
            'total'    => count( $accounts ),
        ) );
    }

    public function ajax_get_cached() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_cpanel_get_cached' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'skyhs-hosting-solution' ) ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'skyhs-hosting-solution' ) ) );
        }

        $server_id = isset( $_POST['server_id'] ) ? intval( $_POST['server_id'] ) : 0;

        global $wpdb;
        $table = $wpdb->prefix . self::DB_TABLE;

        $search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
        $limit  = isset( $_POST['limit'] ) ? intval( $_POST['limit'] ) : 20;
        $page   = isset( $_POST['paged'] ) ? intval( $_POST['paged'] ) : 1;
        if ( $page < 1 ) {
            $page = 1;
        }
        $offset = ( $page - 1 ) * $limit;

        $where = array();
        $params = array();

        if ( $server_id ) {
            $where[] = "server_id = %d";
            $params[] = $server_id;
        }

        if ( ! empty( $search ) ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where[] = "(username LIKE %s OR domain LIKE %s OR plan LIKE %s)";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

        $total = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} {$where_sql}",
                $params
            )
        );

        $total_pages = ceil( $total / $limit );
        if ( $total_pages < 1 ) {
            $total_pages = 1;
        }

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} {$where_sql} ORDER BY username ASC LIMIT %d OFFSET %d",
                array_merge( $params, array( $limit, $offset ) )
            )
        );

        $accounts = array();
        foreach ( $results as $row ) {
            $accounts[] = array(
                'id'           => intval( $row->id ),
                'server_id'    => intval( $row->server_id ),
                'username'     => $row->username,
                'domain'       => $row->domain,
                'plan'         => $row->plan,
                'suspended'    => intval( $row->suspended ),
                'disk_used'    => floatval( $row->disk_used ),
                'disk_limit'   => floatval( $row->disk_limit ),
                'startdate'    => $row->startdate,
                'email'        => $row->email,
                'last_updated' => $row->last_updated,
            );
        }

        // Attach server info
        $server_name = '';
        $last_sync = '';
        if ( $server_id ) {
            $server = get_post( $server_id );
            $server_name = $server ? $server->post_title : '';
            $last_sync = get_post_meta( $server_id, '_skyhshoso_cpanel_last_sync_time', true );
        }

        wp_send_json_success( array(
            'accounts'      => $accounts,
            'total_records' => intval( $total ),
            'total_pages'   => intval( $total_pages ),
            'current_page'  => intval( $page ),
            'last_sync'     => $last_sync ?: '',
            'server_name'   => $server_name,
            'server_id'     => $server_id,
        ) );
    }

    public function ajax_delete_cached() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_cpanel_delete_cached' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'skyhs-hosting-solution' ) ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'skyhs-hosting-solution' ) ) );
        }

        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        if ( ! $id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid ID.', 'skyhs-hosting-solution' ) ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . self::DB_TABLE;
        $wpdb->delete( $table, array( 'id' => $id ) );

        wp_send_json_success( array(
            'message' => __( 'Account removed from local cache.', 'skyhs-hosting-solution' ),
        ) );
    }
}

SkyHSHOSO_CPanel_Sync::instance();
