<?php
/**
 * Store retry details in the WordPress posts table as a custom post type
 *
 * @package     SkyHS Hosting Solution
 * @subpackage  SkyHSHOSO_Retry_Store
 * @category    Class
 * @since       2.1
 */

class SkyHSHOSO_Retry_Post_Store extends SkyHSHOSO_Retry_Store {

	protected static $post_type = 'payment_retry';

	/**
	 * Setup the class, if required
	 *
	 * @return null
	 */
	public function init() {
		register_post_type(
			self::$post_type,
			array(
				'description'  => __( 'Payment retry posts store details about the automatic retry of failed renewal payments.', 'skyhs-hosting-solution' ),
				'public'       => false,
				'map_meta_cap' => true,
				'hierarchical' => false,
				'supports'     => array( 'title', 'editor', 'comments' ),
				'rewrite'      => false,
				'query_var'    => false,
				'can_export'   => true,
				'ep_mask'      => EP_NONE,
				'labels'       => array(
					'name'               => _x( 'Renewal Payment Retries', 'Post type name', 'skyhs-hosting-solution' ),
					'singular_name'      => __( 'Renewal Payment Retry', 'skyhs-hosting-solution' ),
					'menu_name'          => _x( 'Renewal Payment Retries', 'Admin menu name', 'skyhs-hosting-solution' ),
					'add_new'            => __( 'Add', 'skyhs-hosting-solution' ),
					'add_new_item'       => __( 'Add New Retry', 'skyhs-hosting-solution' ),
					'edit'               => __( 'Edit', 'skyhs-hosting-solution' ),
					'edit_item'          => __( 'Edit Retry', 'skyhs-hosting-solution' ),
					'new_item'           => __( 'New Retry', 'skyhs-hosting-solution' ),
					'view'               => __( 'View Retry', 'skyhs-hosting-solution' ),
					'view_item'          => __( 'View Retry', 'skyhs-hosting-solution' ),
					'search_items'       => __( 'Search Renewal Payment Retries', 'skyhs-hosting-solution' ),
					'not_found'          => __( 'No retries found', 'skyhs-hosting-solution' ),
					'not_found_in_trash' => __( 'No retries found in trash', 'skyhs-hosting-solution' ),
				),
			)
		);
	}

	/**
	 * Save the details of a retry to the database
	 *
	 * @param SkyHSHOSO_Retry $retry
	 * @return int the retry's ID
	 */
	public function save( SkyHSHOSO_Retry $retry ) {

		$post_id = wp_insert_post( array(
			'ID'            => $retry->get_id(),
			'post_type'     => self::$post_type,
			'post_status'   => $retry->get_status(),
			'post_parent'   => $retry->get_order_id(),
			'post_date'     => $retry->get_date(),
			'post_date_gmt' => $retry->get_date_gmt(),
		) );

		// keep a record of the rule in post meta
		foreach ( $retry->get_rule()->get_raw_data() as $rule_key => $rule_value ) {
			update_post_meta( $post_id, '_rule_' . $rule_key, $rule_value );
		}

		return $post_id;
	}

	/**
	 * Get the details of a retry from the database
	 *
	 * @param int $retry_id
	 * @return SkyHSHOSO_Retry
	 */
	public function get_retry( $retry_id ) {

		$retry_post = get_post( $retry_id );

		if ( null !== $retry_post && $retry_post->post_type === self::$post_type ) {

			$rule_data = array();
			$post_meta = get_post_meta( $retry_id );

			foreach ( $post_meta as $meta_key => $meta_value ) {
				if ( 0 === strpos( $meta_key, '_rule_' ) ) {
					$rule_data[ substr( $meta_key, 6 ) ] = $meta_value[0];
				}
			}

			$retry = new SkyHSHOSO_Retry( array(
				'id'       => $retry_post->ID,
				'status'   => $retry_post->post_status,
				'order_id' => $retry_post->post_parent,
				'date_gmt' => $retry_post->post_date_gmt,
				'rule_raw' => $rule_data,
			) );
		} else {
			$retry = null;
		}

		return $retry;
	}

	/**
	 * Deletes a retry.
	 *
	 * @param int $retry_id
	 *
	 * @return bool
	 */
	public function delete_retry( $retry_id ) {
		return wp_delete_post( $retry_id, true );
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
		$retries = array();

		$args = wp_parse_args( $args, array(
			'status'     => 'any',
			'date_query' => array(),
			'orderby'    => 'date',
			'order'      => 'DESC',
			'order_id'   => false,
			'limit'      => -1,
		) );

		$retry_post_ids = get_posts( array(
			'posts_per_page' => $args['limit'],
			'post_type'      => self::$post_type,
			'post_status'    => $args['status'],
			'date_query'     => $args['date_query'],
			'fields'         => 'ids',
			'orderby'        => $args['orderby'],
			'order'          => $args['order'],
			'post_parent'    => $args['order_id'],
		) );

		foreach ( $retry_post_ids as $retry_post_id ) {
			$retries[ $retry_post_id ] = 'ids' === $return ? $retry_post_id : $this->get_retry( $retry_post_id );
		}

		return $retries;
	}
}
