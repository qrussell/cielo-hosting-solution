<?php
/**
 * Automatic Failed Payment Retries Meta Box
 *
 * Display the automatic failed payment retries on the Edit Order screen for an order that has been automatically retried.
 *
 * @category Admin
 * @package  SkyHS Hosting Solution/Admin/Meta Boxes
 * @version  2.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * SkyHSHOSO_Meta_Box_Payment_Retries Class
 */
class SkyHSHOSO_Meta_Box_Payment_Retries {

	/**
	 * Outputs the Payment retry metabox.
	 *
	 * @param WC_Order|WP_Post $order The order object or post object.
	 */
	public static function output( $order ) {
		// For backwards compatibility the $order parameter could be a Post.
		if ( is_a( $order, 'WP_Post' ) ) {
			$order = wc_get_order( $order->ID );
		}

		if ( ! ( $order instanceof WC_Order ) ) {
			return;
		}

		WC()->mailer();

		$retries = SkyHSHOSO_Retry_Manager::store()->get_retries_for_order( $order->get_id() );

		include_once( dirname( __FILE__ ) . '/html-retries-table.php' );

		do_action( 'skyhshoso_retries_meta_box', $order->get_id(), $retries );
	}
}
