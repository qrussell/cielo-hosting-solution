<?php
/**
 * SkyHS Export Engine
 *
 * Produces a single JSON export file containing all SkyHS entity types
 * with internal IDs replaced by UUIDs for portable cross-site transfer.
 *
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

class SkyHSHOSO_Export {

	const EXPORT_VERSION = '1.0';

	/**
	 * Meta keys that contain IDs needing UUID replacement, keyed by entity type.
	 * Maps meta_key => entity_type_in_uuid_map (used to look up the UUID).
	 */
	const REPLACEMENT_META_KEYS = array(
		'skyhshoso_hosting' => array(
			'_skyhshoso_hosting_product_id' => 'product',
			'_skyhshoso_variation_id'       => 'variation',
			'skyhshoso_subscription_id'     => 'subscription',
			'skyhshoso_server_id'           => 'server',
		),
		'skyhshoso_domain' => array(
			'_skyhshoso_domain_product_id' => 'product',
			'skyhshoso_subscription_id'    => 'subscription',
		),
		'skyhshoso_wp_site' => array(
			'_skyhshoso_hosting_product_id' => 'product',
			'_skyhshoso_variation_id'       => 'variation',
			'skyhshoso_subscription_id'     => 'subscription',
			'skyhshoso_server_id'           => 'server',
		),
		'product' => array(
			'_skyhshoso_server_id' => 'server',
		),
		'shop_order' => array(
			'_skyhshoso_renewal_subscription_id' => 'subscription',
		),
	);

	/**
	 * Export all entity types into a single array.
	 *
	 * @param array $include_types Entity types to include. Empty = all.
	 * @return array Export data ready for JSON encoding.
	 */
	public static function export_all( $include_types = array() ) {
		$include_types = ! empty( $include_types ) ? $include_types : array( 'users', 'servers', 'products', 'orders', 'subscriptions', 'hosting', 'domains', 'wp_sites', 'settings' );

		$data = array();

		if ( in_array( 'servers', $include_types, true ) ) {
			$data['servers'] = self::export_servers();
		}
		if ( in_array( 'products', $include_types, true ) ) {
			$data['products'] = self::export_products();
		}
		if ( in_array( 'orders', $include_types, true ) ) {
			$data['orders'] = self::export_orders();
		}
		if ( in_array( 'subscriptions', $include_types, true ) ) {
			$data['subscriptions'] = self::export_subscriptions();
		}
		if ( in_array( 'hosting', $include_types, true ) ) {
			$data['hosting'] = self::export_hosting();
		}
		if ( in_array( 'domains', $include_types, true ) ) {
			$data['domains'] = self::export_domains();
		}
		if ( in_array( 'wp_sites', $include_types, true ) ) {
			$data['wp_sites'] = self::export_wp_sites();
		}
		if ( in_array( 'settings', $include_types, true ) ) {
			$data['settings'] = self::export_settings();
		}
		if ( in_array( 'users', $include_types, true ) ) {
			$data['users'] = self::export_users();
		}

		$entity_count = array();
		foreach ( $data as $key => $items ) {
			$entity_count[ $key ] = count( $items );
		}

		return array(
			'version'       => self::EXPORT_VERSION,
			'plugin_version' => SKYHSHOSO_VERSION,
			'export_date'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'entity_count'  => $entity_count,
			'data'          => $data,
		);
	}

	// -------------------------------------------------------------------------
	// Section Exporters
	// -------------------------------------------------------------------------

	/**
	 * Export all servers.
	 *
	 * @return array
	 */
	public static function export_servers() {
		$servers = get_posts( array(
			'post_type'      => 'skyhshoso_server',
			'posts_per_page' => -1,
			'post_status'    => 'any',
		) );

		$result = array();
		foreach ( $servers as $server ) {
			$meta = get_post_meta( $server->ID );

			$result[] = array(
				'uuid'  => SkyHSHOSO_UUID::get_post_uuid( $server->ID ),
				'title' => $server->post_title,
				'meta'  => self::flatten_meta( $meta, array( SkyHSHOSO_UUID::META_KEY ) ),
			);
		}

		return $result;
	}

	/**
	 * Export products that have a SkyHS product type.
	 *
	 * @return array
	 */
	public static function export_products() {
		$products = get_posts( array(
			'post_type'      => 'product',
			'posts_per_page' => -1,
			'post_status'    => 'any',
			'meta_key'       => '_skyhshoso_product_type',
		) );

		$result = array();
		foreach ( $products as $product ) {
			$meta = get_post_meta( $product->ID );
			$wc_product = wc_get_product( $product->ID );
			if ( ! $wc_product ) {
				continue;
			}
			$variations = array();

			if ( $wc_product && $wc_product->is_type( 'variable' ) ) {
				$child_ids = $wc_product->get_children();
				foreach ( $child_ids as $vid ) {
					$vmeta = get_post_meta( $vid );
					$variations[] = array(
						'uuid'  => SkyHSHOSO_UUID::get_post_uuid( $vid ),
						'sku'   => get_post_meta( $vid, '_sku', true ),
						'price' => get_post_meta( $vid, '_price', true ),
						'meta'  => self::flatten_meta( $vmeta, array( SkyHSHOSO_UUID::META_KEY, '_sku', '_price' ) ),
					);
				}
			}

			$entry = array(
				'uuid'                  => SkyHSHOSO_UUID::get_post_uuid( $product->ID ),
				'title'                 => $product->post_title,
				'type'                  => $wc_product ? $wc_product->get_type() : 'simple',
				'sku'                   => $wc_product ? $wc_product->get_sku() : '',
				'price'                 => $wc_product ? $wc_product->get_price() : '',
				'skyhshoso_product_type' => get_post_meta( $product->ID, '_skyhshoso_product_type', true ),
				'meta'                  => self::flatten_meta( $meta, array( SkyHSHOSO_UUID::META_KEY ) ),
				'variations'            => $variations,
			);

			$result[] = $entry;
		}

		return $result;
	}

	/**
	 * Export subscription-related orders.
	 *
	 * @return array
	 */
	public static function export_orders() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$order_ids = $wpdb->get_col(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
			WHERE meta_key IN ( '_skyhshoso_renewal_subscription_id', '_skyhshoso_subscriptions_created' )"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $order_ids ) ) {
			return array();
		}

		$result = array();
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			$meta = get_post_meta( $order_id );

			$result[] = array(
				'uuid'           => SkyHSHOSO_UUID::get_order_uuid( $order_id ),
				'status'         => $order->get_status(),
				'total'          => $order->get_total(),
				'currency'       => $order->get_currency(),
				'customer_email' => $order->get_billing_email(),
				'date_created'   => $order->get_date_created() ? $order->get_date_created()->format( 'Y-m-d H:i:s' ) : '',
				'meta'           => self::flatten_meta( $meta, array( SkyHSHOSO_UUID::META_KEY ) ),
			);
		}

		return $result;
	}

	/**
	 * Export all subscriptions.
	 *
	 * @return array
	 */
	public static function export_subscriptions() {
		$subs = SkyHSHOSO_Subscription_DB::query( array() );
		if ( empty( $subs ) ) {
			return array();
		}

		$result = array();
		foreach ( $subs as $sub ) {
			$sub_array = (array) $sub;
			$sub_meta  = SkyHSHOSO_Subscription_DB::get_all_meta( (int) $sub->id );

			$sub_array['uuid']    = SkyHSHOSO_UUID::get_subscription_uuid( (int) $sub->id );
			$sub_array['user_id'] = self::get_user_email( (int) $sub->user_id );
			$sub_array['meta']    = $sub_meta;

			// Remove internal ID.
			unset( $sub_array['id'] );

			$result[] = $sub_array;
		}

		return $result;
	}

	/**
	 * Export all hosting posts.
	 *
	 * @return array
	 */
	public static function export_hosting() {
		$posts = get_posts( array(
			'post_type'      => 'skyhshoso_hosting',
			'posts_per_page' => -1,
			'post_status'    => 'any',
		) );

		$result = array();
		foreach ( $posts as $post ) {
			$author = $post->post_author ? get_user_by( 'id', $post->post_author ) : null;
			$meta   = get_post_meta( $post->ID );

			$result[] = array(
				'uuid'         => SkyHSHOSO_UUID::get_post_uuid( $post->ID ),
				'title'        => $post->post_title,
				'author_email' => $author ? $author->user_email : '',
				'meta'         => self::flatten_meta( $meta, array( SkyHSHOSO_UUID::META_KEY ) ),
			);
		}

		return $result;
	}

	/**
	 * Export all domain posts.
	 *
	 * @return array
	 */
	public static function export_domains() {
		$posts = get_posts( array(
			'post_type'      => 'skyhshoso_domain',
			'posts_per_page' => -1,
			'post_status'    => 'any',
		) );

		$result = array();
		foreach ( $posts as $post ) {
			$author = $post->post_author ? get_user_by( 'id', $post->post_author ) : null;
			$meta   = get_post_meta( $post->ID );

			$result[] = array(
				'uuid'         => SkyHSHOSO_UUID::get_post_uuid( $post->ID ),
				'title'        => $post->post_title,
				'author_email' => $author ? $author->user_email : '',
				'meta'         => self::flatten_meta( $meta, array( SkyHSHOSO_UUID::META_KEY ) ),
			);
		}

		return $result;
	}

	/**
	 * Export all WP site posts.
	 *
	 * @return array
	 */
	public static function export_wp_sites() {
		$posts = get_posts( array(
			'post_type'      => 'skyhshoso_wp_site',
			'posts_per_page' => -1,
			'post_status'    => 'any',
		) );

		$result = array();
		foreach ( $posts as $post ) {
			$author = $post->post_author ? get_user_by( 'id', $post->post_author ) : null;
			$meta   = get_post_meta( $post->ID );

			$result[] = array(
				'uuid'         => SkyHSHOSO_UUID::get_post_uuid( $post->ID ),
				'title'        => $post->post_title,
				'author_email' => $author ? $author->user_email : '',
				'meta'         => self::flatten_meta( $meta, array( SkyHSHOSO_UUID::META_KEY ) ),
			);
		}

		return $result;
	}

	/**
	 * Export plugin global settings and options.
	 *
	 * @return array
	 */
	public static function export_settings() {
		$settings = array();

		// General settings group
		$general = get_option( 'skyhshoso_settings_group', array() );
		// Resolve dashboard page ID to slug
		if ( ! empty( $general['dashboard_page'] ) ) {
			$post = get_post( $general['dashboard_page'] );
			if ( $post ) {
				$general['dashboard_page_slug'] = $post->post_name;
			}
		}
		$settings['skyhshoso_settings_group'] = $general;

		// Enom settings
		$settings['enom'] = array(
			'skyhshoso_enom_live_username'       => get_option( 'skyhshoso_enom_live_username', '' ),
			'skyhshoso_enom_live_password'       => get_option( 'skyhshoso_enom_live_password', '' ),
			'skyhshoso_enom_test_username'       => get_option( 'skyhshoso_enom_test_username', '' ),
			'skyhshoso_enom_test_password'       => get_option( 'skyhshoso_enom_test_password', '' ),
			'skyhshoso_enom_mode'                => get_option( 'skyhshoso_enom_mode', 'test' ),
			'skyhshoso_enom_price_markup'        => get_option( 'skyhshoso_enom_price_markup', '0' ),
			'skyhshoso_enom_default_nameservers' => get_option( 'skyhshoso_enom_default_nameservers', array() ),
		);

		// Customize dashboard menu items
		$settings['skyhshoso_dashboard_menu_items'] = get_option( 'skyhshoso_dashboard_menu_items', null );

		// Invoice dashboard page ID (resolve to slug)
		$invoice_page_id = get_option( 'skyhshoso_dashboard_page_id', 0 );
		if ( $invoice_page_id ) {
			$post = get_post( $invoice_page_id );
			if ( $post ) {
				$settings['skyhshoso_dashboard_page_slug'] = $post->post_name;
			}
		}

		// Billing, Subscription, Switch, and Renewal settings
		$settings['billing_subscription'] = array(
			'skyhs_hosting_solution_enable_early_renewal'     => get_option( 'skyhs_hosting_solution_enable_early_renewal', 'no' ),
			'skyhshoso_drip_downloadable_content_on_renewal'   => get_option( 'skyhshoso_drip_downloadable_content_on_renewal', 'no' ),
			'skyhshoso_zero_initial_payment_requires_payment' => get_option( 'skyhshoso_zero_initial_payment_requires_payment', 'no' ),
			'skyhshoso_accept_manual_renewals'                => get_option( 'skyhshoso_accept_manual_renewals', 'no' ),
			'skyhshoso_turn_off_automatic_payments'            => get_option( 'skyhshoso_turn_off_automatic_payments', 'no' ),
			'skyhshoso_allow_switching'                       => get_option( 'skyhshoso_allow_switching', 'no' ),
			'skyhshoso_apportion_recurring_price'             => get_option( 'skyhshoso_apportion_recurring_price', 'no' ),
			'skyhshoso_apportion_sign_up_fee'                 => get_option( 'skyhshoso_apportion_sign_up_fee', 'no' ),
			'skyhshoso_apportion_length'                      => get_option( 'skyhshoso_apportion_length', 'no' ),
		);

		return $settings;
	}

	/**
	 * Export all users referenced by plugin entities.
	 *
	 * Collects unique users from subscriptions, hosting posts, domain posts,
	 * and subscription-related orders. Exports core user fields and whitelisted meta.
	 *
	 * Users are identified by email (not UUID) since email is the portable identifier
	 * already used throughout the export schema.
	 *
	 * @return array
	 */
	public static function export_users() {
		$user_emails = array();

		// Collect from subscriptions.
		$subs = SkyHSHOSO_Subscription_DB::query( array() );
		foreach ( $subs as $sub ) {
			if ( $sub->user_id ) {
				$user = get_user_by( 'id', $sub->user_id );
				if ( $user ) {
					$user_emails[ $user->user_email ] = true;
				}
			}
		}

		// Collect from hosting post authors.
		$hosting_ids = get_posts( array(
			'post_type'      => 'skyhshoso_hosting',
			'posts_per_page' => -1,
			'post_status'    => 'any',
			'fields'         => 'ids',
		) );
		foreach ( $hosting_ids as $post_id ) {
			$author_id = (int) get_post_field( 'post_author', $post_id );
			if ( $author_id ) {
				$user = get_user_by( 'id', $author_id );
				if ( $user ) {
					$user_emails[ $user->user_email ] = true;
				}
			}
		}

		// Collect from domain post authors.
		$domain_ids = get_posts( array(
			'post_type'      => 'skyhshoso_domain',
			'posts_per_page' => -1,
			'post_status'    => 'any',
			'fields'         => 'ids',
		) );
		foreach ( $domain_ids as $post_id ) {
			$author_id = (int) get_post_field( 'post_author', $post_id );
			if ( $author_id ) {
				$user = get_user_by( 'id', $author_id );
				if ( $user ) {
					$user_emails[ $user->user_email ] = true;
				}
			}
		}

		// Collect from WordPress site post authors.
		$wp_site_ids = get_posts( array(
			'post_type'      => 'skyhshoso_wp_site',
			'posts_per_page' => -1,
			'post_status'    => 'any',
			'fields'         => 'ids',
		) );
		foreach ( $wp_site_ids as $post_id ) {
			$author_id = (int) get_post_field( 'post_author', $post_id );
			if ( $author_id ) {
				$user = get_user_by( 'id', $author_id );
				if ( $user ) {
					$user_emails[ $user->user_email ] = true;
				}
			}
		}

		// Collect from subscription-related orders.
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$order_ids = $wpdb->get_col(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
			WHERE meta_key IN ( '_skyhshoso_renewal_subscription_id', '_skyhshoso_subscriptions_created' )"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( $order_ids as $order_id ) {
			$email = get_post_meta( $order_id, '_billing_email', true );
			if ( $email ) {
				$user_emails[ $email ] = true;
			}
		}

		if ( empty( $user_emails ) ) {
			return array();
		}

		$result = array();
		foreach ( array_keys( $user_emails ) as $email ) {
			$user = get_user_by( 'email', $email );
			if ( ! $user ) {
				continue;
			}

			// Whitelist of user meta to export (no passwords or sensitive data).
			$meta = array();
			$meta_keys = array( 'first_name', 'last_name', 'nickname', 'description' );
			foreach ( $meta_keys as $key ) {
				$value = get_user_meta( $user->ID, $key, true );
				if ( '' !== $value ) {
					$meta[ $key ] = $value;
				}
			}

			$result[] = array(
				'user_email'      => $user->user_email,
				'user_login'      => $user->user_login,
				'display_name'    => $user->display_name,
				'user_registered' => $user->user_registered,
				'roles'           => $user->roles,
				'meta'            => $meta,
			);
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// ID -> UUID Replacement
	// -------------------------------------------------------------------------

	/**
	 * Replace all numeric entity IDs with their UUID equivalents in the export data.
	 * Modifies the array in place and returns it.
	 *
	 * @param array $export_data Reference to the full export array.
	 * @return array
	 */
	public static function replace_ids_with_uuids( &$export_data ) {
		$data = &$export_data['data'];

		// Pass 1: Replace IDs in entity meta fields.
		foreach ( array( 'hosting', 'domains', 'wp_sites' ) as $key ) {
			if ( ! empty( $data[ $key ] ) ) {
				foreach ( $data[ $key ] as &$item ) {
					if ( 'hosting' === $key ) {
						$post_type = 'skyhshoso_hosting';
					} elseif ( 'domains' === $key ) {
						$post_type = 'skyhshoso_domain';
					} else {
						$post_type = 'skyhshoso_wp_site';
					}
					self::replace_meta_ids( $item['meta'], $post_type );
				}
			}
		}

		if ( ! empty( $data['products'] ) ) {
			foreach ( $data['products'] as &$product ) {
				self::replace_meta_ids( $product['meta'], 'product' );
				if ( ! empty( $product['variations'] ) ) {
					foreach ( $product['variations'] as &$var ) {
						self::replace_meta_ids( $var['meta'], 'product' );
					}
				}
			}
		}

		if ( ! empty( $data['orders'] ) ) {
			foreach ( $data['orders'] as &$order ) {
				self::replace_meta_ids( $order['meta'], 'shop_order' );
			}
		}

		// Pass 2: Replace IDs in subscription rows.
		if ( ! empty( $data['subscriptions'] ) ) {
			foreach ( $data['subscriptions'] as &$sub ) {
				$sub['product_id']   = self::resolve_id_to_uuid( $sub['product_id'], 'product' );
				$sub['variation_id'] = self::resolve_id_to_uuid( $sub['variation_id'], 'variation' );
				$sub['order_id']     = self::resolve_id_to_uuid( $sub['order_id'], 'order' );
				// user_id is already an email at this point.
			}
		}

		return $export_data;
	}

	/**
	 * Replace known ID meta values with UUIDs.
	 *
	 * @param array  $meta      Reference to meta array.
	 * @param string $post_type Post type for replacement key lookup.
	 */
	private static function replace_meta_ids( &$meta, $post_type ) {
		if ( ! isset( self::REPLACEMENT_META_KEYS[ $post_type ] ) ) {
			return;
		}
		foreach ( self::REPLACEMENT_META_KEYS[ $post_type ] as $meta_key => $entity_type ) {
			if ( isset( $meta[ $meta_key ] ) && ! empty( $meta[ $meta_key ] ) ) {
				$meta[ $meta_key ] = self::resolve_id_to_uuid( $meta[ $meta_key ], $entity_type );
			}
		}
	}

	/**
	 * Given a numeric ID and entity type, return the UUID.
	 * If it's already a UUID (non-numeric), return as-is.
	 *
	 * @param mixed  $id          Numeric ID or existing UUID.
	 * @param string $entity_type Entity type key (product, variation, subscription, order, server).
	 * @return string
	 */
	private static function resolve_id_to_uuid( $id, $entity_type ) {
		if ( empty( $id ) || '0' === (string) $id ) {
			return '';
		}

		// If already a UUID, return as-is.
		if ( ! is_numeric( $id ) ) {
			return $id;
		}

		$id = (int) $id;

		switch ( $entity_type ) {
			case 'product':
			case 'variation':
				return SkyHSHOSO_UUID::get_post_uuid( $id );
			case 'server':
				return SkyHSHOSO_UUID::get_post_uuid( $id );
			case 'subscription':
				return SkyHSHOSO_UUID::get_subscription_uuid( $id );
			case 'order':
				return SkyHSHOSO_UUID::get_order_uuid( $id );
			default:
				return (string) $id;
		}
	}

	// -------------------------------------------------------------------------
	// Output
	// -------------------------------------------------------------------------

	/**
	 * Encode export data as JSON.
	 *
	 * @param array $data Export data.
	 * @return string
	 */
	public static function generate_json( $data ) {
		return wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Send JSON as a download to the browser.
	 *
	 * @param string $json     JSON string.
	 * @param string $filename Optional filename.
	 */
	public static function download_file( $json, $filename = '' ) {
		if ( empty( $filename ) ) {
			$filename = 'skyhs-export-' . gmdate( 'Y-m-d-His' ) . '.json';
		}

		// Clear any output buffers.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		header( 'Content-Type: application/json' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Content-Length: ' . strlen( $json ) );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Flatten post meta array (WP returns arrays of values) and skip internal keys.
	 *
	 * @param array $meta        Raw post_meta array.
	 * @param array $skip_keys   Additional keys to exclude.
	 * @return array
	 */
	private static function flatten_meta( $meta, $skip_keys = array() ) {
		$skip = array_merge( array( '_edit_lock', '_edit_last' ), $skip_keys );
		$flat = array();
		foreach ( $meta as $key => $values ) {
			if ( in_array( $key, $skip, true ) ) {
				continue;
			}
			$flat[ $key ] = maybe_unserialize( is_array( $values ) ? $values[0] : $values );
		}
		return $flat;
	}

	/**
	 * Get user email by ID, or return '0' if not found.
	 *
	 * @param int $user_id
	 * @return string
	 */
	private static function get_user_email( $user_id ) {
		if ( ! $user_id ) {
			return '';
		}
		$user = get_user_by( 'id', $user_id );
		return $user ? $user->user_email : '';
	}
}
