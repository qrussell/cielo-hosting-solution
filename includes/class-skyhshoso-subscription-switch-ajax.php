<?php
/**
 * SkyHS Subscription Switch AJAX Handler
 *
 * Handles AJAX requests for adding a subscription switch item to the
 * WooCommerce cart and returning the checkout URL. The actual switch
 * is processed by the existing SkyHSHOSO_Subscriptions_Switcher on
 * successful checkout/payment.
 *
 * Flow: User selects variation → AJAX adds to cart → JS redirects to checkout
 *       → User pays → Switcher processes the switch on order completion.
 *
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

class SkyHSHOSO_Subscription_Switch_Ajax {

	/**
	 * Initialize AJAX hooks.
	 */
	public static function init() {
		add_action( 'wp_ajax_skyhshoso_switch_to_cart', array( __CLASS__, 'add_switch_to_cart' ) );
	}

	/**
	 * Debug logger.
	 *
	 * @param string $message
	 */
	private static function log( $message ) {
	}

	/**
	 * AJAX: Add a subscription switch item to the WooCommerce cart and return checkout URL.
	 *
	 * This uses the exact same cart item data structure that the existing
	 * SkyHSHOSO_Subscriptions_Switcher expects, so all proration, price
	 * calculations, and switch processing on checkout are handled automatically.
	 *
	 * Expects POST: subscription_id, new_variation_id, nonce.
	 */
	public static function add_switch_to_cart() {
		check_ajax_referer( 'skyhshoso_switch_nonce', 'nonce' );

		self::log( '=== Switch to cart request START ===' );
		self::log( 'POST data: ' . wp_json_encode( $_POST ) );

		$subscription_id  = isset( $_POST['subscription_id'] ) ? absint( $_POST['subscription_id'] ) : 0;
		$new_variation_id = isset( $_POST['new_variation_id'] ) ? absint( $_POST['new_variation_id'] ) : 0;

		self::log( "subscription_id=$subscription_id, new_variation_id=$new_variation_id" );

		if ( ! $subscription_id || ! $new_variation_id ) {
			self::log( 'FAIL: Missing subscription_id or new_variation_id' );
			wp_send_json_error( array( 'message' => __( 'Invalid request parameters.', 'skyhs-hosting-solution' ) ) );
		}

		// Get subscription and validate ownership.
		$row = SkyHSHOSO_Subscription_DB::get( $subscription_id );
		if ( ! $row ) {
			self::log( "FAIL: Subscription #$subscription_id not found in DB" );
			wp_send_json_error( array( 'message' => __( 'Subscription not found.', 'skyhs-hosting-solution' ) ) );
		}

		self::log( 'Subscription row: ' . wp_json_encode( $row ) );

		$subscription = new SkyHSHOSO_Subscription( $row );

		$current_user_id = get_current_user_id();
		$sub_customer_id = (int) $subscription->get_customer_id();
		self::log( "current_user=$current_user_id, sub_customer=$sub_customer_id" );

		if ( $sub_customer_id !== $current_user_id ) {
			self::log( 'FAIL: User ownership mismatch' );
			wp_send_json_error( array( 'message' => __( 'You do not have permission to modify this subscription.', 'skyhs-hosting-solution' ) ) );
		}

		// Check switching is enabled.
		$allow_switching = get_option( 'skyhshoso_allow_switching', 'no' );
		self::log( "allow_switching option = '$allow_switching'" );

		if ( 'no' === $allow_switching || false === strpos( $allow_switching, 'variable' ) ) {
			self::log( 'FAIL: Switching not enabled for variable products' );
			wp_send_json_error( array( 'message' => __( 'Subscription switching is not enabled.', 'skyhs-hosting-solution' ) ) );
		}

		// Only active subscriptions can be switched.
		$status = $subscription->get_status();
		self::log( "Subscription status = '$status'" );

		if ( 'active' !== $status ) {
			self::log( 'FAIL: Subscription not active' );
			wp_send_json_error( array( 'message' => __( 'Only active subscriptions can be switched.', 'skyhs-hosting-solution' ) ) );
		}

		// Validate the new product/variation.
		$product_id    = (int) $row->product_id;
		$old_variation = (int) $row->variation_id;
		$current_product_id = $old_variation ?: $product_id;
		$current_product    = wc_get_product( $current_product_id );

		if ( ! $current_product ) {
			self::log( "FAIL: Current product $current_product_id not found" );
			wp_send_json_error( array( 'message' => __( 'Subscription product not found.', 'skyhs-hosting-solution' ) ) );
		}

		$is_variable = $old_variation > 0;
		self::log( "product_id=$product_id, old_variation=$old_variation, is_variable=" . ( $is_variable ? 'yes' : 'no' ) );

		if ( $is_variable ) {
			if ( false === strpos( $allow_switching, 'variable' ) ) {
				self::log( 'FAIL: Switching not enabled for variable products' );
				wp_send_json_error( array( 'message' => __( 'Subscription switching is not enabled for variable products.', 'skyhs-hosting-solution' ) ) );
			}

			$parent = wc_get_product( $product_id );
			if ( ! $parent || ! $parent->is_type( 'variable' ) ) {
				self::log( 'FAIL: Parent product is not variable.' );
				wp_send_json_error( array( 'message' => __( 'Parent product is not a variable product.', 'skyhs-hosting-solution' ) ) );
			}
			$children = $parent->get_children();
			if ( ! in_array( $new_variation_id, $children, true ) ) {
				self::log( "FAIL: Variation $new_variation_id not in parent children" );
				wp_send_json_error( array( 'message' => __( 'The selected variation does not belong to this product.', 'skyhs-hosting-solution' ) ) );
			}

			$product_id_to_add   = $product_id;
			$variation_id_to_add = $new_variation_id;
		} else {
			if ( false === strpos( $allow_switching, 'grouped' ) ) {
				self::log( 'FAIL: Switching not enabled for grouped products' );
				wp_send_json_error( array( 'message' => __( 'Subscription switching is not enabled for grouped products.', 'skyhs-hosting-solution' ) ) );
			}

			$parent_products = class_exists( 'SkyHSHOSO_Subscriptions_Product' ) ? SkyHSHOSO_Subscriptions_Product::get_visible_grouped_parent_product_ids( $current_product ) : array();
			if ( empty( $parent_products ) ) {
				self::log( 'FAIL: Current product is not part of any grouped product' );
				wp_send_json_error( array( 'message' => __( 'This product is not part of a grouped subscription.', 'skyhs-hosting-solution' ) ) );
			}

			$is_valid_grouped_switch = false;
			foreach ( $parent_products as $parent_id ) {
				$parent_grouped = wc_get_product( $parent_id );
				if ( $parent_grouped && $parent_grouped->is_type( 'grouped' ) ) {
					if ( in_array( $new_variation_id, $parent_grouped->get_children(), true ) ) {
						$is_valid_grouped_switch = true;
						break;
					}
				}
			}

			if ( ! $is_valid_grouped_switch ) {
				self::log( "FAIL: Product $new_variation_id does not share a parent grouped product with $current_product_id" );
				wp_send_json_error( array( 'message' => __( 'The selected plan does not belong the same product group.', 'skyhs-hosting-solution' ) ) );
			}

			$product_id_to_add   = $new_variation_id;
			$variation_id_to_add = 0;
		}

		// Cannot switch to the same plan.
		if ( $new_variation_id === $current_product_id ) {
			self::log( 'FAIL: Same variation/product selected' );
			wp_send_json_error( array( 'message' => __( 'You are already on this plan.', 'skyhs-hosting-solution' ) ) );
		}

		// Get the new product.
		$new_product = wc_get_product( $new_variation_id );
		if ( ! $new_product ) {
			self::log( "FAIL: Could not load new product $new_variation_id" );
			wp_send_json_error( array( 'message' => __( 'The selected plan is not available.', 'skyhs-hosting-solution' ) ) );
		}

		self::log( 'New product type: ' . $new_product->get_type() . ', purchasable: ' . ( $new_product->is_purchasable() ? 'yes' : 'no' ) . ', in_stock: ' . ( $new_product->is_in_stock() ? 'yes' : 'no' ) );

		if ( ! $new_product->is_purchasable() || ! $new_product->is_in_stock() ) {
			self::log( 'FAIL: New plan not purchasable or out of stock' );
			wp_send_json_error( array( 'message' => __( 'The selected plan is not available.', 'skyhs-hosting-solution' ) ) );
		}

		// Find the matching order item ID from the subscription's own active items first.
		$order_item_id = 0;
		$sub_items = $subscription->get_items( 'line_item' );
		self::log( "Checking subscription #{$subscription->get_id()} active items count: " . count( $sub_items ) );
		foreach ( $sub_items as $oi_id => $oi ) {
			$oi_product_id   = (int) $oi->get_product_id();
			$oi_variation_id = (int) $oi->get_variation_id();
			self::log( "  Subscription #{$subscription->get_id()} item #$oi_id: product=$oi_product_id, variation=$oi_variation_id" );
			if ( $oi_product_id === $current_product_id || ( $is_variable && $oi_variation_id === $current_product_id ) ) {
				$order_item_id = $oi_id;
				self::log( "  -> MATCH ON SUBSCRIPTION! order_item_id = $order_item_id" );
				break;
			}
		}

		$related_order_ids = array();
		if ( ! $order_item_id ) {
			$related_order_ids = $subscription->get_related_orders( 'ids', array( 'parent', 'switch', 'renewal' ) );
			self::log( 'Related order IDs: ' . wp_json_encode( $related_order_ids ) );

			foreach ( $related_order_ids as $order_id ) {
				$order = wc_get_order( $order_id );
				if ( ! $order ) {
					continue;
				}
				$items = $order->get_items( 'line_item' ); // Only get active line items
				self::log( "Checking order #$order_id items count: " . count( $items ) );

				foreach ( $items as $oi_id => $oi ) {
					$oi_product_id   = (int) $oi->get_product_id();
					$oi_variation_id = (int) $oi->get_variation_id();

					self::log( "  Order #$order_id item #$oi_id: product=$oi_product_id, variation=$oi_variation_id" );

					if ( $oi_product_id === $current_product_id || ( $is_variable && $oi_variation_id === $current_product_id ) ) {
						$order_item_id = $oi_id;
						self::log( "  -> MATCH ON RELATED ORDER! order_item_id = $order_item_id" );
						break 2;
					}
				}
			}
		}

		if ( ! $order_item_id ) {
			self::log( 'FAIL: No matching order item found' );
			wp_send_json_error( array(
				'message' => sprintf(
					__( 'Could not find the original order item for this subscription. (product_id=%d, variation_id=%d, related_orders=%s)', 'skyhs-hosting-solution' ),
					$product_id,
					$old_variation,
					wp_json_encode( $related_order_ids )
				),
			) );
		}

		// Get next payment timestamp for proration calculations.
		$next_payment_timestamp = $subscription->get_time( 'next_payment' );
		if ( ! $next_payment_timestamp ) {
			$next_payment_timestamp = $subscription->get_time( 'end' );
		}
		self::log( "next_payment_timestamp = $next_payment_timestamp" );

		// Clear the cart before adding the switch item.
		WC()->cart->empty_cart( true );
		self::log( 'Cart emptied' );

		// Get variation attributes for add_to_cart.
		$variation_attributes = array();
		if ( $new_product->is_type( 'variation' ) || $new_product->is_type( 'subscription_variation' ) ) {
			$variation_attributes = $new_product->get_variation_attributes();
		}
		self::log( 'Variation attributes: ' . wp_json_encode( $variation_attributes ) );

		// Build the subscription_switch cart item data — same structure
		// the existing SkyHSHOSO_Subscriptions_Switcher uses.
		$cart_item_data = array(
			'subscription_switch' => array(
				'subscription_id'        => $subscription->get_id(),
				'item_id'                => $order_item_id,
				'next_payment_timestamp' => $next_payment_timestamp,
				'upgraded_or_downgraded' => '',
			),
		);
		self::log( 'Cart item data: ' . wp_json_encode( $cart_item_data ) );

		// Add to cart.
		self::log( "Calling WC()->cart->add_to_cart( product_id=$product_id_to_add, qty=1, variation_id=$variation_id_to_add )" );

		$cart_item_key = WC()->cart->add_to_cart(
			$product_id_to_add,
			1,
			$variation_id_to_add,
			$variation_attributes,
			$cart_item_data
		);

		self::log( 'add_to_cart result: ' . ( $cart_item_key ? $cart_item_key : 'FALSE/EMPTY' ) );

		if ( ! $cart_item_key ) {
			// Check for WC notices that might explain the failure.
			$notices = wc_get_notices( 'error' );
			$notice_messages = array();
			if ( ! empty( $notices ) ) {
				foreach ( $notices as $notice ) {
					$msg = is_array( $notice ) ? ( $notice['notice'] ?? '' ) : $notice;
					$notice_messages[] = wp_strip_all_tags( $msg );
				}
			}
			wc_clear_notices();

			$debug_msg = ! empty( $notice_messages )
				? implode( ' | ', $notice_messages )
				: 'Unknown error — WC returned false from add_to_cart.';

			self::log( 'FAIL: add_to_cart failed. WC notices: ' . $debug_msg );

			wp_send_json_error( array(
				'message' => __( 'Could not add the switch item to the cart.', 'skyhs-hosting-solution' ) . ' [Debug: ' . $debug_msg . ']',
			) );
		}

		$checkout_url = wc_get_checkout_url();
		self::log( "SUCCESS! Checkout URL: $checkout_url" );
		self::log( '=== Switch to cart request END ===' );

		// Return the checkout URL.
		wp_send_json_success( array(
			'checkout_url' => $checkout_url,
			'message'      => __( 'Redirecting to checkout...', 'skyhs-hosting-solution' ),
		) );
	}
}
