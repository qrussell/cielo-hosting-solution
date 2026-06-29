<?php
/**
 * Hybrid wrapper around post and database store.
 *
 * In SkyHS, we primarily use the database store. The hybrid store is kept
 * for completeness but simplified.
 *
 * @package        SkyHS Hosting Solution
 * @subpackage     SkyHSHOSO_Retry_Store
 * @category       Class
 * @since          2.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class SkyHSHOSO_Retry_Hybrid_Store extends SkyHSHOSO_Retry_Store {
	/**
	 * Where we're saving/migrating our data.
	 *
	 * @var SkyHSHOSO_Retry_Store
	 */
	private $database_store;

	/**
	 * Where the data comes from.
	 *
	 * @var SkyHSHOSO_Retry_Store
	 */
	private $post_store;

	/**
	 * Setup the class, if required
	 *
	 * @since 2.4
	 */
	public function init() {
		$this->database_store = SkyHSHOSO_Retry_Stores::get_database_store();
		$this->post_store     = SkyHSHOSO_Retry_Stores::get_post_store();
	}

	/**
	 * Save the details of a retry to the database
	 *
	 * @param SkyHSHOSO_Retry $retry Retry to save.
	 *
	 * @return int the retry's ID
	 * @since 2.4
	 */
	public function save( SkyHSHOSO_Retry $retry ) {
		return $this->database_store->save( $retry );
	}

	/**
	 * Get the details of a retry from the database.
	 *
	 * @param int $retry_id Retry we want to get.
	 *
	 * @return SkyHSHOSO_Retry
	 * @since 2.4
	 */
	public function get_retry( $retry_id ) {
		return $this->database_store->get_retry( $retry_id );
	}

	/**
	 * Deletes a retry.
	 *
	 * @param int $retry_id
	 *
	 * @return bool
	 * @since 2.4
	 */
	public function delete_retry( $retry_id ) {
		return $this->database_store->delete_retry( $retry_id );
	}

	/**
	 * Get a set of retries from the database
	 *
	 * @param array  $args   A set of filters:
	 *                       'status': filter to only retries of a certain status, either 'pending', 'processing', 'failed' or 'complete'. Default: 'any', which will return all retries.
	 *                       'date_query': array of dates to filter retries to those that occur 'after' or 'before' a certain date (or between those two dates). Should be a MySQL formated date/time string.
	 *                       'orderby': Order by which property?
	 *                       'order': Order in ASC/DESC.
	 *                       'order_id': filter retries to those which belong to a certain order ID.
	 *                       'limit': How many retries we want to get.
	 * @param string $return Defines in which format return the entries. options:
	 *                       'objects': Returns an array of SkyHSHOSO_Retry objects
	 *                       'ids': Returns an array of ids.
	 *
	 * @return array An array of SkyHSHOSO_Retry objects or ids.
	 * @since 2.4
	 */
	public function get_retries( $args = array(), $return = 'objects' ) {
		return $this->database_store->get_retries( $args, $return );
	}

	/**
	 * Get the IDs of all retries from the database for a given order
	 *
	 * @param int $order_id order we want to look for.
	 *
	 * @return array
	 * @since 2.4
	 */
	public function get_retry_ids_for_order( $order_id ) {
		return $this->database_store->get_retry_ids_for_order( $order_id );
	}
}
