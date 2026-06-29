<?php
/**
 * A class for managing the drip downloads feature.
 *
 * @package SkyHS Hosting Solution
 * @since   4.0.0
 */

defined( 'ABSPATH' ) || exit;

class SkyHSHOSO_Drip_Downloads_Manager {

	/**
	 * Initialise the class.
	 *
	 * @since 4.0.0
	 */
	public static function init() {
		add_filter( 'woocommerce_process_product_file_download_paths_grant_access_to_new_file', array( __CLASS__, 'maybe_revoke_immediate_access' ), 10, 4 );
	}

	/**
	 * Checks if the drip downloads feature is enabled.
	 *
	 * @since 4.0.0
	 * @return bool Whether download dripping is enabled or not.
	 */
	public static function are_drip_downloads_enabled() {
		return 'yes' === get_option( SkyHSHOSO_Admin::$option_prefix . '_drip_downloadable_content_on_renewal', 'no' );
	}

	/**
	 * Prevent granting download permissions to subscriptions and related-orders when new files are added to a product.
	 *
	 * @since 4.0.0
	 *
	 * @param bool     $grant_access Whether to grant access to the file/download ID.
	 * @param string   $download_id  The ID of the download being added.
	 * @param int      $product_id   The ID of the downloadable product.
	 * @param WC_Order $order        The order/subscription's ID.
	 *
	 * @return bool Whether to grant access to the file/download ID.
	 */
	public static function maybe_revoke_immediate_access( $grant_access, $download_id, $product_id, $order ) {

		if ( self::are_drip_downloads_enabled() && ( skyhshoso_is_subscription( $order->get_id() ) || skyhshoso_order_contains_subscription( $order, 'any' ) ) ) {
			$grant_access = false;
		}

		return $grant_access;
	}

	/**
	 * Adds the Drip Downloadable Content setting.
	 *
	 * @since 4.0.0
	 *
	 * @param array $settings The WC Subscriptions settings array.
	 * @return array Settings.
	 */
	public static function add_setting( $settings ) {
		$setting = array(
			'name'     => __( 'Drip Downloadable Content', 'skyhs-hosting-solution' ),
			'desc'     => __( 'Enable dripping for downloadable content on subscription products.', 'skyhs-hosting-solution' ),
			'id'       => SkyHSHOSO_Admin::$option_prefix . '_drip_downloadable_content_on_renewal',
			'default'  => 'no',
			'type'     => 'checkbox',
			// translators: %s is a line break.
			'desc_tip' => sprintf( __( 'Enabling this grants access to new downloadable files added to a product only after the next renewal is processed.%sBy default, access to new downloadable files added to a product is granted immediately to any customer that has an active subscription with that product.', 'skyhs-hosting-solution' ), '<br />' ),
		);

		SkyHSHOSO_Admin::insert_setting_after( $settings, SkyHSHOSO_Admin::$option_prefix . '_miscellaneous', $setting );
		return $settings;
	}
}
