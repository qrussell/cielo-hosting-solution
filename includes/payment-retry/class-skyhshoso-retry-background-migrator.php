<?php
/**
 * Retry Background Updater.
 *
 * Simplified for SkyHS — no WCS_Background_Upgrader dependency.
 *
 * @category    Class
 * @package     SkyHS Hosting Solution
 * @subpackage  SkyHSHOSO_Retry_Background_Migrator
 * @since       2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Class SkyHSHOSO_Retry_Background_Migrator.
 *
 * Updates our retries on background.
 * @since 2.4.0
 */
class SkyHSHOSO_Retry_Background_Migrator {

	/**
	 * Where we're saving/migrating our data.
	 *
	 * @var SkyHSHOSO_Retry_Store
	 */
	private $destination_store;

	/**
	 * Where the data comes from.
	 *
	 * @var SkyHSHOSO_Retry_Store
	 */
	private $source_store;

	/**
	 * Our migration class.
	 *
	 * @var SkyHSHOSO_Retry_Migrator
	 */
	private $migrator;

	/**
	 * Scheduled hook name.
	 *
	 * @var string
	 */
	protected $scheduled_hook = 'skyhshoso_retries_migration_hook';

	/**
	 * Time limit per batch.
	 *
	 * @var int
	 */
	protected $time_limit = 30;

	/**
	 * Constructor.
	 *
	 * @since 2.4.0
	 */
	public function __construct() {
		$this->destination_store = SkyHSHOSO_Retry_Stores::get_database_store();
		$this->source_store      = SkyHSHOSO_Retry_Stores::get_post_store();

		$migrator_class = apply_filters( 'skyhshoso_retry_retry_migrator_class', 'SkyHSHOSO_Retry_Migrator' );
		$this->migrator = new $migrator_class( $this->source_store, $this->destination_store );
	}

	/**
	 * Initialize the background migrator.
	 *
	 * @since 2.4.0
	 */
	public function init() {
		add_action( $this->scheduled_hook, array( $this, 'run_update' ) );
	}

	/**
	 * Schedule the background repair.
	 *
	 * @since 2.4.0
	 */
	public function schedule_repair() {
		if ( false === as_next_scheduled_action( $this->scheduled_hook ) ) {
			as_schedule_single_action( time(), $this->scheduled_hook );
		}
	}

	/**
	 * Run the update process.
	 *
	 * @since 2.4.0
	 */
	public function run_update() {
		$items = $this->get_items_to_update();

		if ( empty( $items ) ) {
			return;
		}

		foreach ( $items as $retry ) {
			if ( time() > ( $this->time_limit + current_time( 'timestamp' ) ) ) {
				break;
			}

			$this->update_item( $retry );
		}

		// If there are still more items, schedule the next batch.
		if ( count( $this->get_items_to_update() ) > 0 ) {
			as_schedule_single_action( time(), $this->scheduled_hook );
		}
	}

	/**
	 * Get the items to be updated, if any.
	 *
	 * @return array An array of items to update, or empty array if there are no items to update.
	 * @since 2.4.0
	 */
	protected function get_items_to_update() {
		return $this->source_store->get_retries( array( 'limit' => 10 ) );
	}

	/**
	 * Run the update for a single item.
	 *
	 * @param SkyHSHOSO_Retry $retry The item to update.
	 *
	 * @return int|null
	 * @since 2.4.0
	 */
	protected function update_item( $retry ) {
		try {
			if ( ! is_a( $retry, 'SkyHSHOSO_Retry' ) ) {
				throw new Exception( 'The $retry parameter must be a valid SkyHSHOSO_Retry instance.' );
			}

			$new_item_id = $this->migrator->migrate_entry( $retry->get_id() );

			return $new_item_id;
		} catch ( Exception $e ) {
			return null;
		}
	}
}
