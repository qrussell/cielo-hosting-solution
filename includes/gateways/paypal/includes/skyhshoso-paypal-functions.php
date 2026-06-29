<?php
/**
 * SkyHS Hosting Solution PayPal Functions
 *
 * Helper functions for PayPal integration.
 *
 * @package    SkyHS Hosting Solution
 * @subpackage Gateways/PayPal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get the PayPal Subscription ID or Billing Agreement ID for a given order/subscription.
 */
function skyhshoso_get_paypal_id( $order ) {
	if ( ! is_object( $order ) ) {
		$order = wc_get_order( $order );
	}
	return $order ? $order->get_meta( '_paypal_subscription_id' ) : '';
}

/**
 * Store a PayPal Standard Subscription ID or Billing Agreement ID.
 */
function skyhshoso_set_paypal_id( $order, $paypal_subscription_id ) {
	if ( ! is_object( $order ) ) {
		$order = wc_get_order( $order );
	}
	if ( ! $order ) {
		return;
	}
	if ( skyhshoso_is_paypal_profile_a( $paypal_subscription_id, 'billing_agreement' ) ) {
		if ( ! in_array( $paypal_subscription_id, get_user_meta( $order->get_user_id(), '_paypal_subscription_id', false ) ) ) {
			add_user_meta( $order->get_user_id(), '_paypal_subscription_id', $paypal_subscription_id );
		}
	}
	$order->update_meta_data( '_paypal_subscription_id', $paypal_subscription_id );
	$order->save();
}

/**
 * Check if a PayPal profile ID is of a certain type.
 */
function skyhshoso_is_paypal_profile_a( $profile_id, $profile_type ) {
	if ( 'billing_agreement' === $profile_type && 'B-' === substr( $profile_id, 0, 2 ) ) {
		$is_a = true;
	} elseif ( 'out_of_date_id' === $profile_type && 'S-' === substr( $profile_id, 0, 2 ) ) {
		$is_a = true;
	} else {
		$is_a = false;
	}
	return apply_filters( 'skyhshoso_is_paypal_profile_a_' . $profile_type, $is_a, $profile_id );
}

/**
 * Limit item names to 127 characters (PayPal limit).
 */
function skyhshoso_get_paypal_item_name( $item_name ) {
	if ( strlen( $item_name ) > 127 ) {
		$item_name = substr( $item_name, 0, 124 ) . '...';
	}
	return html_entity_decode( $item_name, ENT_NOQUOTES, 'UTF-8' );
}

/**
 * Calculate trial periods for PayPal.
 */
function skyhshoso_calculate_paypal_trial_periods_until( $future_timestamp ) {
	$seconds_until_next_payment = $future_timestamp - gmdate( 'U' );
	$days_until_next_payment    = ceil( $seconds_until_next_payment / ( 60 * 60 * 24 ) );

	if ( $days_until_next_payment <= 90 ) {
		$first_trial_length = $days_until_next_payment;
		$first_trial_period = 'D';
		$second_trial_length = 0;
		$second_trial_period = 'D';
	} elseif ( $days_until_next_payment > 365 * 2 ) {
		$first_trial_length = floor( $days_until_next_payment / 365 );
		$first_trial_period = 'Y';
		$second_trial_length = $days_until_next_payment % 365;
		$second_trial_period = 'D';
	} elseif ( $days_until_next_payment > 365 ) {
		$first_trial_length = floor( $days_until_next_payment / 30 );
		$first_trial_period = 'M';
		$days_remaining = $days_until_next_payment % 30;
		if ( $days_remaining <= 90 ) {
			$second_trial_length = $days_remaining;
			$second_trial_period = 'D';
		} else {
			$second_trial_length = floor( $days_remaining / 7 );
			$second_trial_period = 'W';
		}
	} else {
		$first_trial_length = floor( $days_until_next_payment / 7 );
		$first_trial_period = 'W';
		$second_trial_length = $days_until_next_payment % 7;
		$second_trial_period = 'D';
	}

	return array(
		'first_trial_length'  => $first_trial_length,
		'first_trial_period'  => $first_trial_period,
		'second_trial_length' => $second_trial_length,
		'second_trial_period' => $second_trial_period,
	);
}

/**
 * Check if on the PayPal WC-API page.
 */
function skyhshoso_is_paypal_api_page() {
	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	return ( false !== strpos( $request_uri, 'wc-api/skyhshoso_paypal' ) );
}

// ---------------------------------------------------------------------------
// PayPal utility functions (internal to PayPal feature only)
// ---------------------------------------------------------------------------

/**
 * Convert a date string to a timestamp.
 */
function skyhshoso_date_to_time( $date_string ) {
	if ( is_numeric( $date_string ) ) {
		return (int) $date_string;
	}
	return strtotime( $date_string );
}

/**
 * Get the number of seconds since an order was created.
 */
function skyhshoso_seconds_since_order_created( $order ) {
	$order_id = is_object( $order ) ? $order->get_id() : $order;
	$post     = get_post( $order_id );
	if ( ! $post ) {
		return 0;
	}
	return time() - strtotime( $post->post_date_gmt );
}

/**
 * Estimate the number of periods between two dates.
 */
function skyhshoso_estimate_periods_between( $from_timestamp, $to_timestamp, $billing_period = 'month', $billing_interval = 1 ) {
	if ( $to_timestamp <= $from_timestamp ) {
		return 0;
	}
	$days_diff = ( $to_timestamp - $from_timestamp ) / DAY_IN_SECONDS;
	switch ( $billing_period ) {
		case 'day':
			$periods = $days_diff;
			break;
		case 'week':
			$periods = $days_diff / 7;
			break;
		case 'year':
			$periods = $days_diff / 365;
			break;
		case 'month':
		default:
			$from    = new DateTime( '@' . $from_timestamp );
			$to      = new DateTime( '@' . $to_timestamp );
			$diff    = $from->diff( $to );
			$periods = ( $diff->y * 12 ) + $diff->m;
			if ( $periods <= 0 ) {
				$periods = 1;
			}
			break;
	}
	return ceil( $periods / $billing_interval );
}

/**
 * Update a gateway setting.
 */
function skyhshoso_update_settings_option( $gateway, $key, $value ) {
	if ( is_object( $gateway ) && method_exists( $gateway, 'update_option' ) ) {
		$gateway->update_option( $key, $value );
	}
}

/**
 * Check if the cart contains a resubscribe.
 */
function skyhshoso_cart_contains_resubscribe() {
	if ( ! WC()->cart ) {
		return false;
	}
	foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
		if ( isset( $cart_item['subscription_resubscribe'] ) ) {
			return $cart_item;
		}
	}
	return false;
}

/**
 * Check if a product can be switched.
 */
if ( ! function_exists( 'skyhshoso_is_product_switchable_type' ) ) {
function skyhshoso_is_product_switchable_type( $product_id ) {
	$product = wc_get_product( $product_id );
	if ( ! $product ) {
		return false;
	}
	// Simple products and variations of subscription-type products are switchable.
	return in_array( $product->get_type(), array( 'simple', 'variable', 'subscription', 'variable-subscription', 'subscription_variation' ), true );
}
}

/**
 * Check if the cart contains subscription switches.
 */
if ( ! function_exists( 'skyhshoso_cart_contains_switches' ) ) {
function skyhshoso_cart_contains_switches() {
	if ( ! WC()->cart ) {
		return false;
	}
	foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
		if ( isset( $cart_item['subscription_switch'] ) ) {
			return $cart_item;
		}
	}
	return false;
}
}

/**
 * Check if an order contains a switch.
 */
if ( ! function_exists( 'skyhshoso_order_contains_switch' ) ) {
function skyhshoso_order_contains_switch( $order ) {
	$order_id = is_object( $order ) ? $order->get_id() : $order;
	$order    = wc_get_order( $order_id );
	if ( ! $order ) {
		return false;
	}
	return (bool) $order->get_meta( '_subscription_switch_data' );
}
}

/**
 * Get subscriptions for a switch order.
 */
if ( ! function_exists( 'skyhshoso_get_subscriptions_for_switch_order' ) ) {
function skyhshoso_get_subscriptions_for_switch_order( $order_id ) {
	return skyhshoso_get_subscriptions_for_order( $order_id );
}
}

/**
 * Check if a given object is a WC_Order.
 */
function skyhshoso_is_order( $object ) {
	return is_a( $object, 'WC_Order' );
}

/**
 * Create a renewal order for a subscription.
 */
function skyhshoso_create_renewal_order( $subscription ) {
	$renewal_order = wc_create_order( array(
		'customer_id' => $subscription->get_customer_id(),
		'created_via' => 'skyhshoso_renewal',
		'status'      => 'pending',
	) );

	if ( is_wp_error( $renewal_order ) ) {
		return false;
	}

	$renewal_order->update_meta_data( '_skyhshoso_renewal_subscription_id', $subscription->get_id() );

	$product = wc_get_product( $subscription->get_product_id() );
	if ( $product ) {
		$renewal_order->add_product( $product, 1, array(
			'subtotal' => $subscription->get_total(),
			'total'    => $subscription->get_total(),
		) );
	} else {
		$item = new WC_Order_Item_Fee();
		$item->set_name( __( 'Subscription Renewal', 'skyhs-hosting-solution' ) );
		$item->set_total( $subscription->get_total() );
		$renewal_order->add_item( $item );
	}

	$user = get_userdata( $subscription->get_customer_id() );
	if ( $user ) {
		$fields = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' );
		foreach ( $fields as $field ) {
			$value = get_user_meta( $user->ID, 'billing_' . $field, true );
			if ( $value ) {
				$renewal_order->{'set_billing_' . $field}( $value );
			}
		}
	}

	$renewal_order->set_payment_method( $subscription->get_payment_method() );
	$renewal_order->calculate_totals();
	$renewal_order->save();

	return $renewal_order;
}

/**
 * Insert an array after a specific key in another array.
 */
function skyhshoso_array_insert_after( $needle, $haystack, $new_key, $new_value ) {
	if ( ! is_array( $haystack ) ) {
		return $haystack;
	}
	$new_array = array();
	$inserted  = false;
	foreach ( $haystack as $key => $value ) {
		$new_array[ $key ] = $value;
		if ( $key === $needle ) {
			$new_array[ $new_key ] = $new_value;
			$inserted = true;
		}
	}
	if ( ! $inserted ) {
		$new_array[ $new_key ] = $new_value;
	}
	return $new_array;
}
