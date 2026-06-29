<?php
/**
 * SkyHS Email Campaign DB
 *
 * Handles creation and CRUD for email campaign and campaign queue tables.
 *
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB
class SkyHSHOSO_Email_Campaign_DB {

	const CAMPAIGN_TABLE = 'skyhshoso_email_campaigns';
	const QUEUE_TABLE    = 'skyhshoso_email_campaign_queue';
	const DB_VERSION     = '1.0';

	public static function maybe_install() {
		$installed = get_option( 'skyhshoso_email_campaign_db_version', '0' );
		if ( version_compare( $installed, self::DB_VERSION, '<' ) ) {
			self::install();
		}
	}

	public static function install() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$campaign_table  = $wpdb->prefix . self::CAMPAIGN_TABLE;
		$queue_table     = $wpdb->prefix . self::QUEUE_TABLE;

		$sql_campaigns = "CREATE TABLE IF NOT EXISTS {$campaign_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL DEFAULT '',
			subject VARCHAR(500) NOT NULL DEFAULT '',
			body LONGTEXT NOT NULL,
			target_type VARCHAR(20) NOT NULL DEFAULT 'products',
			target_ids TEXT DEFAULT NULL,
			trigger_type VARCHAR(20) NOT NULL DEFAULT 'scheduled',
			delay_value INT UNSIGNED NOT NULL DEFAULT 0,
			delay_unit VARCHAR(10) NOT NULL DEFAULT 'days',
			is_active TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY idx_active (is_active),
			KEY idx_target_type (target_type)
		) $charset_collate;";

		$sql_queue = "CREATE TABLE IF NOT EXISTS {$queue_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			campaign_id BIGINT UNSIGNED NOT NULL,
			subscription_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			status_message TEXT DEFAULT NULL,
			scheduled_at DATETIME NOT NULL,
			sent_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY idx_campaign (campaign_id),
			KEY idx_status (status),
			KEY idx_scheduled (scheduled_at),
			KEY idx_order (order_id),
			KEY idx_campaign_order (campaign_id, order_id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_campaigns );
		dbDelta( $sql_queue );

		update_option( 'skyhshoso_email_campaign_db_version', self::DB_VERSION );
	}

	// -------------------------------------------------------------------------
	// Campaign CRUD
	// -------------------------------------------------------------------------

	public static function insert_campaign( array $data ) {
		global $wpdb;
		$table = $wpdb->prefix . self::CAMPAIGN_TABLE;

		$now = gmdate( 'Y-m-d H:i:s' );

		$defaults = array(
			'name'         => '',
			'subject'      => '',
			'body'         => '',
			'target_type'  => 'products',
			'target_ids'   => null,
			'trigger_type' => 'scheduled',
			'delay_value'  => 0,
			'delay_unit'   => 'days',
			'is_active'    => 0,
			'created_at'   => $now,
			'updated_at'   => $now,
		);

		$row    = array_merge( $defaults, $data );
		$result = $wpdb->insert( $table, $row );

		return $result ? (int) $wpdb->insert_id : false;
	}

	public static function get_campaign( $id ) {
		global $wpdb;
		$table = $wpdb->prefix . self::CAMPAIGN_TABLE;
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", (int) $id )
		);
	}

	public static function get_all_campaigns() {
		global $wpdb;
		$table = $wpdb->prefix . self::CAMPAIGN_TABLE;
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC" );
	}

	public static function get_active_campaigns() {
		global $wpdb;
		$table = $wpdb->prefix . self::CAMPAIGN_TABLE;
		return $wpdb->get_results( "SELECT * FROM {$table} WHERE is_active = 1 ORDER BY created_at ASC" );
	}

	public static function update_campaign( $id, array $data ) {
		global $wpdb;
		$table              = $wpdb->prefix . self::CAMPAIGN_TABLE;
		$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		return false !== $wpdb->update( $table, $data, array( 'id' => (int) $id ) );
	}

	public static function delete_campaign( $id ) {
		global $wpdb;
		$table  = $wpdb->prefix . self::CAMPAIGN_TABLE;
		return false !== $wpdb->delete( $table, array( 'id' => (int) $id ) );
	}

	public static function toggle_campaign( $id ) {
		global $wpdb;
		$table = $wpdb->prefix . self::CAMPAIGN_TABLE;

		$campaign = self::get_campaign( $id );
		if ( ! $campaign ) {
			return false;
		}

		$new_active = $campaign->is_active ? 0 : 1;
		return self::update_campaign( $id, array( 'is_active' => $new_active ) );
	}

	public static function duplicate_campaign( $id ) {
		$campaign = self::get_campaign( $id );
		if ( ! $campaign ) {
			return false;
		}

		$data = (array) $campaign;
		unset( $data['id'] );
		$data['name']      = $data['name'] . ' ' . __( '(Copy)', 'skyhs-hosting-solution' );
		$data['is_active'] = 0;
		$data['created_at'] = gmdate( 'Y-m-d H:i:s' );
		$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		return self::insert_campaign( $data );
	}

	// -------------------------------------------------------------------------
	// Queue CRUD
	// -------------------------------------------------------------------------

	public static function insert_queue( array $data ) {
		global $wpdb;
		$table = $wpdb->prefix . self::QUEUE_TABLE;

		$now = gmdate( 'Y-m-d H:i:s' );

		$defaults = array(
			'campaign_id'     => 0,
			'subscription_id' => 0,
			'order_id'        => 0,
			'user_id'         => 0,
			'product_id'      => 0,
			'status'          => 'pending',
			'status_message'  => null,
			'scheduled_at'    => $now,
			'sent_at'         => null,
			'created_at'      => $now,
		);

		$row    = array_merge( $defaults, $data );
		$result = $wpdb->insert( $table, $row );

		return $result ? (int) $wpdb->insert_id : false;
	}

	public static function get_pending_queue( $limit = 60 ) {
		global $wpdb;
		$table = $wpdb->prefix . self::QUEUE_TABLE;
		$now   = gmdate( 'Y-m-d H:i:s' );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'pending' AND scheduled_at <= %s ORDER BY scheduled_at ASC LIMIT %d",
				$now,
				(int) $limit
			)
		);
	}

	public static function count_pending() {
		global $wpdb;
		$table = $wpdb->prefix . self::QUEUE_TABLE;
		$now   = gmdate( 'Y-m-d H:i:s' );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = 'pending' AND scheduled_at <= %s",
				$now
			)
		);
	}

	public static function update_queue( $id, array $data ) {
		global $wpdb;
		$table = $wpdb->prefix . self::QUEUE_TABLE;
		return false !== $wpdb->update( $table, $data, array( 'id' => (int) $id ) );
	}

	public static function queue_entry_exists( $campaign_id, $order_id ) {
		global $wpdb;
		$table = $wpdb->prefix . self::QUEUE_TABLE;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE campaign_id = %d AND order_id = %d LIMIT 1",
				(int) $campaign_id,
				(int) $order_id
			)
		);
	}

	public static function queue_entry_exists_for_user( $campaign_id, $user_id ) {
		global $wpdb;
		$table = $wpdb->prefix . self::QUEUE_TABLE;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE campaign_id = %d AND user_id = %d LIMIT 1",
				(int) $campaign_id,
				(int) $user_id
			)
		);
	}

	public static function get_queue_by_campaign( $campaign_id, $limit = 100, $offset = 0 ) {
		global $wpdb;
		$table = $wpdb->prefix . self::QUEUE_TABLE;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE campaign_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
				(int) $campaign_id,
				(int) $limit,
				(int) $offset
			)
		);
	}

	public static function count_queue_by_campaign( $campaign_id ) {
		global $wpdb;
		$table = $wpdb->prefix . self::QUEUE_TABLE;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE campaign_id = %d",
				(int) $campaign_id
			)
		);
	}

	public static function delete_queue_by_campaign( $campaign_id ) {
		global $wpdb;
		$table = $wpdb->prefix . self::QUEUE_TABLE;
		return false !== $wpdb->delete( $table, array( 'campaign_id' => (int) $campaign_id ) );
	}

	public static function cleanup_completed_queue( $days = 30 ) {
		global $wpdb;
		$table = $wpdb->prefix . self::QUEUE_TABLE;
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		return $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE status IN ('sent', 'failed', 'skipped') AND created_at < %s",
				$cutoff
			)
		);
	}
}
// phpcs:enable WordPress.DB
