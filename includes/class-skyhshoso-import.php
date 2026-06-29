<?php
/**
 * SkyHS Import Engine
 *
 * Reads an exported JSON file, maps UUIDs to local IDs, and creates or
 * updates records in dependency order. Skips records whose UUIDs already
 * exist locally.
 *
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

class SkyHSHOSO_Import {

	/**
	 * UUID pattern for detection.
	 */
	const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

	/**
	 * UUID → local ID map. Key format: "{entity_type}_{uuid}".
	 *
	 * @var array
	 */
	private $uuid_map = array();

	/**
	 * Deferred reference resolution.
	 * Used when an order references a subscription UUID not yet imported.
	 *
	 * @var array
	 */
	private $deferred_refs = array();

	/**
	 * Import results.
	 *
	 * @var array
	 */
	private $results = array(
		'users'         => array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array() ),
		'servers'       => array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array() ),
		'products'      => array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array() ),
		'orders'        => array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array() ),
		'subscriptions' => array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array() ),
		'hosting'       => array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array() ),
		'domains'       => array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array() ),
		'wp_sites'      => array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array() ),
		'settings'      => array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array() ),
	);

	// -------------------------------------------------------------------------
	// Entry Points
	// -------------------------------------------------------------------------

	/**
	 * Import from a JSON string.
	 *
	 * @param string $json_string
	 * @return array Results array.
	 */
	public function import_from_string( $json_string ) {
		$data = json_decode( $json_string, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return array( 'error' => 'Invalid JSON: ' . json_last_error_msg() );
		}

		$validation = $this->validate_structure( $data );
		if ( true !== $validation ) {
			return array( 'error' => $validation );
		}

		// Build map of existing UUIDs so we can detect duplicates.
		$this->build_initial_uuid_map( $data );

		// Import in dependency order (users first — everything references them).
		$this->import_users( $data['data']['users'] ?? array() );
		$this->import_servers( $data['data']['servers'] ?? array() );
		$this->import_products( $data['data']['products'] ?? array() );
		$this->import_orders( $data['data']['orders'] ?? array() );
		$this->import_subscriptions( $data['data']['subscriptions'] ?? array() );
		$this->import_hosting( $data['data']['hosting'] ?? array() );
		$this->import_domains( $data['data']['domains'] ?? array() );
		$this->import_wp_sites( $data['data']['wp_sites'] ?? array() );
		$this->import_settings( $data['data']['settings'] ?? array() );

		$this->resolve_deferred_references();

		return $this->results;
	}

	/**
	 * Import from a JSON file.
	 *
	 * @param string $file_path
	 * @return array
	 */
	public function import_from_file( $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return array( 'error' => 'File not found: ' . $file_path );
		}

		$contents = file_get_contents( $file_path );
		if ( false === $contents ) {
			return array( 'error' => 'Failed to read file.' );
		}

		return $this->import_from_string( $contents );
	}

	/**
	 * Get results after import completes.
	 *
	 * @return array
	 */
	public function get_results() {
		return $this->results;
	}

	/**
	 * Check whether the import had zero errors (all records succeeded).
	 *
	 * @return bool
	 */
	public function is_successful() {
		foreach ( $this->results as $type => $data ) {
			if ( ! empty( $data['errors'] ) ) {
				return false;
			}
		}
		return true;
	}

	// -------------------------------------------------------------------------
	// Validation
	// -------------------------------------------------------------------------

	/**
	 * Validate the imported data structure.
	 *
	 * @param array $data
	 * @return true|string True if valid, error message string otherwise.
	 */
	private function validate_structure( $data ) {
		if ( ! is_array( $data ) ) {
			return 'Import data must be an array.';
		}
		if ( empty( $data['version'] ) ) {
			return 'Missing version field.';
		}
		if ( ! isset( $data['data'] ) || ! is_array( $data['data'] ) ) {
			return 'Missing data section.';
		}
		return true;
	}

	// -------------------------------------------------------------------------
	// UUID Map
	// -------------------------------------------------------------------------

	/**
	 * Build initial UUID→local-ID map from existing database records.
	 * This prevents duplicate creation on re-import.
	 *
	 * @param array $data Import data (used to know which types to scan).
	 */
	private function build_initial_uuid_map( $data ) {
		global $wpdb;

		// Scan post types for existing UUIDs.
		$post_types = array( 'skyhshoso_server', 'skyhshoso_hosting', 'skyhshoso_domain', 'skyhshoso_wp_site', 'product', 'shop_order' );
		foreach ( $post_types as $pt ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT p.ID, pm.meta_value
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
				WHERE p.post_type = %s AND pm.meta_value != ''",
				SkyHSHOSO_UUID::META_KEY,
				$pt
			) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			foreach ( $rows as $row ) {
				$key = $this->entity_type_key( $pt ) . '_' . $row->meta_value;
				$this->uuid_map[ $key ] = (int) $row->ID;
			}
		}

		// Scan subscriptions for existing UUIDs.
		$sub_table = $wpdb->prefix . SkyHSHOSO_Subscription_DB::TABLE;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$subs = $wpdb->get_results(
			"SELECT id, uuid FROM {$sub_table} WHERE uuid IS NOT NULL AND uuid != ''"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $subs as $s ) {
			$this->uuid_map[ 'subscription_' . $s->uuid ] = (int) $s->id;
		}
	}

	/**
	 * Map a post type to a short entity key used in uuid_map.
	 *
	 * @param string $post_type
	 * @return string
	 */
	private function entity_type_key( $post_type ) {
		$map = array(
			'skyhshoso_server'  => 'server',
			'skyhshoso_hosting' => 'hosting',
			'skyhshoso_domain'  => 'domain',
			'skyhshoso_wp_site' => 'wp_site',
			'product'           => 'product',
			'shop_order'        => 'order',
		);
		return isset( $map[ $post_type ] ) ? $map[ $post_type ] : $post_type;
	}

	/**
	 * Get local ID from UUID map.
	 *
	 * @param string $entity_type E.g., 'server', 'product', 'subscription'.
	 * @param string $uuid
	 * @return int|null
	 */
	private function get_local_id( $entity_type, $uuid ) {
		if ( empty( $uuid ) ) {
			return null;
		}
		$key = $entity_type . '_' . $uuid;
		return isset( $this->uuid_map[ $key ] ) ? $this->uuid_map[ $key ] : null;
	}

	/**
	 * Store a UUID→ID mapping.
	 *
	 * @param string $entity_type
	 * @param string $uuid
	 * @param int    $local_id
	 */
	private function set_local_id( $entity_type, $uuid, $local_id ) {
		$this->uuid_map[ $entity_type . '_' . $uuid ] = (int) $local_id;
	}

	// -------------------------------------------------------------------------
	// Import: Users
	// -------------------------------------------------------------------------

	/**
	 * Import user records.
	 *
	 * Creates users if they don't exist (matched by email), updates display name
	 * and whitelisted meta on existing users. Runs first since all other entity
	 * types reference user IDs.
	 *
	 * @param array $items
	 */
	private function import_users( $items ) {
		foreach ( $items as $item ) {
			$email = $item['user_email'] ?? '';
			if ( empty( $email ) ) {
				$this->results['users']['errors'][] = 'User email missing.';
				continue;
			}

			$user = get_user_by( 'email', $email );

			if ( $user ) {
				$user_id = $user->ID;

				$update_args = array( 'ID' => $user_id );
				if ( ! empty( $item['display_name'] ) ) {
					$update_args['display_name'] = $item['display_name'];
				}
				if ( ! empty( $item['user_registered'] ) ) {
					$update_args['user_registered'] = $item['user_registered'];
				}
				if ( count( $update_args ) > 1 ) {
					wp_update_user( $update_args );
				}

				$this->results['users']['updated']++;
			} else {
				$user_login = $item['user_login'] ?? $email;
				if ( username_exists( $user_login ) ) {
					$user_login = $email;
				}

				$user_id = wp_insert_user( array(
					'user_login'      => $user_login,
					'user_email'      => $email,
					'display_name'    => $item['display_name'] ?? '',
					'user_pass'       => wp_generate_password(),
					'user_registered' => $item['user_registered'] ?? gmdate( 'Y-m-d H:i:s' ),
				) );

				if ( is_wp_error( $user_id ) ) {
					$this->results['users']['errors'][] = 'Failed to create user ' . $email . ': ' . $user_id->get_error_message();
					continue;
				}

				if ( ! empty( $item['roles'] ) && is_array( $item['roles'] ) ) {
					$user_obj = new WP_User( $user_id );
					foreach ( $item['roles'] as $role ) {
						$user_obj->add_role( $role );
					}
				}

				$this->results['users']['created']++;
			}

			if ( ! empty( $item['meta'] ) ) {
				foreach ( $item['meta'] as $key => $value ) {
					update_user_meta( $user_id, $key, $value );
				}
			}
		}
	}

	// -------------------------------------------------------------------------
	// Import: Servers
	// -------------------------------------------------------------------------

	/**
	 * Import server records.
	 *
	 * @param array $items
	 */
	private function import_servers( $items ) {
		foreach ( $items as $item ) {
			$uuid = $item['uuid'];

			if ( $this->get_local_id( 'server', $uuid ) ) {
				$this->results['servers']['skipped']++;
				continue;
			}

			// Check by UUID lookup directly.
			$existing = SkyHSHOSO_UUID::get_post_by_uuid( $uuid, array( 'skyhshoso_server' ) );
			if ( $existing ) {
				$this->set_local_id( 'server', $uuid, $existing->ID );
				$this->update_post_meta( $existing->ID, $item['meta'] );
				$this->results['servers']['updated']++;
				continue;
			}

			$post_id = wp_insert_post( array(
				'post_type'   => 'skyhshoso_server',
				'post_title'  => $item['title'] ?? '',
				'post_status' => 'publish',
			), true );

			if ( is_wp_error( $post_id ) ) {
				$this->results['servers']['errors'][] = 'Failed to create server "' . ( $item['title'] ?? $uuid ) . '": ' . $post_id->get_error_message();
				continue;
			}

			update_post_meta( $post_id, SkyHSHOSO_UUID::META_KEY, $uuid );
			$this->update_post_meta( $post_id, $item['meta'] );
			$this->set_local_id( 'server', $uuid, $post_id );
			$this->results['servers']['created']++;
		}
	}

	// -------------------------------------------------------------------------
	// Import: Products
	// -------------------------------------------------------------------------

	/**
	 * Import product records.
	 *
	 * @param array $items
	 */
	private function import_products( $items ) {
		foreach ( $items as $item ) {
			$uuid = $item['uuid'];

			if ( $this->get_local_id( 'product', $uuid ) ) {
				$this->results['products']['skipped']++;
				continue;
			}

			$existing = SkyHSHOSO_UUID::get_post_by_uuid( $uuid, array( 'product' ) );
			if ( $existing ) {
				$this->set_local_id( 'product', $uuid, $existing->ID );
				$this->update_post_meta( $existing->ID, $this->remap_meta( $item['meta'], 'product' ) );
				$this->maybe_set_product_type( $existing->ID, $item['type'] ?? 'simple' );
				$this->results['products']['updated']++;
				continue;
			}

			$post_id = wp_insert_post( array(
				'post_type'   => 'product',
				'post_title'  => $item['title'] ?? '',
				'post_status' => 'publish',
			), true );

			if ( is_wp_error( $post_id ) ) {
				$this->results['products']['errors'][] = 'Failed to create product "' . ( $item['title'] ?? $uuid ) . '": ' . $post_id->get_error_message();
				continue;
			}

			update_post_meta( $post_id, SkyHSHOSO_UUID::META_KEY, $uuid );
			$this->maybe_set_product_type( $post_id, $item['type'] ?? 'simple' );

			// Set WooCommerce price.
			if ( isset( $item['price'] ) && '' !== $item['price'] ) {
				update_post_meta( $post_id, '_price', $item['price'] );
				update_post_meta( $post_id, '_regular_price', $item['price'] );
			}
			if ( ! empty( $item['sku'] ) ) {
				update_post_meta( $post_id, '_sku', $item['sku'] );
			}
			update_post_meta( $post_id, '_visibility', 'visible' );
			update_post_meta( $post_id, '_skyhshoso_product_type', $item['skyhshoso_product_type'] ?? '' );

			$this->update_post_meta( $post_id, $this->remap_meta( $item['meta'], 'product' ) );

			// Import variations.
			if ( ! empty( $item['variations'] ) ) {
				foreach ( $item['variations'] as $vitem ) {
					$this->import_variation( $post_id, $vitem );
				}
			}

			$this->set_local_id( 'product', $uuid, $post_id );
			$this->results['products']['created']++;
		}
	}

	/**
	 * Import a product variation.
	 *
	 * @param int   $parent_id
	 * @param array $item
	 */
	private function import_variation( $parent_id, $item ) {
		$uuid = $item['uuid'] ?? '';

		if ( $uuid && $this->get_local_id( 'variation', $uuid ) ) {
			$this->results['products']['skipped']++;
			return;
		}

		if ( $uuid ) {
			$existing = SkyHSHOSO_UUID::get_post_by_uuid( $uuid, array( 'product' ) );
			if ( $existing ) {
				$this->set_local_id( 'variation', $uuid, $existing->ID );
				$this->update_post_meta( $existing->ID, $this->remap_meta( $item['meta'] ?? array(), 'product' ) );
				return;
			}
		}

		$vid = wp_insert_post( array(
			'post_type'   => 'product_variation',
			'post_parent' => $parent_id,
			'post_status' => 'publish',
		), true );

		if ( is_wp_error( $vid ) ) {
			$this->results['products']['errors'][] = 'Failed to create variation: ' . $vid->get_error_message();
			return;
		}

		if ( $uuid ) {
			update_post_meta( $vid, SkyHSHOSO_UUID::META_KEY, $uuid );
			$this->set_local_id( 'variation', $uuid, $vid );
		}

		if ( isset( $item['price'] ) && '' !== $item['price'] ) {
			update_post_meta( $vid, '_price', $item['price'] );
		}
		if ( ! empty( $item['sku'] ) ) {
			update_post_meta( $vid, '_sku', $item['sku'] );
		}

		$this->update_post_meta( $vid, $this->remap_meta( $item['meta'] ?? array(), 'product' ) );
	}

	/**
	 * Set the WooCommerce product type term.
	 *
	 * @param int    $product_id
	 * @param string $type
	 */
	private function maybe_set_product_type( $product_id, $type ) {
		if ( ! taxonomy_exists( 'product_type' ) ) {
			return;
		}
		$valid_types = array( 'simple', 'variable', 'grouped', 'external', 'subscription', 'variable-subscription' );
		if ( ! in_array( $type, $valid_types, true ) ) {
			$type = 'simple';
		}
		wp_set_object_terms( $product_id, $type, 'product_type' );
	}

	// -------------------------------------------------------------------------
	// Import: Orders
	// -------------------------------------------------------------------------

	/**
	 * Import order records.
	 *
	 * @param array $items
	 */
	private function import_orders( $items ) {
		if ( ! function_exists( 'wc_create_order' ) ) {
			$this->results['orders']['errors'][] = 'WooCommerce not available. Orders cannot be imported.';
			return;
		}

		foreach ( $items as $item ) {
			$uuid = $item['uuid'];

			if ( $this->get_local_id( 'order', $uuid ) ) {
				$this->results['orders']['skipped']++;
				continue;
			}

			$existing = SkyHSHOSO_UUID::get_order_by_uuid( $uuid );
			if ( $existing ) {
				$this->set_local_id( 'order', $uuid, $existing->get_id() );
				$this->results['orders']['updated']++;
				continue;
			}

			// Resolve customer.
			$customer_id = 0;
			if ( ! empty( $item['customer_email'] ) ) {
				$user = get_user_by( 'email', $item['customer_email'] );
				if ( $user ) {
					$customer_id = $user->ID;
				}
			}

			try {
				$order = wc_create_order( array(
					'customer_id' => $customer_id,
					'status'      => $item['status'] ?? 'pending',
					'currency'    => $item['currency'] ?? get_woocommerce_currency(),
				) );

				if ( ! empty( $item['total'] ) ) {
					$order->set_total( (float) $item['total'] );
				}
				if ( ! empty( $item['date_created'] ) ) {
					$order->set_date_created( $item['date_created'] );
				}

				$order->save();
				$order_id = $order->get_id();

				update_post_meta( $order_id, SkyHSHOSO_UUID::META_KEY, $uuid );

				// Remap meta, deferring subscription refs if needed.
				$meta = $this->remap_order_meta( $item['meta'] ?? array(), $uuid );
				$this->update_post_meta( $order_id, $meta );

				$this->set_local_id( 'order', $uuid, $order_id );
				$this->results['orders']['created']++;

			} catch ( Exception $e ) {
				$this->results['orders']['errors'][] = 'Failed to create order: ' . $e->getMessage();
			}
		}
	}

	/**
	 * Remap order meta, deferring subscription UUID references.
	 *
	 * @param array  $meta
	 * @param string $order_uuid
	 * @return array
	 */
	private function remap_order_meta( $meta, $order_uuid ) {
		if ( isset( $meta['_skyhshoso_renewal_subscription_id'] ) && ! empty( $meta['_skyhshoso_renewal_subscription_id'] ) ) {
			$sub_uuid = $meta['_skyhshoso_renewal_subscription_id'];
			$local_id = $this->get_local_id( 'subscription', $sub_uuid );
			if ( $local_id ) {
				$meta['_skyhshoso_renewal_subscription_id'] = $local_id;
			} else {
				// Defer — subscriptions not imported yet.
				$this->deferred_refs[] = array(
					'order_uuid' => $order_uuid,
					'meta_key'   => '_skyhshoso_renewal_subscription_id',
					'sub_uuid'   => $sub_uuid,
				);
			}
		}
		return $meta;
	}

	// -------------------------------------------------------------------------
	// Import: Subscriptions
	// -------------------------------------------------------------------------

	/**
	 * Import subscription records.
	 *
	 * @param array $items
	 */
	private function import_subscriptions( $items ) {
		global $wpdb;
		$table_name = $wpdb->prefix . SkyHSHOSO_Subscription_DB::TABLE;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$db_columns = $wpdb->get_col( "DESCRIBE {$table_name}" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( $items as $item ) {
			$uuid = $item['uuid'];

			// Resolve user by email.
			$user_id = 0;
			if ( ! empty( $item['user_id'] ) && is_string( $item['user_id'] ) ) {
				$user = get_user_by( 'email', $item['user_id'] );
				if ( $user ) {
					$user_id = $user->ID;
				}
			} elseif ( ! empty( $item['user_id'] ) && is_numeric( $item['user_id'] ) ) {
				$user_id = (int) $item['user_id'];
			}

			if ( ! $user_id ) {
				$this->results['subscriptions']['errors'][] = 'User not found for subscription ' . $uuid . ' (email: ' . ( $item['user_id'] ?? 'unknown' ) . ')';
				continue;
			}

			// Prepare columns dynamically.
			$sub_data = $item;
			unset( $sub_data['id'] );
			unset( $sub_data['meta'] );

			$sub_data['user_id']      = $user_id;
			$sub_data['product_id']   = $this->remap_uuid_field( $item['product_id'] ?? 0, 'product' );
			$sub_data['variation_id'] = $this->remap_uuid_field( $item['variation_id'] ?? 0, 'variation' );
			$sub_data['order_id']     = $this->remap_uuid_field( $item['order_id'] ?? 0, 'order' );
			$sub_data['uuid']         = $uuid;

			// Filter only valid columns present in DB to avoid query errors.
			if ( ! empty( $db_columns ) ) {
				$sub_data = array_intersect_key( $sub_data, array_flip( $db_columns ) );
			}

			$local_id = $this->get_local_id( 'subscription', $uuid );
			if ( $local_id ) {
				SkyHSHOSO_Subscription_DB::update( $local_id, $sub_data );
				$this->update_subscription_meta( $local_id, $item['meta'] ?? array() );
				$this->results['subscriptions']['skipped']++;
				continue;
			}

			$existing = SkyHSHOSO_UUID::get_subscription_by_uuid( $uuid );
			if ( $existing ) {
				$local_id = (int) $existing->id;
				$this->set_local_id( 'subscription', $uuid, $local_id );
				SkyHSHOSO_Subscription_DB::update( $local_id, $sub_data );
				$this->update_subscription_meta( $local_id, $item['meta'] ?? array() );
				$this->results['subscriptions']['updated']++;
				continue;
			}

			$sub_id = SkyHSHOSO_Subscription_DB::insert( $sub_data );

			if ( ! $sub_id ) {
				$this->results['subscriptions']['errors'][] = 'Failed to create subscription ' . $uuid;
				continue;
			}

			// Import meta.
			$this->update_subscription_meta( $sub_id, $item['meta'] ?? array() );

			$this->set_local_id( 'subscription', $uuid, $sub_id );
			$this->results['subscriptions']['created']++;
		}
	}

	/**
	 * Update subscription meta values.
	 *
	 * @param int   $sub_id
	 * @param array $meta
	 */
	private function update_subscription_meta( $sub_id, $meta ) {
		if ( empty( $meta ) ) {
			return;
		}
		foreach ( $meta as $key => $value ) {
			SkyHSHOSO_Subscription_DB::update_meta( $sub_id, $key, $value );
		}
	}

	// -------------------------------------------------------------------------
	// Import: Hosting
	// -------------------------------------------------------------------------

	/**
	 * Import hosting post records.
	 *
	 * @param array $items
	 */
	private function import_hosting( $items ) {
		foreach ( $items as $item ) {
			$uuid = $item['uuid'];

			if ( $this->get_local_id( 'hosting', $uuid ) ) {
				$this->results['hosting']['skipped']++;
				continue;
			}

			$existing = SkyHSHOSO_UUID::get_post_by_uuid( $uuid, array( 'skyhshoso_hosting' ) );
			if ( $existing ) {
				$this->set_local_id( 'hosting', $uuid, $existing->ID );
				$this->update_post_meta( $existing->ID, $this->remap_meta( $item['meta'], 'skyhshoso_hosting' ) );
				$this->results['hosting']['updated']++;
				continue;
			}

			$author_id = $this->resolve_author( $item['author_email'] ?? '' );

			$post_id = wp_insert_post( array(
				'post_type'   => 'skyhshoso_hosting',
				'post_title'  => $item['title'] ?? '',
				'post_author' => $author_id,
				'post_status' => 'publish',
			), true );

			if ( is_wp_error( $post_id ) ) {
				$this->results['hosting']['errors'][] = 'Failed to create hosting "' . ( $item['title'] ?? $uuid ) . '": ' . $post_id->get_error_message();
				continue;
			}

			update_post_meta( $post_id, SkyHSHOSO_UUID::META_KEY, $uuid );
			$this->update_post_meta( $post_id, $this->remap_meta( $item['meta'], 'skyhshoso_hosting' ) );
			$this->set_local_id( 'hosting', $uuid, $post_id );
			$this->results['hosting']['created']++;
		}
	}

	// -------------------------------------------------------------------------
	// Import: Domains
	// -------------------------------------------------------------------------

	/**
	 * Import domain post records.
	 *
	 * @param array $items
	 */
	private function import_domains( $items ) {
		foreach ( $items as $item ) {
			$uuid = $item['uuid'];

			if ( $this->get_local_id( 'domain', $uuid ) ) {
				$this->results['domains']['skipped']++;
				continue;
			}

			$existing = SkyHSHOSO_UUID::get_post_by_uuid( $uuid, array( 'skyhshoso_domain' ) );
			if ( $existing ) {
				$this->set_local_id( 'domain', $uuid, $existing->ID );
				$this->update_post_meta( $existing->ID, $this->remap_meta( $item['meta'], 'skyhshoso_domain' ) );
				$this->results['domains']['updated']++;
				continue;
			}

			$author_id = $this->resolve_author( $item['author_email'] ?? '' );

			$post_id = wp_insert_post( array(
				'post_type'   => 'skyhshoso_domain',
				'post_title'  => $item['title'] ?? '',
				'post_author' => $author_id,
				'post_status' => 'publish',
			), true );

			if ( is_wp_error( $post_id ) ) {
				$this->results['domains']['errors'][] = 'Failed to create domain "' . ( $item['title'] ?? $uuid ) . '": ' . $post_id->get_error_message();
				continue;
			}

			update_post_meta( $post_id, SkyHSHOSO_UUID::META_KEY, $uuid );
			$this->update_post_meta( $post_id, $this->remap_meta( $item['meta'], 'skyhshoso_domain' ) );
			$this->set_local_id( 'domain', $uuid, $post_id );
			$this->results['domains']['created']++;
		}
	}

	// -------------------------------------------------------------------------
	// Remapping Helpers
	// -------------------------------------------------------------------------

	/**
	 * Remap UUID meta values to local IDs for a given entity type.
	 *
	 * @param array  $meta       Raw meta from import.
	 * @param string $post_type  Post type for replacement key lookup.
	 * @return array Meta with UUIDs replaced by local IDs.
	 */
	private function remap_meta( $meta, $post_type ) {
		if ( ! isset( SkyHSHOSO_Export::REPLACEMENT_META_KEYS[ $post_type ] ) ) {
			return $meta ?: array();
		}

		$meta = $meta ?: array();
		foreach ( SkyHSHOSO_Export::REPLACEMENT_META_KEYS[ $post_type ] as $meta_key => $entity_type ) {
			if ( isset( $meta[ $meta_key ] ) && ! empty( $meta[ $meta_key ] ) ) {
				$meta[ $meta_key ] = $this->remap_uuid_field( $meta[ $meta_key ], $entity_type );
			}
		}
		return $meta;
	}

	/**
	 * If a value is a UUID, look up its local ID; otherwise return as-is.
	 *
	 * @param mixed  $value
	 * @param string $entity_type
	 * @return int|string
	 */
	private function remap_uuid_field( $value, $entity_type ) {
		if ( empty( $value ) ) {
			return 0;
		}

		$value_str = (string) $value;

		// Check if value is a UUID.
		if ( preg_match( self::UUID_PATTERN, $value_str ) ) {
			$local_id = $this->get_local_id( $entity_type, $value_str );
			return $local_id ? $local_id : 0;
		}

		// Already a numeric ID.
		return is_numeric( $value ) ? (int) $value : $value;
	}

	/**
	 * Resolve deferred references (subscription UUIDs on orders).
	 */
	private function resolve_deferred_references() {
		foreach ( $this->deferred_refs as $ref ) {
			$local_id = $this->get_local_id( 'subscription', $ref['sub_uuid'] );
			if ( ! $local_id ) {
				continue;
			}

			$order_id = $this->get_local_id( 'order', $ref['order_uuid'] );
			if ( $order_id ) {
				update_post_meta( $order_id, $ref['meta_key'], $local_id );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Utilities
	// -------------------------------------------------------------------------

	/**
	 * Resolve post author by email, falling back to current user.
	 *
	 * @param string $email
	 * @return int
	 */
	private function resolve_author( $email ) {
		if ( empty( $email ) ) {
			return get_current_user_id();
		}
		$user = get_user_by( 'email', $email );
		return $user ? $user->ID : get_current_user_id();
	}

	/**
	 * Update post meta from a key => value array.
	 *
	 * @param int   $post_id
	 * @param array $meta
	 */
	private function update_post_meta( $post_id, $meta ) {
		if ( empty( $meta ) ) {
			return;
		}
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
	}

	/**
	 * Import WordPress site post records.
	 *
	 * @param array $items
	 */
	private function import_wp_sites( $items ) {
		foreach ( $items as $item ) {
			$uuid = $item['uuid'];

			if ( $this->get_local_id( 'wp_site', $uuid ) ) {
				$this->results['wp_sites']['skipped']++;
				continue;
			}

			$existing = SkyHSHOSO_UUID::get_post_by_uuid( $uuid, array( 'skyhshoso_wp_site' ) );
			if ( $existing ) {
				$this->set_local_id( 'wp_site', $uuid, $existing->ID );
				$this->update_post_meta( $existing->ID, $this->remap_meta( $item['meta'], 'skyhshoso_wp_site' ) );
				$this->results['wp_sites']['updated']++;
				continue;
			}

			$author_id = $this->resolve_author( $item['author_email'] ?? '' );

			$post_id = wp_insert_post( array(
				'post_type'   => 'skyhshoso_wp_site',
				'post_title'  => $item['title'] ?? '',
				'post_author' => $author_id,
				'post_status' => 'publish',
			), true );

			if ( is_wp_error( $post_id ) ) {
				$this->results['wp_sites']['errors'][] = 'Failed to create WP site "' . ( $item['title'] ?? $uuid ) . '": ' . $post_id->get_error_message();
				continue;
			}

			update_post_meta( $post_id, SkyHSHOSO_UUID::META_KEY, $uuid );
			$this->update_post_meta( $post_id, $this->remap_meta( $item['meta'], 'skyhshoso_wp_site' ) );
			$this->set_local_id( 'wp_site', $uuid, $post_id );
			$this->results['wp_sites']['created']++;
		}
	}

	/**
	 * Import plugin global settings and options.
	 *
	 * @param array $settings
	 */
	private function import_settings( $settings ) {
		if ( empty( $settings ) ) {
			return;
		}

		try {
			// 1. General settings group
			if ( isset( $settings['skyhshoso_settings_group'] ) && is_array( $settings['skyhshoso_settings_group'] ) ) {
				$general = $settings['skyhshoso_settings_group'];
				if ( ! empty( $general['dashboard_page_slug'] ) ) {
					$page = get_page_by_path( $general['dashboard_page_slug'] );
					if ( $page ) {
						$general['dashboard_page'] = $page->ID;
					}
					unset( $general['dashboard_page_slug'] );
				}
				update_option( 'skyhshoso_settings_group', $general );
			}

			// 2. Enom settings
			if ( isset( $settings['enom'] ) && is_array( $settings['enom'] ) ) {
				foreach ( $settings['enom'] as $option_name => $option_val ) {
					update_option( $option_name, $option_val );
				}
			}

			// 3. Customize dashboard menu items
			if ( isset( $settings['skyhshoso_dashboard_menu_items'] ) ) {
				update_option( 'skyhshoso_dashboard_menu_items', $settings['skyhshoso_dashboard_menu_items'] );
			}

			// 4. Invoice dashboard page ID
			if ( ! empty( $settings['skyhshoso_dashboard_page_slug'] ) ) {
				$page = get_page_by_path( $settings['skyhshoso_dashboard_page_slug'] );
				if ( $page ) {
					update_option( 'skyhshoso_dashboard_page_id', $page->ID );
				}
			}

			// 5. Billing and Subscription settings
			if ( isset( $settings['billing_subscription'] ) && is_array( $settings['billing_subscription'] ) ) {
				foreach ( $settings['billing_subscription'] as $option_name => $option_val ) {
					update_option( $option_name, $option_val );
				}
			}

			$this->results['settings']['created']++;
		} catch ( Exception $e ) {
			$this->results['settings']['errors'][] = 'Failed to import settings: ' . $e->getMessage();
		}
	}
}
