<?php
/**
 * SkyHS Subscription DB
 *
 * Handles creation, retrieval, and management of the custom
 * subscription database table: wp_skyhshoso_subscriptions.
 *
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB
class SkyHSHOSO_Subscription_DB {

    /**
     * Table name (without prefix).
     */
    const TABLE = 'skyhshoso_subscriptions';

    /**
     * Meta table name (without prefix).
     */
    const META_TABLE = 'skyhshoso_subscription_meta';

    /**
     * Current DB version for upgrade tracking.
     */
    const DB_VERSION = '1.1';

    /**
     * Run install() if the DB is out of date or the table doesn't exist yet.
     * Called on every page load via plugins_loaded — cheap because it checks
     * the option before touching the DB.
     */
    public static function maybe_install() {
        $installed = get_option( 'skyhshoso_subscription_db_version', '0' );
        if ( version_compare( $installed, self::DB_VERSION, '<' ) ) {
            self::install();
        }

        // Ensure uuid column exists on existing installations (v1.0 -> v1.1 upgrade).
        if ( version_compare( $installed, '1.1', '<' ) ) {
            self::maybe_add_uuid_column();
        }
    }

    /**
     * Add the uuid column to existing subscription tables.
     */
    private static function maybe_add_uuid_column() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $row = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'uuid'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
        if ( empty( $row ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN uuid VARCHAR(36) NULL DEFAULT NULL AFTER id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
        }
    }

    /**
     * Create both tables on plugin activation.
     */
    public static function install() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $table           = $wpdb->prefix . self::TABLE;
        $meta_table      = $wpdb->prefix . self::META_TABLE;

        $sql_subscriptions = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uuid VARCHAR(36) NULL DEFAULT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            payment_method VARCHAR(100) NOT NULL DEFAULT '',
            billing_period VARCHAR(10) NOT NULL DEFAULT 'month',
            billing_interval INT UNSIGNED NOT NULL DEFAULT 1,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            currency VARCHAR(3) NOT NULL DEFAULT 'USD',
            start_date DATETIME DEFAULT NULL,
            trial_end_date DATETIME DEFAULT NULL,
            next_payment_date DATETIME DEFAULT NULL,
            end_date DATETIME DEFAULT NULL,
            last_payment_date DATETIME DEFAULT NULL,
            failed_payment_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_via VARCHAR(100) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_user_id (user_id),
            KEY idx_order_id (order_id),
            KEY idx_status (status),
            KEY idx_next_payment (next_payment_date)
        ) $charset_collate;";

        $sql_meta = "CREATE TABLE IF NOT EXISTS {$meta_table} (
            meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            subscription_id BIGINT UNSIGNED NOT NULL,
            meta_key VARCHAR(255) NOT NULL DEFAULT '',
            meta_value LONGTEXT DEFAULT NULL,
            PRIMARY KEY (meta_id),
            KEY idx_subscription_id (subscription_id),
            KEY idx_meta_key (meta_key(191))
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_subscriptions );
        dbDelta( $sql_meta );

        update_option( 'skyhshoso_subscription_db_version', self::DB_VERSION );
    }

    // -------------------------------------------------------------------------
    // Subscription CRUD
    // -------------------------------------------------------------------------

    /**
     * Insert a new subscription record.
     *
     * @param array $data {
     *   @type int    $user_id
     *   @type int    $product_id
     *   @type int    $variation_id
     *   @type int    $order_id
     *   @type string $status
     *   @type string $payment_method
     *   @type string $billing_period
     *   @type int    $billing_interval
     *   @type float  $amount
     *   @type string $currency
     *   @type string $start_date        GMT datetime string
     *   @type string $next_payment_date GMT datetime string
     *   @type string $trial_end_date    GMT datetime string
     *   @type string $end_date          GMT datetime string
     *   @type string $created_via
     * }
     * @return int|false  New subscription ID or false on failure.
     */
    public static function insert( array $data ) {
        global $wpdb;

        $now     = gmdate( 'Y-m-d H:i:s' );
        $table   = $wpdb->prefix . self::TABLE;

        $defaults = array(
            'uuid'               => null,
            'user_id'            => 0,
            'product_id'         => 0,
            'variation_id'       => 0,
            'order_id'           => 0,
            'status'             => 'pending',
            'payment_method'     => '',
            'billing_period'     => 'month',
            'billing_interval'   => 1,
            'amount'             => 0.00,
            'currency'           => get_woocommerce_currency(),
            'start_date'         => $now,
            'trial_end_date'     => null,
            'next_payment_date'  => null,
            'end_date'           => null,
            'last_payment_date'  => null,
            'failed_payment_count' => 0,
            'created_via'        => '',
            'created_at'         => $now,
            'updated_at'         => $now,
        );

        $row = array_merge( $defaults, $data );

        $result = $wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

        return $result ? (int) $wpdb->insert_id : false;
    }

    /**
     * Get a single subscription row by ID.
     *
     * @param int $id Subscription ID.
     * @return object|null
     */
    public static function get( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", (int) $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );
    }

    /**
     * Find a subscription by UUID.
     *
     * @param string $uuid
     * @return object|null
     */
    public static function get_by_uuid( $uuid ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE uuid = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $uuid
            )
        );
    }

    /**
     * Get all subscriptions for a given user.
     *
     * @param int $user_id
     * @return array
     */
    public static function get_by_user( $user_id ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC", (int) $user_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );
    }

    /**
     * Get all subscriptions for an order.
     *
     * @param int $order_id
     * @return array
     */
    public static function get_by_order( $order_id ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d", (int) $order_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );
    }

    /**
     * Query subscriptions with flexible filters.
     *
     * @param array $args {
     *   @type string $subscription_status
     *   @type int    $customer_id
     *   @type int    $product_id
     *   @type int    $limit
     *   @type int    $offset
     * }
     * @return array
     */
    public static function query( array $args = array() ) {
        global $wpdb;
        $table  = $wpdb->prefix . self::TABLE;
        $where  = array( '1=1' );
        $values = array();

        if ( ! empty( $args['subscription_status'] ) ) {
            $where[]  = 'status = %s';
            $values[] = $args['subscription_status'];
        }

        if ( ! empty( $args['customer_id'] ) || ! empty( $args['user_id'] ) ) {
            $where[]  = 'user_id = %d';
            $values[] = (int) ( ! empty( $args['customer_id'] ) ? $args['customer_id'] : $args['user_id'] );
        }

        if ( ! empty( $args['product_id'] ) ) {
            $where[]  = 'product_id = %d';
            $values[] = (int) $args['product_id'];
        }

        $limit  = ! empty( $args['limit'] ) ? (int) $args['limit'] : -1;
        $offset = ! empty( $args['offset'] ) ? (int) $args['offset'] : 0;

        $sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY created_at DESC'; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ( $limit > 0 ) {
            $sql     .= ' LIMIT %d OFFSET %d';
            $values[] = $limit;
            $values[] = $offset;
        }

        if ( ! empty( $values ) ) {
            $sql = $wpdb->prepare( $sql, $values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        return $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * Get subscriptions due for renewal right now.
     *
     * @return array
     */
    public static function get_due_renewals() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $now   = gmdate( 'Y-m-d H:i:s' );
        return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = 'active' AND next_payment_date IS NOT NULL AND next_payment_date <= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $now
            )
        );
    }

    /**
     * Update subscription fields.
     *
     * @param int   $id   Subscription ID.
     * @param array $data Column => value pairs.
     * @return bool
     */
    public static function update( $id, array $data ) {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $data['updated_at'] = gmdate( 'Y-m-d H:i:s' );
        $result  = $wpdb->update( $table, $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        return false !== $result;
    }

    /**
     * Delete a subscription (and its meta).
     *
     * @param int $id
     * @return bool
     */
    public static function delete( $id ) {
        global $wpdb;
        self::delete_all_meta( $id );
        $table  = $wpdb->prefix . self::TABLE;
        $result = $wpdb->delete( $table, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        return false !== $result;
    }

    // -------------------------------------------------------------------------
    // Meta CRUD
    // -------------------------------------------------------------------------

    /**
     * Get a meta value for a subscription.
     *
     * @param int    $subscription_id
     * @param string $meta_key
     * @param bool   $single
     * @return mixed
     */
    public static function get_meta( $subscription_id, $meta_key, $single = true ) {
        global $wpdb;
        $table = $wpdb->prefix . self::META_TABLE;

        if ( $single ) {
            $value = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $wpdb->prepare(
                    "SELECT meta_value FROM {$table} WHERE subscription_id = %d AND meta_key = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    (int) $subscription_id,
                    $meta_key
                )
            );
            return maybe_unserialize( $value );
        }

        $rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->prepare(
                "SELECT meta_value FROM {$table} WHERE subscription_id = %d AND meta_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                (int) $subscription_id,
                $meta_key
            )
        );
        return array_map( 'maybe_unserialize', $rows );
    }

    /**
     * Add a meta value (allows multiple values for same key).
     *
     * @param int    $subscription_id
     * @param string $meta_key
     * @param mixed  $meta_value
     * @return int|false Meta ID or false.
     */
    public static function add_meta( $subscription_id, $meta_key, $meta_value ) {
        global $wpdb;
        $table  = $wpdb->prefix . self::META_TABLE;
        $result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $table,
            array(
                'subscription_id' => (int) $subscription_id,
                'meta_key'        => $meta_key,
                'meta_value'      => maybe_serialize( $meta_value ),
            )
        );
        return $result ? (int) $wpdb->insert_id : false;
    }

    /**
     * Update (or insert) a meta value.
     *
     * @param int    $subscription_id
     * @param string $meta_key
     * @param mixed  $meta_value
     * @return bool
     */
    public static function update_meta( $subscription_id, $meta_key, $meta_value ) {
        global $wpdb;
        $table  = $wpdb->prefix . self::META_TABLE;

        $existing = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->prepare(
                "SELECT meta_id FROM {$table} WHERE subscription_id = %d AND meta_key = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                (int) $subscription_id,
                $meta_key
            )
        );

        if ( $existing ) {
            $result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $table,
                array( 'meta_value' => maybe_serialize( $meta_value ) ),
                array( 'meta_id'    => (int) $existing )
            );
            return false !== $result;
        }

        return (bool) self::add_meta( $subscription_id, $meta_key, $meta_value );
    }

    /**
     * Delete a specific meta key for a subscription.
     *
     * @param int    $subscription_id
     * @param string $meta_key
     */
    public static function delete_meta( $subscription_id, $meta_key ) {
        global $wpdb;
        $table = $wpdb->prefix . self::META_TABLE;
        $wpdb->delete( $table, array( 'subscription_id' => (int) $subscription_id, 'meta_key' => $meta_key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    }

    /**
     * Delete ALL meta for a subscription.
     *
     * @param int $subscription_id
     */
    public static function delete_all_meta( $subscription_id ) {
        global $wpdb;
        $table = $wpdb->prefix . self::META_TABLE;
        $wpdb->delete( $table, array( 'subscription_id' => (int) $subscription_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    }

    /**
     * Get all meta for a subscription as a key=>value array.
     *
     * @param int $subscription_id
     * @return array
     */
    public static function get_all_meta( $subscription_id ) {
        global $wpdb;
        $table = $wpdb->prefix . self::META_TABLE;
        $rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$table} WHERE subscription_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                (int) $subscription_id
            )
        );
        $meta = array();
        foreach ( $rows as $row ) {
            $meta[ $row->meta_key ] = maybe_unserialize( $row->meta_value );
        }
        return $meta;
    }
}
// phpcs:enable WordPress.DB
