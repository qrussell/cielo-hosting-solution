<?php
/**
 * Retry migration class.
 *
 * Simplified for SkyHS — no WCS_Migrator dependency.
 * Migrates retries from the post store to the database store.
 *
 * @category    Class
 * @package     SkyHS Hosting Solution
 * @subpackage  SkyHSHOSO_Retry_Migrator
 * @since       2.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class SkyHSHOSO_Retry_Migrator {

	/**
	 * @var SkyHSHOSO_Retry_Store
	 */
	protected $source_store;

	/**
	 * @var SkyHSHOSO_Retry_Store
	 */
	protected $destination_store;

	static protected $needs_migration_option_name = 'skyhshoso_payment_retry_needs_migration';

	/**
	 * Constructor.
	 *
	 * @param SkyHSHOSO_Retry_Store $source_store
	 * @param SkyHSHOSO_Retry_Store $destination_store
	 */
	public function __construct( $source_store, $destination_store ) {
		$this->source_store      = $source_store;
		$this->destination_store = $destination_store;
	}

	/**
	 * Should this retry be migrated.
	 *
	 * @param int $retry_id
	 *
	 * @return bool
	 * @since 2.4
	 */
	public function should_migrate_entry( $retry_id ) {
		return ! $this->destination_store->get_retry( $retry_id );
	}

	/**
	 * Gets the item from the source store.
	 *
	 * @param int $entry_id
	 *
	 * @return SkyHSHOSO_Retry
	 * @since 2.4
	 */
	public function get_source_store_entry( $entry_id ) {
		return $this->source_store->get_retry( $entry_id );
	}

	/**
	 * Save the item to the destination store.
	 *
	 * @param int $entry_id
	 *
	 * @return mixed
	 * @since 2.4
	 */
	public function save_destination_store_entry( $entry_id ) {
		$source_retry = $this->get_source_store_entry( $entry_id );

		return $this->destination_store->save( $source_retry );
	}

	/**
	 * Migrate a retry entry from source to destination.
	 *
	 * @param int $entry_id
	 *
	 * @return int New retry ID in destination store.
	 * @since 2.4
	 */
	public function migrate_entry( $entry_id ) {
		$new_id = $this->save_destination_store_entry( $entry_id );
		$this->delete_source_store_entry( $entry_id );
		return $new_id;
	}

	/**
	 * Deletes the item from the source store.
	 *
	 * @param int $entry_id
	 *
	 * @return bool
	 * @since 2.4
	 */
	public function delete_source_store_entry( $entry_id ) {
		return $this->source_store->delete_retry( $entry_id );
	}

	/**
	 * If options exists, we need to run migration.
	 *
	 * @since 2.4.1
	 * @return bool
	 */
	public static function needs_migration() {
		return apply_filters( 'skyhshoso_payment_retry_needs_migration', ( 'true' === get_option( self::$needs_migration_option_name ) ) );
	}

	/**
	 * Sets needs migration option.
	 *
	 * @since 2.4.1
	 */
	public static function set_needs_migration() {
		if ( SkyHSHOSO_Retry_Stores::get_post_store()->get_retries( array( 'limit' => 1 ), 'ids' ) ) {
			update_option( self::$needs_migration_option_name, 'true' );
		} else {
			delete_option( self::$needs_migration_option_name );
		}
	}
}
