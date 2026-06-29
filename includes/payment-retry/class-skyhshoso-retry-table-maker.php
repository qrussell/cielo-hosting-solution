<?php
/**
 * Class that handles our retries custom tables creation.
 *
 * Simplified for SkyHS — uses dbDelta directly, no WCS_Table_Maker dependency.
 *
 * @package        SkyHS Hosting Solution
 * @subpackage     SkyHSHOSO_Retry_Table_Maker
 * @category       Class
 * @since          2.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class SkyHSHOSO_Retry_Table_Maker {

	/**
	 * Register table creation on the appropriate hook.
	 */
	public function register_tables() {
		add_action( 'admin_init', array( $this, 'create_table' ) );
	}

	/**
	 * Create the payment retries table using dbDelta.
	 *
	 * @since 2.4
	 */
	public function create_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . SkyHSHOSO_Retry_Database_Store::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
			retry_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			status varchar(255) NOT NULL,
			date_gmt datetime NOT NULL default '0000-00-00 00:00:00',
			rule_raw text,
			PRIMARY KEY  (retry_id),
			KEY order_id (order_id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
