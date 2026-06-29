<?php
/**
 * Stores facade.
 *
 * @package        SkyHS Hosting Solution
 * @subpackage     SkyHSHOSO_Retry_Store
 * @category       Class
 * @since          2.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class SkyHSHOSO_Retry_Stores {
	/**
	 * Where we're saving/migrating our data.
	 *
	 * @var SkyHSHOSO_Retry_Store
	 */
	private static $database_store;

	/**
	 * Where the data comes from.
	 *
	 * @var SkyHSHOSO_Retry_Store
	 */
	private static $post_store;

	/**
	 * Access the object used to interface with the destination store.
	 *
	 * @return SkyHSHOSO_Retry_Store
	 * @since 2.4
	 */
	public static function get_database_store() {
		if ( empty( self::$database_store ) ) {
			$class                = self::get_database_store_class();
			self::$database_store = new $class();
			self::$database_store->init();
		}

		return self::$database_store;
	}

	/**
	 * Get the class used for instantiating retry storage via self::destination_store()
	 *
	 * @return string
	 * @since 2.4
	 */
	public static function get_database_store_class() {
		return apply_filters( 'skyhshoso_retry_database_store_class', 'SkyHSHOSO_Retry_Database_Store' );
	}

	/**
	 * Access the object used to interface with the source store.
	 *
	 * @return SkyHSHOSO_Retry_Store
	 * @since 2.4
	 */
	public static function get_post_store() {
		if ( empty( self::$post_store ) ) {
			$class            = self::get_post_store_class();
			self::$post_store = new $class();
			self::$post_store->init();
		}

		return self::$post_store;
	}

	/**
	 * Get the class used for instantiating retry storage via self::source_store()
	 *
	 * @return string
	 * @since 2.4
	 */
	public static function get_post_store_class() {
		return apply_filters( 'skyhshoso_retry_post_store_class', 'SkyHSHOSO_Retry_Post_Store' );
	}
}
