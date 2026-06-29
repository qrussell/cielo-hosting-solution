<?php
/**
 * SkyHS UUID Utility
 *
 * Generates, stores, and retrieves UUIDv4 identifiers for all entity types.
 * Post types use meta key _skyhshoso_uuid. Subscriptions use a uuid column.
 *
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB
class SkyHSHOSO_UUID {

	/**
	 * Meta key used for all post types.
	 */
	const META_KEY = '_skyhshoso_uuid';

	/**
	 * Post types that receive UUIDs.
	 */
	const POST_TYPES = array( 'skyhshoso_hosting', 'skyhshoso_domain', 'skyhshoso_server', 'skyhshoso_wp_site', 'product' );

	// -------------------------------------------------------------------------
	// Generation
	// -------------------------------------------------------------------------

	/**
	 * Generate a v4 UUID and verify uniqueness across all entity types.
	 *
	 * @return string
	 */
	public static function generate_uuid() {
		do {
			$uuid = wp_generate_uuid4();
		} while ( self::uuid_exists( $uuid ) );
		return $uuid;
	}

	/**
	 * Check if a UUID already exists in any entity type.
	 *
	 * @param string $uuid
	 * @return bool
	 */
	public static function uuid_exists( $uuid ) {
		if ( self::uuid_exists_in_post( $uuid ) ) {
			return true;
		}
		if ( self::uuid_exists_in_subscription( $uuid ) ) {
			return true;
		}
		if ( self::uuid_exists_in_order( $uuid ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Check if a UUID exists in any post type.
	 *
	 * @param string $uuid
	 * @return bool
	 */
	public static function uuid_exists_in_post( $uuid ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
			self::META_KEY,
			$uuid
		) );
	}

	/**
	 * Check if a UUID exists in the subscription table.
	 *
	 * @param string $uuid
	 * @return bool
	 */
	public static function uuid_exists_in_subscription( $uuid ) {
		global $wpdb;
		$table = $wpdb->prefix . SkyHSHOSO_Subscription_DB::TABLE;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE uuid = %s",
			$uuid
		) );
	}

	/**
	 * Check if a UUID exists for any WooCommerce order.
	 *
	 * @param string $uuid
	 * @return bool
	 */
	public static function uuid_exists_in_order( $uuid ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
			self::META_KEY,
			$uuid
		) );
	}

	// -------------------------------------------------------------------------
	// Post UUIDs (hosting, domain, server, product)
	// -------------------------------------------------------------------------

	/**
	 * Get UUID for a post, generating one if absent.
	 *
	 * @param int $post_id
	 * @return string
	 */
	public static function get_post_uuid( $post_id ) {
		$uuid = get_post_meta( $post_id, self::META_KEY, true );
		if ( empty( $uuid ) ) {
			$uuid = self::set_post_uuid( $post_id );
		}
		return $uuid;
	}

	/**
	 * Generate and store a UUID for a post.
	 *
	 * @param int $post_id
	 * @return string
	 */
	public static function set_post_uuid( $post_id ) {
		$uuid = self::generate_uuid();
		update_post_meta( $post_id, self::META_KEY, $uuid );
		return $uuid;
	}

	/**
	 * Find a post by its UUID.
	 *
	 * @param string       $uuid
	 * @param string|array $post_types Optional post type filter.
	 * @return WP_Post|null
	 */
	public static function get_post_by_uuid( $uuid, $post_types = array() ) {
		$args = array(
			'post_type'      => ! empty( $post_types ) ? $post_types : self::POST_TYPES,
			'meta_key'       => self::META_KEY,
			'meta_value'     => $uuid,
			'posts_per_page' => 1,
			'post_status'    => 'any',
			'fields'         => 'all',
		);

		$posts = get_posts( $args );
		return ! empty( $posts ) ? $posts[0] : null;
	}

	// -------------------------------------------------------------------------
	// Subscription UUIDs
	// -------------------------------------------------------------------------

	/**
	 * Get UUID for a subscription, generating one if absent.
	 *
	 * @param int $subscription_id
	 * @return string
	 */
	public static function get_subscription_uuid( $subscription_id ) {
		$sub = SkyHSHOSO_Subscription_DB::get( $subscription_id );
		if ( ! $sub ) {
			return '';
		}
		if ( ! empty( $sub->uuid ) ) {
			return $sub->uuid;
		}
		return self::set_subscription_uuid( $subscription_id );
	}

	/**
	 * Generate and store a UUID for a subscription.
	 *
	 * @param int $subscription_id
	 * @return string
	 */
	public static function set_subscription_uuid( $subscription_id ) {
		$uuid = self::generate_uuid();
		SkyHSHOSO_Subscription_DB::update( $subscription_id, array( 'uuid' => $uuid ) );
		return $uuid;
	}

	/**
	 * Find a subscription by its UUID.
	 *
	 * @param string $uuid
	 * @return object|null
	 */
	public static function get_subscription_by_uuid( $uuid ) {
		return SkyHSHOSO_Subscription_DB::get_by_uuid( $uuid );
	}

	// -------------------------------------------------------------------------
	// Order UUIDs
	// -------------------------------------------------------------------------

	/**
	 * Get UUID for a WooCommerce order, generating one if absent.
	 *
	 * @param int $order_id
	 * @return string
	 */
	public static function get_order_uuid( $order_id ) {
		$uuid = $order_id ? get_post_meta( $order_id, self::META_KEY, true ) : '';
		if ( empty( $uuid ) ) {
			$uuid = self::set_order_uuid( $order_id );
		}
		return $uuid;
	}

	/**
	 * Generate and store a UUID for an order.
	 *
	 * @param int $order_id
	 * @return string
	 */
	public static function set_order_uuid( $order_id ) {
		$uuid = self::generate_uuid();
		update_post_meta( $order_id, self::META_KEY, $uuid );
		return $uuid;
	}

	/**
	 * Find an order by its UUID.
	 *
	 * @param string $uuid
	 * @return WC_Order|null
	 */
	public static function get_order_by_uuid( $uuid ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}

		$orders = wc_get_orders( array(
			'limit'     => 1,
			'meta_key'  => self::META_KEY,
			'meta_value' => $uuid,
			'type'      => 'shop_order',
		) );

		return ! empty( $orders ) ? $orders[0] : null;
	}

	// -------------------------------------------------------------------------
	// Backfill
	// -------------------------------------------------------------------------

	/**
	 * Count records missing UUIDs per entity type.
	 *
	 * @return array
	 */
	public static function get_backfill_counts() {
		global $wpdb;
		$counts = array();

		foreach ( self::POST_TYPES as $pt ) {
			$counts[ $pt ] = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
				WHERE p.post_type = %s AND pm.meta_id IS NULL",
				self::META_KEY,
				$pt
			) );
		}

		// Orders with subscription-related meta that lack UUIDs.
		$counts['shop_order'] = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
			WHERE p.post_type = 'shop_order'
			AND pm.meta_id IS NULL
			AND (
				p.ID IN ( SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_skyhshoso_renewal_subscription_id' )
				OR
				p.ID IN ( SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_skyhshoso_subscriptions_created' )
			)",
			self::META_KEY
		) );

		// Products with _skyhshoso_product_type set that lack UUIDs.
		$counts['product'] = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} ptm ON p.ID = ptm.post_id AND ptm.meta_key = '_skyhshoso_product_type'
			LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
			WHERE p.post_type = 'product' AND pm.meta_id IS NULL",
			self::META_KEY
		) );

		$sub_table = $wpdb->prefix . SkyHSHOSO_Subscription_DB::TABLE;
		$counts['subscription'] = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$sub_table} WHERE uuid IS NULL OR uuid = ''"
		);

		return $counts;
	}

	/**
	 * Backfill UUIDs for records missing them.
	 *
	 * @param int $limit Batch size.
	 * @return array Counts of UUIDs generated per type.
	 */
	public static function backfill_batch( $limit = 50 ) {
		global $wpdb;
		$generated = array();

		// Servers.
		$servers = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
			WHERE p.post_type = 'skyhshoso_server' AND pm.meta_id IS NULL
			LIMIT %d",
			self::META_KEY,
			$limit
		) );
		foreach ( $servers as $s ) {
			self::set_post_uuid( (int) $s->ID );
			$generated['skyhshoso_server'] = isset( $generated['skyhshoso_server'] ) ? $generated['skyhshoso_server'] + 1 : 1;
		}

		// Products with product type meta.
		$products = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} ptm ON p.ID = ptm.post_id AND ptm.meta_key = '_skyhshoso_product_type'
			LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
			WHERE p.post_type = 'product' AND pm.meta_id IS NULL
			LIMIT %d",
			self::META_KEY,
			$limit
		) );
		foreach ( $products as $p ) {
			self::set_post_uuid( (int) $p->ID );
			$generated['product'] = isset( $generated['product'] ) ? $generated['product'] + 1 : 1;
		}

		// Orders with subscription meta.
		$orders = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
			WHERE p.post_type = 'shop_order'
			AND pm.meta_id IS NULL
			AND (
				p.ID IN ( SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_skyhshoso_renewal_subscription_id' )
				OR
				p.ID IN ( SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_skyhshoso_subscriptions_created' )
			)
			LIMIT %d",
			self::META_KEY,
			$limit
		) );
		foreach ( $orders as $o ) {
			self::set_order_uuid( (int) $o->ID );
			$generated['shop_order'] = isset( $generated['shop_order'] ) ? $generated['shop_order'] + 1 : 1;
		}

		// Subscriptions.
		$sub_table = $wpdb->prefix . SkyHSHOSO_Subscription_DB::TABLE;
		$subs = $wpdb->get_results(
			"SELECT id FROM {$sub_table} WHERE uuid IS NULL OR uuid = '' LIMIT {$limit}"
		);
		foreach ( $subs as $s ) {
			self::set_subscription_uuid( (int) $s->id );
			$generated['subscription'] = isset( $generated['subscription'] ) ? $generated['subscription'] + 1 : 1;
		}

		// Hosting posts.
		$hosting = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
			WHERE p.post_type = 'skyhshoso_hosting' AND pm.meta_id IS NULL
			LIMIT %d",
			self::META_KEY,
			$limit
		) );
		foreach ( $hosting as $h ) {
			self::set_post_uuid( (int) $h->ID );
			$generated['skyhshoso_hosting'] = isset( $generated['skyhshoso_hosting'] ) ? $generated['skyhshoso_hosting'] + 1 : 1;
		}

		// Domain posts.
		$domains = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
			WHERE p.post_type = 'skyhshoso_domain' AND pm.meta_id IS NULL
			LIMIT %d",
			self::META_KEY,
			$limit
		) );
		foreach ( $domains as $d ) {
			self::set_post_uuid( (int) $d->ID );
			$generated['skyhshoso_domain'] = isset( $generated['skyhshoso_domain'] ) ? $generated['skyhshoso_domain'] + 1 : 1;
		}

		return $generated;
	}
}
// phpcs:enable WordPress.DB
