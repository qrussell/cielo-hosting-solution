<?php

defined( 'ABSPATH' ) || exit;

class SkyHSHOSO_Activity_Log {

    const TABLE = 'skyhshoso_activity_log';
    const DB_VERSION = '1.0';

    public static function maybe_install() {
        $installed = get_option( 'skyhshoso_activity_log_db_version', '0' );
        if ( version_compare( $installed, self::DB_VERSION, '<' ) ) {
            self::install();
        }
    }

    public static function install() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . self::TABLE;

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            log_type VARCHAR(50) NOT NULL DEFAULT '',
            message TEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'info',
            subscription_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_type (log_type),
            KEY idx_created (created_at),
            KEY idx_subscription (subscription_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'skyhshoso_activity_log_db_version', self::DB_VERSION );
    }

    public static function log( $log_type, $message, $status = 'info', $subscription_id = 0, $order_id = 0, $user_id = 0 ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        return $wpdb->insert( $table, array(
            'log_type'        => $log_type,
            'message'         => $message,
            'status'          => $status,
            'subscription_id' => (int) $subscription_id,
            'order_id'        => (int) $order_id,
            'user_id'         => (int) $user_id,
            'created_at'      => gmdate( 'Y-m-d H:i:s' ),
        ) );
    }

    public static function get_logs( $date_from = '', $date_to = '', $log_type = '', $limit = 100, $offset = 0 ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $where = array( '1=1' );
        $params = array();

        if ( $date_from ) {
            $where[]  = 'created_at >= %s';
            $params[] = $date_from . ' 00:00:00';
        }
        if ( $date_to ) {
            $where[]  = 'created_at <= %s';
            $params[] = $date_to . ' 23:59:59';
        }
        if ( $log_type ) {
            $where[]  = 'log_type = %s';
            $params[] = $log_type;
        }

        $where_sql = implode( ' AND ', $where );
        $params[] = (int) $limit;
        $params[] = (int) $offset;

        $sql = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
            $params
        );

        return $wpdb->get_results( $sql );
    }

    public static function get_days_with_logs( $limit = 30 ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(created_at) as log_date, COUNT(*) as count 
                FROM {$table} 
                GROUP BY DATE(created_at) 
                ORDER BY log_date DESC 
                LIMIT %d",
                (int) $limit
            )
        );
    }

    public static function get_daily_summary( $date ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT log_type, status, COUNT(*) as count 
                FROM {$table} 
                WHERE DATE(created_at) = %s 
                GROUP BY log_type, status 
                ORDER BY log_type ASC",
                $date
            )
        );
    }

    public static function get_log_count( $date_from = '', $date_to = '', $log_type = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $where = array( '1=1' );
        $params = array();

        if ( $date_from ) {
            $where[]  = 'created_at >= %s';
            $params[] = $date_from . ' 00:00:00';
        }
        if ( $date_to ) {
            $where[]  = 'created_at <= %s';
            $params[] = $date_to . ' 23:59:59';
        }
        if ( $log_type ) {
            $where[]  = 'log_type = %s';
            $params[] = $log_type;
        }

        $where_sql = implode( ' AND ', $where );

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}",
                $params
            )
        );
    }

    public static function cleanup_old_logs( $days = 90 ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        return $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE created_at < %s",
                gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) )
            )
        );
    }
}
