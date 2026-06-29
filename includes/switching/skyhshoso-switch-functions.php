<?php
/**
 * SkyHS Switch Functions
 *
 * @package SkyHS Hosting Solution
 */

defined( 'ABSPATH' ) || exit;

/**
 * Check if a given order was to switch a subscription.
 *
 * @param WC_Order|int $order
 * @return bool
 */
function skyhshoso_order_contains_switch( $order ) {
	if ( ! is_a( $order, 'WC_Abstract_Order' ) ) {
		$order = wc_get_order( $order );
	}
	if ( ! $order ) {
		return false;
	}
	return (bool) $order->get_meta( '_skyhshoso_switch_subscription' );
}

/**
 * Get subscriptions for a switch order.
 *
 * @param WC_Order|int $order
 * @return SkyHSHOSO_Subscription[]
 */
function skyhshoso_get_subscriptions_for_switch_order( $order ) {
	$order_id = is_object( $order ) ? $order->get_id() : $order;
	$order    = wc_get_order( $order_id );
	if ( ! $order ) {
		return array();
	}

	$sub_ids = $order->get_meta( '_skyhshoso_switch_subscription' );
	if ( ! is_array( $sub_ids ) ) {
		$sub_ids = $sub_ids ? array( $sub_ids ) : array();
	}

	$subscriptions = array();
	foreach ( $sub_ids as $sub_id ) {
		$sub = skyhshoso_get_subscription( $sub_id );
		if ( $sub ) {
			$subscriptions[ $sub_id ] = $sub;
		}
	}
	return $subscriptions;
}

/**
 * Get switch orders for a subscription.
 *
 * @param int $subscription_id
 * @return WC_Order[]
 */
function skyhshoso_get_switch_orders_for_subscription( $subscription_id ) {
	$orders = wc_get_orders( array(
		'limit'      => -1,
		'meta_key'   => '_skyhshoso_switch_subscription',
		'type'       => 'shop_order',
		'status'     => array( 'completed', 'processing', 'pending', 'on-hold', 'failed', 'cancelled', 'refunded' ),
	) );

	$switch_orders = array();
	foreach ( $orders as $order ) {
		$sub_ids = $order->get_meta( '_skyhshoso_switch_subscription' );
		if ( ! is_array( $sub_ids ) ) {
			$sub_ids = array( $sub_ids );
		}
		if ( in_array( $subscription_id, $sub_ids ) || in_array( (string) $subscription_id, $sub_ids ) ) {
			$switch_orders[ $order->get_id() ] = $order;
		}
	}
	return $switch_orders;
}

/**
 * Check if a product is of a switchable type.
 *
 * @param WC_Product|int $product
 * @return bool
 */
function skyhshoso_is_product_switchable_type( $product ) {
	if ( ! is_object( $product ) ) {
		$product = wc_get_product( $product );
	}
	if ( ! $product ) {
		return false;
	}

	// In SkyHS, any product with _skyhshoso_is_subscription = yes is switchable
	$product_id = $product->get_id();
	$parent_id  = $product->get_parent_id();
	$check_id   = $parent_id ? $parent_id : $product_id;
	$is_sub     = get_post_meta( $check_id, '_skyhshoso_is_subscription', true );

	return 'yes' === $is_sub;
}

/**
 * Check if the cart includes any switch items.
 *
 * @param string $item_action
 * @return array|false
 */
function skyhshoso_cart_contains_switches( $item_action = 'any' ) {
	if ( ! WC()->cart ) {
		return false;
	}

	$contains_switches = false;

	foreach ( WC()->cart->get_cart() as $cart_item ) {
		if ( isset( $cart_item['subscription_switch'] ) ) {
			if ( 'any' === $item_action ) {
				$contains_switches = $cart_item;
				break;
			}
			// subscription_switch doesn't have an 'action' key, so just return any
			$contains_switches = $cart_item;
			break;
		}
	}

	return $contains_switches;
}

/**
 * Get switch type for a cart item.
 *
 * @param array $cart_item
 * @return string|null
 */
function skyhshoso_get_cart_item_switch_type( $cart_item ) {
	if ( ! isset( $cart_item['subscription_switch'] ) ) {
		return null;
	}

	$subscription = skyhshoso_get_subscription( $cart_item['subscription_switch']['subscription_id'] );
	if ( ! $subscription ) {
		return null;
	}

	$old_total = (float) $subscription->get_total();
	$new_total = (float) $cart_item['subscription_switch']['total'];

	if ( $new_total > $old_total ) {
		return 'upgrade';
	} elseif ( $new_total < $old_total ) {
		return 'downgrade';
	}
	return 'crossgrade';
}

// ---------------------------------------------------------------------------
// Function shims (internal to copied switching feature)
// ---------------------------------------------------------------------------

	/**
	 * Get the canonical product ID for a cart item, order item, or product.
	 *
	 * @param WC_Product|array|WC_Order_Item $item
	 * @return int
	 */
	function skyhshoso_get_canonical_product_id( $item ) {
		if ( is_a( $item, 'WC_Product' ) ) {
			return $item->get_id();
		}
		if ( is_callable( array( $item, 'get_product_id' ) ) ) {
			return $item->get_product_id();
		}
		if ( is_array( $item ) && isset( $item['product_id'] ) ) {
			return $item['product_id'];
		}
		if ( is_array( $item ) && isset( $item['data'] ) && is_a( $item['data'], 'WC_Product' ) ) {
			return $item['data']->get_id();
		}
		return 0;
	}

	/**
	 * Get an order item by ID from an order/subscription.
	 *
	 * @param int $item_id
	 * @param WC_Order|SkyHSHOSO_Subscription $order
	 * @return WC_Order_Item|array|false
	 */
	function skyhshoso_get_order_item( $item_id, $order ) {
		if ( is_a( $order, 'SkyHSHOSO_Subscription' ) ) {
			// Search the subscription itself first
			foreach ( array( 'line_item', 'line_item_switched', 'shipping', 'fee', 'coupon' ) as $type ) {
				foreach ( $order->get_items( $type ) as $item ) {
					if ( $item->get_id() == $item_id ) {
						return $item;
					}
				}
			}

			// Search parent, switch, and renewal orders for the item ID
			$related_order_ids = $order->get_related_orders( 'ids', array( 'parent', 'switch', 'renewal' ) );
			foreach ( $related_order_ids as $order_id ) {
				$wc_order = wc_get_order( $order_id );
				if ( $wc_order ) {
					foreach ( $wc_order->get_items( array( 'line_item', 'line_item_switched', 'shipping', 'fee', 'coupon' ) ) as $item ) {
						if ( $item->get_id() == $item_id ) {
							return $item;
						}
					}
				}
			}
			return false;
		}
		if ( is_callable( array( $order, 'get_item' ) ) ) {
			return $order->get_item( $item_id );
		}
		return false;
	}

	/**
	 * Get the product limitation setting for switching.
	 *
	 * @param WC_Product $product
	 * @return string
	 */
	function skyhshoso_get_product_limitation( $product ) {
		return get_post_meta( $product->get_id(), '_skyhshoso_subscription_limit', true );
	}

	/**
	 * Get all subscriptions for a user.
	 *
	 * @param int $user_id
	 * @return SkyHSHOSO_Subscription[]
	 */
	function skyhshoso_get_users_subscriptions( $user_id ) {
		return skyhshoso_get_subscriptions( array(
			'customer_id' => $user_id,
		) );
	}

	/**
	 * Maybe prefix a key with a given prefix.
	 *
	 * @param string $key
	 * @param string $prefix
	 * @return string
	 */
	function skyhshoso_maybe_prefix_key( $key, $prefix ) {
		if ( 0 !== strpos( $key, $prefix ) ) {
			$key = $prefix . $key;
		}
		return $key;
	}

	/**
	 * Update the type of an order item.
	 *
	 * @param int    $item_id
	 * @param string $new_type
	 * @param int    $order_id
	 * @return bool
	 */
	function skyhshoso_update_order_item_type( $item_id, $new_type, $order_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$wpdb->prefix . 'woocommerce_order_items',
			array( 'order_item_type' => $new_type ),
			array( 'order_item_id' => $item_id ),
			array( '%s' ),
			array( '%d' )
		);
		return false !== $result;
	}

	/**
	 * Get used coupon codes for a subscription.
	 *
	 * @param SkyHSHOSO_Subscription|WC_Order $subscription
	 * @return array
	 */
	function skyhshoso_get_used_coupon_codes( $subscription ) {
		if ( is_a( $subscription, 'SkyHSHOSO_Subscription' ) ) {
			$parent = $subscription->get_parent();
			if ( $parent ) {
				return $parent->get_coupon_codes();
			}
			return array();
		}
		if ( is_callable( array( $subscription, 'get_coupon_codes' ) ) ) {
			return $subscription->get_coupon_codes();
		}
		return array();
	}

	/**
	 * Deprecated function notice.
	 *
	 * @param string $function
	 * @param string $version
	 * @param string $replacement
	 */
	function skyhshoso_deprecated_function( $function, $version, $replacement = null ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && apply_filters( 'skyhshoso_deprecated_function_trigger_error', true ) ) {
			_deprecated_function( $function, $version, $replacement ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Deprecated argument notice.
	 *
	 * @param string $function
	 * @param string $version
	 * @param string $message
	 */
	function skyhshoso_deprecated_argument( $function, $version, $message = null ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && apply_filters( 'skyhshoso_deprecated_function_trigger_error', true ) ) {
			_deprecated_argument( $function, $version, $message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Sort objects by a property.
	 *
	 * @param array  &$objects
	 * @param string $property
	 * @param string $direction
	 */
	function skyhshoso_sort_objects( &$objects, $property, $direction = 'descending' ) {
		$direction_multiplier = ( 'ascending' === $direction ) ? 1 : -1;

		usort( $objects, function ( $a, $b ) use ( $property, $direction_multiplier ) {
			$getter = 'get_' . $property;
			if ( is_callable( array( $a, $getter ) ) && is_callable( array( $b, $getter ) ) ) {
				$val_a = $a->$getter();
				$val_b = $b->$getter();
			} elseif ( isset( $a->$property ) && isset( $b->$property ) ) {
				$val_a = $a->$property;
				$val_b = $b->$property;
			} else {
				return 0;
			}

			if ( $val_a instanceof WC_DateTime ) {
				$val_a = $val_a->getTimestamp();
			}
			if ( $val_b instanceof WC_DateTime ) {
				$val_b = $val_b->getTimestamp();
			}

			return $direction_multiplier * ( $val_a > $val_b ? 1 : ( $val_a < $val_b ? -1 : 0 ) );
		} );
	}

	/**
	 * Find a matching line item in an order for a given subscription line item.
	 *
	 * @param WC_Order                $order
	 * @param WC_Order_Item_Product   $subscription_item
	 * @return WC_Order_Item_Product|false
	 */
	function skyhshoso_find_matching_line_item( $order, $subscription_item ) {
		$product_id = skyhshoso_get_canonical_product_id( $subscription_item );

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( $product_id === skyhshoso_get_canonical_product_id( $item ) ) {
				return $item;
			}
		}

		return false;
	}

	/**
	 * Get the name of an order item.
	 *
	 * @param WC_Order_Item $item
	 * @param array $args
	 * @return string
	 */
	function skyhshoso_get_order_item_name( $item, $args = array() ) {
		$name = $item->get_name();

		if ( ! empty( $args['attributes'] ) ) {
			if ( is_callable( array( $item, 'get_formatted_meta_data' ) ) ) {
				$meta_data = $item->get_formatted_meta_data( '_', true );
				if ( ! empty( $meta_data ) ) {
					$meta_strings = array();
					foreach ( $meta_data as $meta ) {
						$meta_strings[] = $meta->display_key . ': ' . $meta->value;
					}
					if ( ! empty( $meta_strings ) ) {
						$name .= ' (' . implode( ', ', $meta_strings ) . ')';
					}
				}
			}
		}

		return $name;
	}

	/**
	 * Get the product ID from an order item.
	 *
	 * @param int $item_id
	 * @return int
	 */
	function skyhshoso_get_order_items_product_id( $item_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE order_item_id = %d AND meta_key IN ('_product_id', '_variation_id') ORDER BY meta_key ASC LIMIT 1",
			$item_id
		) );
		return $meta ? (int) $meta : 0;
	}

	/**
	 * Check if manual renewals are required.
	 *
	 * @return bool
	 */
	function skyhshoso_is_manual_renewal_required() {
		return 'yes' !== get_option( 'skyhshoso_accept_manual_renewals', 'no' );
	}

	/**
	 * Check if an order contains a renewal.
	 *
	 * @param WC_Order $order
	 * @return bool
	 */
	function skyhshoso_order_contains_renewal( $order ) {
		if ( ! is_a( $order, 'WC_Abstract_Order' ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order ) {
			return false;
		}
		return (bool) $order->get_meta( '_skyhshoso_renewal_subscription_id' );
	}

	function skyhshoso_order_contains_early_renewal( $order ) {
		if ( ! is_a( $order, 'WC_Abstract_Order' ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order ) {
			return false;
		}
		return (bool) $order->get_meta( '_subscription_renewal_early' );
	}

	/**
	 * Get a subscription from a key.
	 *
	 * @param string $subscription_key
	 * @return SkyHSHOSO_Subscription|false
	 */
	function skyhshoso_get_subscription_from_key( $subscription_key ) {
		$parts = explode( '_', $subscription_key );
		$id    = isset( $parts[0] ) ? (int) $parts[0] : 0;
		return skyhshoso_get_subscription( $id );
	}

	/**
	 * Add time to a timestamp based on a period and interval.
	 *
	 * @param int    $interval
	 * @param string $period
	 * @param int    $timestamp
	 * @return int
	 */
	function skyhshoso_add_time( $interval, $period, $timestamp ) {
		$units = array(
			'day'   => DAY_IN_SECONDS,
			'week'  => WEEK_IN_SECONDS,
			'month' => MONTH_IN_SECONDS,
			'year'  => YEAR_IN_SECONDS,
		);

		if ( 'month' === $period ) {
			$time = new DateTime( '@' . $timestamp );
			$time->modify( '+' . $interval . ' months' );
			return $time->getTimestamp();
		}

		if ( 'year' === $period ) {
			$time = new DateTime( '@' . $timestamp );
			$time->modify( '+' . $interval . ' years' );
			return $time->getTimestamp();
		}

		$unit = isset( $units[ $period ] ) ? $units[ $period ] : DAY_IN_SECONDS;
		return $timestamp + ( $interval * $unit );
	}

	/**
	 * Get the number of days in a billing cycle.
	 *
	 * @param string $period
	 * @param int    $interval
	 * @return int
	 */
	function skyhshoso_get_days_in_cycle( $period, $interval ) {
		switch ( $period ) {
			case 'day':
				return $interval;
			case 'week':
				return $interval * 7;
			case 'month':
				return $interval * 30;
			case 'year':
				return $interval * 365;
			default:
				return $interval * 30;
		}
	}

	/**
	 * Get the last non-early renewal order for a subscription.
	 *
	 * @param SkyHSHOSO_Subscription $subscription
	 * @return WC_Order|false
	 */
	function skyhshoso_get_last_non_early_renewal_order( $subscription ) {
		if ( ! is_a( $subscription, 'SkyHSHOSO_Subscription' ) ) {
			return false;
		}

		$order_id = $subscription->get_order_id();
		if ( ! $order_id ) {
			return false;
		}

		$order = wc_get_order( $order_id );
		if ( $order && ! skyhshoso_order_contains_early_renewal( $order ) ) {
			return $order;
		}

		return false;
	}

	/**
	 * Check if currently doing AJAX.
	 *
	 * @return bool
	 */
	function skyhshoso_doing_ajax() {
		return defined( 'DOING_AJAX' ) && DOING_AJAX;
	}

	/**
	 * Check if an order contains a subscription switch.
	 *
	 * @param WC_Order|int $order
	 * @return bool
	 */
	/**
	 * Get a property from an object using a getter, or direct access.
	 *
	 * @param object $object
	 * @param string $key
	 * @return mixed
	 */
	function skyhshoso_get_objects_property( $object, $key ) {
		$getter = 'get_' . $key;
		if ( is_callable( array( $object, $getter ) ) ) {
			return $object->$getter();
		}
		if ( is_a( $object, 'WC_Data' ) ) {
			return $object->get_meta( $key );
		}
		if ( isset( $object->$key ) ) {
			return $object->$key;
		}
		return false;
	}

	/**
	 * Set a property on an object using a setter or direct access.
	 *
	 * @param object $object
	 * @param string $key
	 * @param mixed  $value
	 * @param string $action
	 */
	function skyhshoso_set_objects_property( $object, $key, $value, $action = '' ) {
		$setter = 'set_' . $key;
		if ( is_callable( array( $object, $setter ) ) ) {
			if ( 'set_prop_only' === $action ) {
				// Only set if the property does not already have a value
				$getter = 'get_' . $key;
				if ( is_callable( array( $object, $getter ) ) && $object->$getter() ) {
					return;
				}
			}
			$object->$setter( $value );
			return;
		}
		if ( is_a( $object, 'WC_Data' ) ) {
			$object->update_meta_data( $key, $value );
		} else {
			$object->$key = $value;
		}
	}

	/**
	 * Check if HPOS is enabled.
	 *
	 * @return bool
	 */
	function skyhshoso_is_custom_order_tables_usage_enabled() {
		return function_exists( 'wc_get_container' ) && class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Check if WooCommerce version is before a given version.
	 *
	 * @param string $version
	 * @return bool
	 */
	function skyhshoso_is_woocommerce_pre( $version ) {
		return version_compare( WC()->version, $version, '<' );
	}

	/**
	 * Check if the cart contains a renewal.
	 *
	 * @return bool
	 */
	function skyhshoso_cart_contains_renewal() {
		if ( ! WC()->cart ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( isset( $cart_item['subscription_renewal'] ) ) {
				return $cart_item;
			}
		}
		return false;
	}

	/**
	 * Check if an order contains a subscription.
	 *
	 * @param WC_Order|int $order
	 * @return bool
	 */
	function skyhshoso_order_contains_subscription( $order ) {
		$subscriptions = skyhshoso_get_subscriptions_for_order( $order );
		return ! empty( $subscriptions );
	}

	/**
	 * Get subscriptions for a renewal order.
	 *
	 * @param WC_Order|int $order
	 * @return SkyHSHOSO_Subscription[]
	 */
	function skyhshoso_get_subscriptions_for_renewal_order( $order ) {
		return skyhshoso_get_subscriptions_for_order( $order );
	}

	/**
	 * Copy payment method from a subscription to an order.
	 *
	 * @param WC_Order $order
	 * @param SkyHSHOSO_Subscription $subscription
	 */
	function skyhshoso_copy_payment_method_to_order( $order, $subscription ) {
		$order->set_payment_method( $subscription->get_payment_method() );
		$order->save();
	}

	/**
	 * Check if the cart contains a failed renewal order payment.
	 *
	 * @return bool
	 */
	function skyhshoso_cart_contains_failed_renewal_order_payment() {
		if ( ! WC()->cart ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( isset( $cart_item['subscription_failed_renewal'] ) ) {
				return $cart_item;
			}
		}
		return false;
	}

	/**
	 * Get orders with a meta query (HPOS-compat).
	 *
	 * @param array $query_args
	 * @return WC_Order[]
	 */
	function skyhshoso_get_orders_with_meta_query( $query_args ) {
		if ( function_exists( 'wc_get_orders' ) ) {
			return wc_get_orders( $query_args );
		}
		$query = new WP_Query( $query_args );
		return $query->posts;
	}

	/**
	 * Doing it wrong notice.
	 *
	 * @param string $function
	 * @param string $message
	 * @param string $version
	 */
	function skyhshoso_doing_it_wrong( $function, $message, $version ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && apply_filters( 'skyhshoso_doing_it_wrong_trigger_error', true ) ) {
			_doing_it_wrong( $function, $message, $version ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Check if an order contains a manual subscription.
	 *
	 * @param WC_Order|int $order
	 * @return bool
	 */
	function skyhshoso_order_contains_manual_subscription( $order ) {
		if ( ! is_a( $order, 'WC_Abstract_Order' ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order ) {
			return false;
		}
		$subs = skyhshoso_get_subscriptions_for_order( $order );
		foreach ( $subs as $sub ) {
			if ( $sub->is_manual() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Convert a string to ASCII.
	 *
	 * @param string $str
	 * @return string
	 */
	function skyhshoso_str_to_ascii( $str ) {
		$str = remove_accents( $str );
		$str = preg_replace( '/[^a-zA-Z0-9\s\-_]/', '', $str );
		return $str;
	}

	/**
	 * JSON encode with fallback.
	 *
	 * @param mixed $data
	 * @return string
	 */
	function skyhshoso_json_encode( $data ) {
		return wp_json_encode( $data );
	}

	/**
	 * Check if a given object is a SkyHSHOSO_Subscription.
	 *
	 * @param mixed $object
	 * @return bool
	 */
	function skyhshoso_is_subscription( $object ) {
		return is_a( $object, 'SkyHSHOSO_Subscription' );
	}
