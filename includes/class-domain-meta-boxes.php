<?php

defined( 'ABSPATH' ) || exit;

class SkyHSHOSO_Domain_Meta_Boxes {
    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action( 'before_delete_post', array( $this, 'delete_associated_subscription' ), 10, 1 );
    }

    public function delete_associated_subscription( $post_id ) {
        $post_type = get_post_type( $post_id );

        if ( 'skyhshoso_domain' !== $post_type ) {
            return;
        }

        $skyhshoso_subscription_id = get_post_meta( $post_id, 'skyhshoso_subscription_id', true );

        if ( ! empty( $skyhshoso_subscription_id ) ) {
            $subscription = skyhshoso_get_subscription( $skyhshoso_subscription_id );

            if ( $subscription ) {
                $subscription->update_status( 'cancelled' );
                wp_trash_post( $skyhshoso_subscription_id );
            }
        }
    }

    public function process_subscription_creation( $post_id ) {
		try {
			$post = get_post( $post_id );
			if ( !$post || 'skyhshoso_domain' !== $post->post_type ) {
				throw new Exception( "Invalid post #$post_id" );
			}

			$skyhshoso_subscription_id = get_post_meta( $post_id, 'skyhshoso_subscription_id', true );
			if ( !empty( $skyhshoso_subscription_id ) ) {
				delete_post_meta( $post_id, '_skyhshoso_subscription_creation_pending' );
				return true;
			}

			$product_id = get_post_meta( $post_id, '_skyhshoso_domain_product_id', true );
			if ( empty( $product_id ) ) {
				throw new Exception( "No product ID found for domain #$post_id" );
			}

			$product = wc_get_product( $product_id );
			if ( !$product ) {
				throw new Exception( "Product #$product_id not found for domain #$post_id" );
			}

			$user_id = $post->post_author;
			$user = get_user_by( 'id', $user_id );
			if ( !$user ) {
				throw new Exception( "User #$user_id not found for domain #$post_id" );
			}

			$skyhshoso_subscription_id = $this->create_subscription( $user, $product, $post );

			if ( !$skyhshoso_subscription_id ) {
				throw new Exception( "Failed to create subscription for domain #$post_id" );
			}

			update_post_meta( $post_id, 'skyhshoso_subscription_id', $skyhshoso_subscription_id );
			delete_post_meta( $post_id, '_skyhshoso_subscription_creation_pending' );

			return true;

		} catch ( Exception $e ) {
			update_post_meta( $post_id, '_skyhshoso_subscription_creation_error', $e->getMessage() );
			SkyHSHOSO_Logger::error( 'Domain subscription creation failed for post #' . $post_id . ': ' . $e->getMessage(), array( 'source' => 'domain_meta_boxes' ) );
			return false;
		}
    }

    private function create_subscription( $user, $product, $post ) {
        try {
            $post_id = $post->ID;

            $subscription = skyhshoso_create_subscription( array(
                'customer_id'      => $user->ID,
                'billing_period'   => 'year',
                'billing_interval' => 1,
                'start_date'       => gmdate( 'Y-m-d H:i:s' ),
            ) );

            if ( is_wp_error( $subscription ) ) {
                throw new Exception( $subscription->get_error_message() );
            }

            $subscription->add_product( $product );

            $address_fields = array(
                'first_name', 'last_name', 'company', 'address_1',
                'address_2', 'city', 'state', 'postcode', 'country',
                'email', 'phone'
            );

            foreach ( $address_fields as $field ) {
                $billing_field = 'billing_' . $field;
                $value = get_user_meta( $user->ID, $billing_field, true );
                if ( !empty( $value ) ) {
                    $subscription->update_meta_data( '_' . $billing_field, $value );
                }
            }

            $subscription->calculate_totals();
            $subscription->update_status( 'active' );
            $subscription->save();

            return $subscription->get_id();

		} catch ( Exception $e ) {
			update_post_meta( $post->ID, '_skyhshoso_subscription_creation_error', $e->getMessage() );
			SkyHSHOSO_Logger::error( 'Domain subscription inner creation failed for post #' . $post->ID . ': ' . $e->getMessage(), array( 'source' => 'domain_meta_boxes' ) );
			return false;
		}
    }
}

function SkyHSHOSO_Domain_Meta_Boxes() {
    return SkyHSHOSO_Domain_Meta_Boxes::instance();
}

SkyHSHOSO_Domain_Meta_Boxes();
