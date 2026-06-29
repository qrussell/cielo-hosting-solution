<?php

defined( 'ABSPATH' ) || exit;

class SkyHSHOSO_Enom_Domain_Sync {

    private static $instance = null;

    const DB_TABLE = 'skyhshoso_enom_cache';
    const DB_VERSION_KEY = 'skyhshoso_enom_cache_db_version';
    const DB_VERSION = '1.0';

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'wp_ajax_skyhshoso_enom_sync_fetch', array( $this, 'ajax_sync_all' ) );
        add_action( 'wp_ajax_skyhshoso_enom_get_cached', array( $this, 'ajax_get_cached' ) );
        add_action( 'wp_ajax_skyhshoso_enom_poll_queue', array( $this, 'ajax_poll_queue' ) );
        add_action( 'wp_ajax_skyhshoso_enom_process_next', array( $this, 'ajax_process_next_domain' ) );
        add_action( 'wp_ajax_skyhshoso_enom_pause_queue', array( $this, 'ajax_pause_queue' ) );
        add_action( 'wp_ajax_skyhshoso_enom_resume_queue', array( $this, 'ajax_resume_queue' ) );
        add_action( 'wp_ajax_skyhshoso_enom_stop_queue', array( $this, 'ajax_stop_queue' ) );
        add_action( 'wp_ajax_skyhshoso_enom_get_details', array( $this, 'ajax_get_domain_details' ) );
        add_action( 'wp_ajax_skyhshoso_enom_toggle_lock', array( $this, 'ajax_toggle_lock' ) );
        add_action( 'wp_ajax_skyhshoso_enom_get_contacts', array( $this, 'ajax_get_contacts' ) );
        add_action( 'wp_ajax_skyhshoso_enom_update_contacts', array( $this, 'ajax_update_contacts' ) );
        add_action( 'wp_ajax_skyhshoso_enom_import_domain', array( $this, 'ajax_import_domain' ) );
        add_action( 'wp_ajax_skyhshoso_enom_delete_domain', array( $this, 'ajax_delete_domain' ) );
        add_action( 'wp_ajax_skyhshoso_enom_debug_ping', array( $this, 'ajax_debug_ping' ) );
        add_action( 'admin_post_skyhshoso_enom_clear_cache', array( $this, 'handle_clear_cache' ) );
        add_action( 'admin_init', array( $this, 'maybe_create_table' ) );
        add_action( 'skyhshoso_enom_process_next', array( $this, 'cron_process_one' ) );
        add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );
    }

    public function add_cron_interval( $schedules ) {
        $schedules['skyhshoso_enom_every_minute'] = array(
            'interval' => 60,
            'display'  => 'Every Minute (SkyHS Enom)',
        );
        return $schedules;
    }

    public function ajax_debug_ping() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nonce'] ) ), 'skyhshoso_enom_debug_ping' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ) );
        }
        header('Content-Type: application/json');
        echo wp_json_encode( array( 'success' => true, 'time' => current_time( 'mysql' ) ) );
        wp_die();
    }

    public function maybe_create_table() {
        global $wpdb;
        $current = get_option( self::DB_VERSION_KEY, '' );
        if ( $current !== self::DB_VERSION ) {
            $table_name = $wpdb->prefix . self::DB_TABLE;
            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                domain_name varchar(255) NOT NULL,
                sld varchar(255) NOT NULL,
                tld varchar(255) NOT NULL,
                expiration_date varchar(64) DEFAULT '',
                domain_name_id varchar(64) DEFAULT '',
                ns_status varchar(16) DEFAULT '',
                auto_renew varchar(8) DEFAULT '',
                wpps_status varchar(32) DEFAULT '',
                reg_lock varchar(8) DEFAULT '',
                renew_flag varchar(8) DEFAULT '',
                domain_data longtext DEFAULT '',
                last_updated datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY domain_name (domain_name),
                KEY sld (sld),
                KEY tld (tld)
            ) {$charset_collate};";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta( $sql );

            update_option( self::DB_VERSION_KEY, self::DB_VERSION );
        }
    }

    public function enqueue_scripts( $hook ) {
        $page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( false === strpos( $hook, 'skyhshoso-enom-sync' ) && 'skyhshoso-enom-sync' !== $page ) {
            return;
        }

        wp_enqueue_style(
            'skyhshoso-enom-sync',
            SKYHSHOSO_PLUGIN_URL . 'assets/css/hosting-manager.css',
            array(),
            SKYHSHOSO_VERSION
        );

        wp_enqueue_script(
            'skyhshoso-enom-sync',
            SKYHSHOSO_PLUGIN_URL . 'assets/js/enom-sync.js',
            array( 'jquery' ),
            SKYHSHOSO_VERSION,
            true
        );

        wp_localize_script(
            'skyhshoso-enom-sync',
            'skyhshoso_enom_sync',
            array(
                'ajax_url'          => admin_url( 'admin-ajax.php' ),
                'nonce_fetch'       => wp_create_nonce( 'skyhshoso_enom_sync_fetch' ),
                'nonce_cached'      => wp_create_nonce( 'skyhshoso_enom_get_cached' ),
                'nonce_poll'        => wp_create_nonce( 'skyhshoso_enom_poll_queue' ),
                'nonce_process'     => wp_create_nonce( 'skyhshoso_enom_process_next' ),
                'nonce_pause'       => wp_create_nonce( 'skyhshoso_enom_pause_queue' ),
                'nonce_resume'      => wp_create_nonce( 'skyhshoso_enom_resume_queue' ),
                'nonce_stop'        => wp_create_nonce( 'skyhshoso_enom_stop_queue' ),
                'nonce_details'     => wp_create_nonce( 'skyhshoso_enom_get_details' ),
                'nonce_lock'        => wp_create_nonce( 'skyhshoso_enom_toggle_lock' ),
                'nonce_contacts'    => wp_create_nonce( 'skyhshoso_enom_get_contacts' ),
                'nonce_update_contacts' => wp_create_nonce( 'skyhshoso_enom_update_contacts' ),
                'nonce_import'      => wp_create_nonce( 'skyhshoso_enom_import_domain' ),
                'nonce_delete'      => wp_create_nonce( 'skyhshoso_enom_delete_domain' ),
                'nonce_debug'       => wp_create_nonce( 'skyhshoso_enom_debug_ping' ),
                'strings'           => array(
                    'fetching'      => __( 'Fetching domains from Enom...', 'skyhs-hosting-solution' ),
                    'importing'     => __( 'Importing domain...', 'skyhs-hosting-solution' ),
                    'saving'        => __( 'Saving...', 'skyhs-hosting-solution' ),
                    'imported'      => __( 'Domain imported successfully!', 'skyhs-hosting-solution' ),
                    'lock_toggled'  => __( 'Registrar lock updated.', 'skyhs-hosting-solution' ),
                    'contacts_saved' => __( 'Contacts updated successfully.', 'skyhs-hosting-solution' ),
                    'error'         => __( 'An error occurred.', 'skyhs-hosting-solution' ),
                    'loading'       => __( 'Loading...', 'skyhs-hosting-solution' ),
                    'no_results'    => __( 'No results.', 'skyhs-hosting-solution' ),
                ),
            )
        );
    }

    private function get_enom_credentials() {
        $mode = get_option( 'skyhshoso_enom_mode', 'live' );
        if ( $mode === 'live' ) {
            return array(
                'username' => get_option( 'skyhshoso_enom_live_username' ),
                'password' => get_option( 'skyhshoso_enom_live_password' ),
                'url'      => 'https://reseller.enom.com/interface.asp',
            );
        } else {
            return array(
                'username' => get_option( 'skyhshoso_enom_test_username' ),
                'password' => get_option( 'skyhshoso_enom_test_password' ),
                'url'      => 'https://resellertest.enom.com/interface.asp',
            );
        }
    }

    private function parse_sld_tld( $domain ) {
        $parts = explode( '.', $domain, 2 );
        if ( count( $parts ) !== 2 ) {
            return false;
        }
        return array( 'sld' => $parts[0], 'tld' => $parts[1] );
    }

    private function enom_request( $command, $params = array() ) {
        $credentials = $this->get_enom_credentials();

        if ( empty( $credentials['username'] ) || empty( $credentials['password'] ) ) {
            return array( 'error' => 'Enom API credentials not configured. Go to SKYHS > Enom Settings.' );
        }

        $query = array_merge( array(
            'command'       => $command,
            'uid'           => $credentials['username'],
            'pw'            => $credentials['password'],
            'responsetype'  => 'xml',
        ), $params );

        $url = $credentials['url'] . '?' . http_build_query( $query );

        $response = wp_remote_get( $url, array( 'timeout' => 30 ) );

        if ( is_wp_error( $response ) ) {
            return array( 'error' => 'Connection failed: ' . $response->get_error_message() );
        }

        $body = wp_remote_retrieve_body( $response );
        $xml  = simplexml_load_string( $body );

        if ( $xml === false ) {
            return array( 'error' => 'Failed to parse XML response' );
        }

        if ( isset( $xml->ErrCount ) && (int) $xml->ErrCount > 0 ) {
            $err_msg = isset( $xml->errors->Err1 ) ? (string) $xml->errors->Err1 : 'Unknown API error';
            return array( 'error' => $err_msg );
        }

        return $xml;
    }

    private function db() {
        global $wpdb;
        return $wpdb->prefix . self::DB_TABLE;
    }

    // -------------------------------------------------------------------------
    // SYNC: Fetch all domains from Enom + lock/renew, save to DB
    // -------------------------------------------------------------------------

    public function ajax_sync_all() {
        try {
            if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nonce'] ) ), 'skyhshoso_enom_sync_fetch' ) ) {
                wp_send_json_error( array( 'message' => 'Security check failed.' ) );
            }

            $credentials = $this->get_enom_credentials();
            if ( empty( $credentials['username'] ) || empty( $credentials['password'] ) ) {
                wp_send_json_error( array( 'message' => 'Enom credentials not saved. Go to SKYHS > Enom Settings first.' ) );
            }

            set_time_limit( 120 );

            // Fetch domain list from Enom (all pages)
            $all_domains = array();
            $per_page = 100;
            $start = 1;
            $total = null;

            while ( true ) {
                $result = $this->enom_request( 'GetDomains', array(
                    'Tab'     => 'IOwn',
                    'Display' => $per_page,
                    'Start'   => $start,
                ) );

                if ( isset( $result['error'] ) ) {

                    wp_send_json_error( array( 'message' => $result['error'] ) );
                    return;
                }

                if ( isset( $result->GetDomains->{'domain-list'}->domain ) ) {
                    foreach ( $result->GetDomains->{'domain-list'}->domain as $d ) {
                        $domain_name = (string) $d->sld . '.' . (string) $d->tld;
                        $all_domains[] = array(
                            'domain_name'     => $domain_name,
                            'sld'             => (string) $d->sld,
                            'tld'             => (string) $d->tld,
                            'domain_name_id'  => (string) $d->DomainNameID,
                            'ns_status'       => (string) $d->{'ns-status'},
                            'expiration_date' => (string) $d->{'expiration-date'},
                            'auto_renew'      => (string) $d->{'auto-renew'},
                            'wpps_status'     => isset( $d->wppsstatus ) ? (string) $d->wppsstatus : '',
                        );
                    }
                }

                if ( $total === null ) {
                    $total = isset( $result->GetDomains->TotalDomainCount ) ? (int) $result->GetDomains->TotalDomainCount : 0;
                }

                $end = isset( $result->GetDomains->EndPosition ) ? (int) $result->GetDomains->EndPosition : 0;
                if ( $end >= $total || $total === 0 ) {
                    break;
                }
                $start = $end + 1;
            }


        if ( empty( $all_domains ) ) {
            global $wpdb;
            $wpdb->query( "TRUNCATE TABLE {$this->db()}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
            wp_send_json_success( array(
                'message'      => 'No domains found in your Enom account.',
                'domains'      => array(),
                'total_count'  => 0,
            ) );
            return;
        }

        // Save domain list only (skip per-domain lock/renew to avoid timeout)
        $inserted = 0;
        foreach ( $all_domains as $d ) {
            $this->upsert_domain_cache( $d['domain_name'], array(
                'domain_name'     => $d['domain_name'],
                'sld'             => $d['sld'],
                'tld'             => $d['tld'],
                'domain_name_id'  => $d['domain_name_id'],
                'ns_status'       => $d['ns_status'],
                'expiration_date' => $d['expiration_date'],
                'auto_renew'      => $d['auto_renew'],
                'wpps_status'     => $d['wpps_status'],
                'reg_lock'        => '?',
                'renew_flag'      => '?',
            ) );
            $inserted++;
        }

        $this->prune_stale_domains( $all_domains );

        $cached = $this->get_all_cached();
        $enriched = $this->enrich_with_local( $cached );


        // Schedule WP Cron to fetch lock/renew for each domain one at a time
        $this->schedule_next();

        wp_send_json_success( array(
            'message'      => sprintf( 'Synced %d domains from Enom. Status details will be fetched via WP Cron (1 per minute).', $inserted ),
            'domains'      => $enriched,
            'total_count'  => count( $enriched ),
            'last_synced'  => current_time( 'mysql' ),
        ) );

        } catch ( Throwable $e ) {

            wp_send_json_error( array( 'message' => 'PHP Error: ' . $e->getMessage() ) );
        }
    }

    /**
     * Cron: process one domain (fetch lock + renew), re-schedule if more remain.
     */
    public function cron_process_one() {

        if ( get_option( 'skyhshoso_enom_queue_paused', 0 ) == 1 ) {
            return;
        }

        global $wpdb;
        $domain = $wpdb->get_row( "SELECT * FROM {$this->db()} WHERE reg_lock = '?' LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery

        if ( ! $domain ) {
            return;
        }

        $lock  = $this->enom_request( 'GetRegLock', array( 'sld' => $domain['sld'], 'tld' => $domain['tld'] ) );
        $renew = $this->enom_request( 'GetRenew', array( 'sld' => $domain['sld'], 'tld' => $domain['tld'] ) );

        $reg_lock   = '';
        $renew_flag = '';
        if ( ! isset( $lock['error'] ) && isset( $lock->{'reg-lock'} ) ) {
            $reg_lock = (string) $lock->{'reg-lock'};
        }
        if ( ! isset( $renew['error'] ) && isset( $renew->renewflag ) ) {
            $renew_flag = (string) $renew->renewflag;
        }

        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
            $this->db(),
            array( 'reg_lock' => $reg_lock, 'renew_flag' => $renew_flag, 'last_updated' => current_time( 'mysql' ) ),
            array( 'domain_name' => $domain['domain_name'] )
        );

        // Check if more domains remain
        $remaining = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->db()} WHERE reg_lock = '?'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery

        if ( $remaining > 0 ) {
            $this->schedule_next();
        }
    }

    private function schedule_next() {
        if ( ! wp_next_scheduled( 'skyhshoso_enom_process_next' ) ) {
            wp_schedule_single_event( time() + 60, 'skyhshoso_enom_process_next' );
        }
    }

    /**
     * AJAX: returns queue stats — remaining count + whether cron is running.
     */
    public function ajax_poll_queue() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nonce'] ) ), 'skyhshoso_enom_poll_queue' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ) );
        }

        global $wpdb;
        $remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->db()} WHERE reg_lock = '?'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
        $total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->db()}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
        $scheduled = (int) wp_next_scheduled( 'skyhshoso_enom_process_next' );
        $paused    = (int) get_option( 'skyhshoso_enom_queue_paused', 0 );

        wp_send_json_success( array(
            'remaining'     => $remaining,
            'total'         => $total,
            'done'          => $remaining === 0,
            'scheduled'     => $scheduled > 0,
            'paused'        => $paused === 1,
        ) );
    }

    /**
     * AJAX: pause the queue (cron will skip processing, but scheduled event stays).
     */
    public function ajax_pause_queue() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nonce'] ) ), 'skyhshoso_enom_pause_queue' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ) );
        }
        update_option( 'skyhshoso_enom_queue_paused', 1 );
        wp_send_json_success( array( 'message' => 'Queue paused.' ) );
    }

    /**
     * AJAX: resume the queue (re-schedule cron if needed).
     */
    public function ajax_resume_queue() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nonce'] ) ), 'skyhshoso_enom_resume_queue' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ) );
        }
        update_option( 'skyhshoso_enom_queue_paused', 0 );
        $this->schedule_next();
        wp_send_json_success( array( 'message' => 'Queue resumed.' ) );
    }

    /**
     * AJAX: stop/cancel the queue — clear cron and reset queued domains.
     */
    public function ajax_stop_queue() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nonce'] ) ), 'skyhshoso_enom_stop_queue' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ) );
        }

        wp_clear_scheduled_hook( 'skyhshoso_enom_process_next' );
        delete_option( 'skyhshoso_enom_queue_paused' );

        global $wpdb;
        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "UPDATE {$this->db()} SET reg_lock = '', renew_flag = '' WHERE reg_lock = '?'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );

        wp_send_json_success( array( 'message' => 'Queue stopped and pending domains reset.' ) );
    }

    /**
     * AJAX: process one pending domain — fetch lock + renew from Enom, update cache.
     */
    public function ajax_process_next_domain() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'skyhshoso_enom_process_next' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ) );
        }

        global $wpdb;
        $domain = $wpdb->get_row( "SELECT * FROM {$this->db()} WHERE reg_lock = '?' LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery

        if ( ! $domain ) {
            $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->db()}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
            wp_send_json_success( array(
                'done'      => true,
                'domain'    => null,
                'remaining' => 0,
                'total'     => $total,
                'message'   => 'All domains processed.',
            ) );
            return;
        }

        $lock  = $this->enom_request( 'GetRegLock', array( 'sld' => $domain['sld'], 'tld' => $domain['tld'] ) );
        $renew = $this->enom_request( 'GetRenew', array( 'sld' => $domain['sld'], 'tld' => $domain['tld'] ) );

        $reg_lock   = '';
        $renew_flag = '';
        if ( ! isset( $lock['error'] ) && isset( $lock->{'reg-lock'} ) ) {
            $reg_lock = (string) $lock->{'reg-lock'};
        }
        if ( ! isset( $renew['error'] ) && isset( $renew->renewflag ) ) {
            $renew_flag = (string) $renew->renewflag;
        }

        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
            $this->db(),
            array( 'reg_lock' => $reg_lock, 'renew_flag' => $renew_flag, 'last_updated' => current_time( 'mysql' ) ),
            array( 'domain_name' => $domain['domain_name'] )
        );

        $remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->db()} WHERE reg_lock = '?'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
        $total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->db()}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery

        wp_send_json_success( array(
            'done'      => $remaining === 0,
            'domain'    => $domain['domain_name'],
            'remaining' => $remaining,
            'total'     => $total,
            'message'   => sprintf( 'Processed %s. %d remaining.', $domain['domain_name'], $remaining ),
        ) );
    }

    private function upsert_domain_cache( $domain_name, $data ) {
        global $wpdb;
        $existing = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT id FROM {$this->db()} WHERE domain_name = %s", $domain_name // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        ) );

        $row = array(
            'domain_name'     => $data['domain_name'],
            'sld'             => $data['sld'],
            'tld'             => $data['tld'],
            'domain_name_id'  => $data['domain_name_id'],
            'ns_status'       => $data['ns_status'],
            'expiration_date' => $data['expiration_date'],
            'auto_renew'      => $data['auto_renew'],
            'wpps_status'     => $data['wpps_status'],
            'reg_lock'        => $data['reg_lock'],
            'renew_flag'      => $data['renew_flag'],
            'last_updated'    => current_time( 'mysql' ),
        );

        if ( $existing ) {
            $wpdb->update( $this->db(), $row, array( 'id' => $existing ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
        } else {
            $wpdb->insert( $this->db(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
        }
    }

    private function prune_stale_domains( $current_domains ) {
        global $wpdb;
        $current_names = array();
        foreach ( $current_domains as $d ) {
            $current_names[] = $d['domain_name'];
        }
        if ( ! empty( $current_names ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $current_names ), '%s' ) );
            $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                "DELETE FROM {$this->db()} WHERE domain_name NOT IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                $current_names
            ) );
        }
    }

    // -------------------------------------------------------------------------
    // READ cached domains
    // -------------------------------------------------------------------------

    public function ajax_get_cached() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nonce'] ) ), 'skyhshoso_enom_get_cached' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ) );
        }

        $cached = $this->get_all_cached();
        $enriched = $this->enrich_with_local( $cached );

        $last_synced = $this->get_last_synced_time();

        wp_send_json_success( array(
            'domains'      => $enriched,
            'total_count'  => count( $enriched ),
            'last_synced'  => $last_synced,
        ) );
    }

    private function get_all_cached() {
        global $wpdb;
        $rows = $wpdb->get_results( "SELECT * FROM {$this->db()} ORDER BY domain_name ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
        return $rows ?: array();
    }

    private function get_last_synced_time() {
        global $wpdb;
        $time = $wpdb->get_var( "SELECT MAX(last_updated) FROM {$this->db()}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
        return $time ?: '';
    }

    private function enrich_with_local( $domains ) {
        $enriched = array();
        foreach ( $domains as $d ) {
            $enriched[] = array(
                'domain'          => $d['domain_name'],
                'sld'             => $d['sld'],
                'tld'             => $d['tld'],
                'expiration_date' => $d['expiration_date'],
                'ns_status'       => $d['ns_status'],
                'auto_renew'      => $d['auto_renew'],
                'reg_lock'        => $d['reg_lock'],
                'renew_flag'      => $d['renew_flag'],
                'local_domain_id' => $this->find_local_domain( $d['domain_name'] ) ?: 0,
            );
        }
        return $enriched;
    }

    // -------------------------------------------------------------------------
    // Individual domain operations (live via Enom API)
    // -------------------------------------------------------------------------

    public function get_domain_info( $sld, $tld ) {
        $result = $this->enom_request( 'GetDomainInfo', array(
            'sld' => $sld,
            'tld' => $tld,
        ) );

        if ( isset( $result['error'] ) ) {
            return $result;
        }

        $info = array();
        if ( isset( $result->GetDomainInfo->domainname ) ) {
            $info['domain'] = (string) $result->GetDomainInfo->domainname;
            $info['sld']    = (string) $result->GetDomainInfo->domainname['sld'];
            $info['tld']    = (string) $result->GetDomainInfo->domainname['tld'];
        }

        if ( isset( $result->GetDomainInfo->status ) ) {
            $info['expiration']           = isset( $result->GetDomainInfo->status->expiration ) ? (string) $result->GetDomainInfo->status->expiration : '';
            $info['registrar']            = isset( $result->GetDomainInfo->status->registrar ) ? (string) $result->GetDomainInfo->status->registrar : '';
            $info['registration_status']  = isset( $result->GetDomainInfo->status->registrationstatus ) ? (string) $result->GetDomainInfo->status->registrationstatus : '';
            $info['purchase_status']      = isset( $result->GetDomainInfo->status->{'purchase-status'} ) ? (string) $result->GetDomainInfo->status->{'purchase-status'} : '';
        }

        $info['nameservers'] = array();
        if ( isset( $result->GetDomainInfo->services->entry ) ) {
            foreach ( $result->GetDomainInfo->services->entry as $entry ) {
                if ( (string) $entry['name'] === 'dnsserver' ) {
                    if ( isset( $entry->configuration->dns ) ) {
                        foreach ( $entry->configuration->dns as $ns ) {
                            $info['nameservers'][] = (string) $ns;
                        }
                    }
                }
                if ( (string) $entry['name'] === 'irtpsettings' ) {
                    if ( isset( $entry->irtpsetting->transferlock ) ) {
                        $info['transferlock'] = (string) $entry->irtpsetting->transferlock;
                    }
                }
            }
        }

        return $info;
    }

    public function get_contacts( $sld, $tld ) {
        $result = $this->enom_request( 'GetContacts', array(
            'sld' => $sld,
            'tld' => $tld,
        ) );

        if ( isset( $result['error'] ) ) {
            return $result;
        }

        $contacts = array();
        $contact_types = array( 'Registrant', 'Admin', 'Tech', 'AuxBilling', 'Billing' );

        foreach ( $contact_types as $type ) {
            if ( isset( $result->GetContacts->{$type} ) ) {
                $c = $result->GetContacts->{$type};
                $contacts[ strtolower( $type ) ] = array(
                    'first_name'       => isset( $c->{$type . 'FirstName'} ) ? (string) $c->{$type . 'FirstName'} : '',
                    'last_name'        => isset( $c->{$type . 'LastName'} ) ? (string) $c->{$type . 'LastName'} : '',
                    'organization'     => isset( $c->{$type . 'OrganizationName'} ) ? (string) $c->{$type . 'OrganizationName'} : '',
                    'address1'         => isset( $c->{$type . 'Address1'} ) ? (string) $c->{$type . 'Address1'} : '',
                    'address2'         => isset( $c->{$type . 'Address2'} ) ? (string) $c->{$type . 'Address2'} : '',
                    'city'             => isset( $c->{$type . 'City'} ) ? (string) $c->{$type . 'City'} : '',
                    'state_province'   => isset( $c->{$type . 'StateProvince'} ) ? (string) $c->{$type . 'StateProvince'} : '',
                    'postal_code'      => isset( $c->{$type . 'PostalCode'} ) ? (string) $c->{$type . 'PostalCode'} : '',
                    'country'          => isset( $c->{$type . 'Country'} ) ? (string) $c->{$type . 'Country'} : '',
                    'email'            => isset( $c->{$type . 'EmailAddress'} ) ? (string) $c->{$type . 'EmailAddress'} : '',
                    'phone'            => isset( $c->{$type . 'Phone'} ) ? (string) $c->{$type . 'Phone'} : '',
                    'fax'              => isset( $c->{$type . 'Fax'} ) ? (string) $c->{$type . 'Fax'} : '',
                );
            }
        }

        $contacts['transferlock'] = isset( $result->GetContacts->TransferLock ) ? (string) $result->GetContacts->TransferLock : '';

        return $contacts;
    }

    public function set_reg_lock( $sld, $tld, $lock = true ) {
        $result = $this->enom_request( 'SetRegLock', array(
            'sld'             => $sld,
            'tld'             => $tld,
            'UnlockRegistrar' => $lock ? '0' : '1',
        ) );

        if ( isset( $result['error'] ) ) {
            return $result;
        }

        return array(
            'success'  => true,
            'reg_lock' => isset( $result->{'reg-lock'} ) ? (string) $result->{'reg-lock'} : '',
        );
    }

    public function set_renew( $sld, $tld, $enabled = true ) {
        $result = $this->enom_request( 'SetRenew', array(
            'sld'       => $sld,
            'tld'       => $tld,
            'renewflag' => $enabled ? '1' : '0',
        ) );

        if ( isset( $result['error'] ) ) {
            return $result;
        }

        return array( 'success' => true );
    }

    public function update_contacts( $sld, $tld, $contact_type, $data ) {
        $params = array(
            'sld'         => $sld,
            'tld'         => $tld,
            'ContactType' => $contact_type,
        );

        $prefix = $contact_type;
        foreach ( $data as $key => $value ) {
            $params[ $prefix . $key ] = $value;
        }

        $result = $this->enom_request( 'Contacts', $params );

        if ( isset( $result['error'] ) ) {
            return $result;
        }

        return array(
            'success'        => true,
            'consent_status' => isset( $result->ConsentStatus ) ? (string) $result->ConsentStatus : '',
        );
    }

    // -------------------------------------------------------------------------
    // AJAX Handlers
    // -------------------------------------------------------------------------

    public function ajax_get_domain_details() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_enom_get_details' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ) );
        }

        $domain = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
        $parts  = $this->parse_sld_tld( $domain );

        if ( ! $parts ) {
            wp_send_json_error( array( 'message' => 'Invalid domain format.' ) );
        }

        $info  = $this->get_domain_info( $parts['sld'], $parts['tld'] );

        if ( isset( $info['error'] ) ) {
            wp_send_json_error( array( 'message' => $info['error'] ) );
        }

        $local_id = $this->find_local_domain( $domain );

        wp_send_json_success( array(
            'info'            => $info,
            'local_domain_id' => $local_id ?: 0,
        ) );
    }

    public function ajax_toggle_lock() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_enom_toggle_lock' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ) );
        }

        $domain = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
        $lock   = isset( $_POST['lock'] ) ? (bool) intval( $_POST['lock'] ) : true;
        $parts  = $this->parse_sld_tld( $domain );

        if ( ! $parts ) {
            wp_send_json_error( array( 'message' => 'Invalid domain format.' ) );
        }

        $result = $this->set_reg_lock( $parts['sld'], $parts['tld'], $lock );

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( array( 'message' => $result['error'] ) );
        }

        // Update cache
        $this->update_cache_field( $domain, 'reg_lock', $lock ? '1' : '0' );

        wp_send_json_success( array(
            'message'  => 'Registrar lock updated successfully.',
            'reg_lock' => $result['reg_lock'],
        ) );
    }

    public function ajax_get_contacts() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_enom_get_contacts' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ) );
        }

        $domain = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
        $parts  = $this->parse_sld_tld( $domain );

        if ( ! $parts ) {
            wp_send_json_error( array( 'message' => 'Invalid domain format.' ) );
        }

        $contacts = $this->get_contacts( $parts['sld'], $parts['tld'] );

        if ( isset( $contacts['error'] ) ) {
            wp_send_json_error( array( 'message' => $contacts['error'] ) );
        }

        wp_send_json_success( $contacts );
    }

    public function ajax_update_contacts() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nonce'] ) ), 'skyhshoso_enom_update_contacts' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ) );
        }

        $domain       = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
        $contact_type = isset( $_POST['contact_type'] ) ? sanitize_text_field( wp_unslash( $_POST['contact_type'] ) ) : '';
        $raw_data     = isset( $_POST['data'] ) && is_array( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $parts        = $this->parse_sld_tld( $domain );

        if ( ! $parts ) {
            wp_send_json_error( array( 'message' => 'Invalid domain format.' ) );
        }

        $allowed_types = array( 'Registrant', 'Admin', 'Tech', 'AuxBilling' );
        if ( ! in_array( $contact_type, $allowed_types, true ) ) {
            wp_send_json_error( array( 'message' => 'Invalid contact type.' ) );
        }

        $data = array();
        foreach ( $raw_data as $key => $value ) {
            $data[ sanitize_text_field( $key ) ] = sanitize_text_field( $value );
        }

        $result = $this->update_contacts( $parts['sld'], $parts['tld'], $contact_type, $data );

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( array( 'message' => $result['error'] ) );
        }

        wp_send_json_success( array(
            'message'        => 'Contacts updated successfully.',
            'consent_status' => $result['consent_status'],
        ) );
    }

    public function ajax_import_domain() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_enom_import_domain' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ) );
        }

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        $domain   = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
        $owner_id = isset( $_POST['owner_id'] ) ? intval( $_POST['owner_id'] ) : 0;
        $parts    = $this->parse_sld_tld( $domain );

        if ( ! $parts ) {
            wp_send_json_error( array( 'message' => 'Invalid domain format.' ) );
        }

        if ( $this->find_local_domain( $domain ) ) {
            wp_send_json_error( array( 'message' => 'This domain is already imported locally.' ) );
        }

        if ( ! $owner_id ) {
            $owner_id = get_current_user_id();
        }

        $post_id = wp_insert_post( array(
            'post_type'   => 'skyhshoso_domain',
            'post_title'  => $domain,
            'post_author' => $owner_id,
            'post_status' => 'publish',
        ) );

        if ( is_wp_error( $post_id ) ) {
            wp_send_json_error( array( 'message' => $post_id->get_error_message() ) );
        }

        update_post_meta( $post_id, 'skyhshoso_domain_name', $domain );
        update_post_meta( $post_id, 'skyhshoso_enom_imported', 'yes' );

        // Grab cached data
        global $wpdb;
        $cached = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT * FROM {$this->db()} WHERE domain_name = %s", $domain // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        ), ARRAY_A );

        if ( $cached ) {
            update_post_meta( $post_id, 'skyhshoso_domain_expiration', $cached['expiration_date'] );
            update_post_meta( $post_id, 'skyhshoso_domain_registrar', 'Enom' );
            update_post_meta( $post_id, 'skyhshoso_domain_reg_lock', $cached['reg_lock'] );
            update_post_meta( $post_id, 'skyhshoso_domain_auto_renew', $cached['renew_flag'] );
        }

        $product_id = SkyHSHOSO_Domain_Cart()->create_domain_product( $domain, false );
        if ( $product_id ) {
            update_post_meta( $post_id, '_skyhshoso_domain_product_id', $product_id );
        }

        wp_send_json_success( array(
            'message'   => sprintf( 'Domain %s imported successfully.', $domain ),
            'domain_id' => $post_id,
        ) );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function update_cache_field( $domain_name, $field, $value ) {
        global $wpdb;
        $allowed = array( 'reg_lock', 'renew_flag' );
        if ( ! in_array( $field, $allowed, true ) ) {
            return;
        }
        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
            $this->db(),
            array( $field => $value, 'last_updated' => current_time( 'mysql' ) ),
            array( 'domain_name' => $domain_name )
        );
    }

    public function ajax_delete_domain() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nonce'] ) ), 'skyhshoso_enom_delete_domain' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        $domain = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
        if ( ! $domain ) {
            wp_send_json_error( array( 'message' => 'No domain specified.' ) );
        }

        global $wpdb;
        $deleted = $wpdb->delete( $this->db(), array( 'domain_name' => $domain ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery

        if ( $deleted ) {
            wp_send_json_success( array( 'message' => sprintf( 'Domain %s removed from local cache.', $domain ) ) );
        } else {
            wp_send_json_error( array( 'message' => 'Domain not found in cache.' ) );
        }
    }

    public function handle_clear_cache() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Permission denied.' );
        }
        check_admin_referer( 'skyhshoso_enom_clear_cache' );

        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$this->db()}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery

        // Clear any pending cron jobs
        wp_clear_scheduled_hook( 'skyhshoso_enom_process_next' );

        wp_safe_redirect( add_query_arg( 'cleared', '1', wp_get_referer() ?: admin_url( 'admin.php?page=skyhshoso-enom-settings' ) ) );
        exit;
    }

    private function find_local_domain( $domain ) {
        $posts = get_posts( array(
            'post_type'      => 'skyhshoso_domain',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'   => 'skyhshoso_domain_name',
                    'value' => $domain,
                ),
            ),
        ) );
        return ! empty( $posts ) ? intval( reset( $posts ) ) : 0;
    }

    // -------------------------------------------------------------------------
    // Admin Page
    // -------------------------------------------------------------------------

    public function render_page() {
        ?>
        <div class="wrap skyhshoso-hm-wrap">
            <h1><?php esc_html_e( 'Enom Manager', 'skyhs-hosting-solution' ); ?></h1>
            <p><?php esc_html_e( 'Sync domains from your Enom account into the local database, view status, update settings, and import into your WooCommerce system. Status details are fetched via WP Cron (1 domain per minute).', 'skyhs-hosting-solution' ); ?></p>

            <div id="skyhshoso-es-notice" class="notice" style="display:none;"></div>

            <div class="skyhshoso-hm-list-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:15px;">
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <button type="button" id="es-refresh-btn" class="button button-primary">
                        <span class="dashicons dashicons-update" style="vertical-align:middle;margin-right:4px;font-size:16px;"></span>
                        <?php esc_html_e( 'Sync from Enom', 'skyhs-hosting-solution' ); ?>
                    </button>
                    <span id="es-total-count" style="font-size:13px;color:#6b7280;"></span>
                    <span id="es-last-synced" style="font-size:12px;color:#9ca3af;"></span>
                    <span id="es-queue-status" style="font-size:12px;color:#d97706;display:none;"></span>
                </div>
                <div style="display:flex;gap:10px;align-items:center;">
                    <input type="text" id="es-search-input" class="hm-control-input" placeholder="<?php esc_attr_e( 'Search domains...', 'skyhs-hosting-solution' ); ?>" style="min-width:200px;" />
                </div>
            </div>

            <div id="es-loader" style="display:none;text-align:center;padding:40px 0;color:#6b7280;font-size:14px;">
                <span class="spinner" style="float:none;visibility:visible;margin:0 8px 0 0;"></span>
                <span id="es-loader-text"><?php esc_html_e( 'Loading...', 'skyhs-hosting-solution' ); ?></span>
            </div>

            <div id="es-container" style="display:none;">
                <table class="skyhshoso-hm-table" id="es-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Domain', 'skyhs-hosting-solution' ); ?></th>
                            <th><?php esc_html_e( 'Expiration', 'skyhs-hosting-solution' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'skyhs-hosting-solution' ); ?></th>
                            <th><?php esc_html_e( 'Reg Lock', 'skyhs-hosting-solution' ); ?></th>
                            <th style="text-align:right;"><?php esc_html_e( 'Actions', 'skyhs-hosting-solution' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="es-table-body"></tbody>
                </table>
            </div>

            <div id="es-detail-panel" class="skyhshoso-hm-form-panel" style="display:none;margin-top:20px;">
                <div class="skyhshoso-hm-form-header">
                    <h2 id="es-detail-title"><?php esc_html_e( 'Domain Details', 'skyhs-hosting-solution' ); ?></h2>
                </div>
                <div id="es-detail-content"></div>
            </div>
        </div>
        <?php
    }
}

SkyHSHOSO_Enom_Domain_Sync::instance();
