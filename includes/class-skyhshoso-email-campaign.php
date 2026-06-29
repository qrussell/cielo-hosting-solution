<?php
/**
 * SkyHS Email Campaign Core
 *
 * Handles email campaign enqueueing on order completion, cron-based
 * batch queue processing, and campaign email sending with placeholder
 * replacement.
 *
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

class SkyHSHOSO_Email_Campaign {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_action( 'woocommerce_order_status_completed', array( $this, 'handle_order_complete' ), 20, 1 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'handle_order_complete' ), 20, 1 );

		add_action( 'skyhshoso_process_email_campaigns', array( $this, 'process_queue' ) );

		add_action( 'skyhshoso_cleanup_email_campaign_queue', array( $this, 'cleanup_queue' ) );
	}

	// -------------------------------------------------------------------------
	// Cron scheduling
	// -------------------------------------------------------------------------

	public static function schedule_events() {
		if ( ! wp_next_scheduled( 'skyhshoso_process_email_campaigns' ) ) {
			wp_schedule_event( time(), 'skyhshoso_5min', 'skyhshoso_process_email_campaigns' );
		}
		if ( ! wp_next_scheduled( 'skyhshoso_cleanup_email_campaign_queue' ) ) {
			wp_schedule_event( time(), 'daily', 'skyhshoso_cleanup_email_campaign_queue' );
		}
	}

	public static function unschedule_events() {
		$hooks = array( 'skyhshoso_process_email_campaigns', 'skyhshoso_cleanup_email_campaign_queue' );
		foreach ( $hooks as $hook ) {
			$ts = wp_next_scheduled( $hook );
			if ( $ts ) {
				wp_unschedule_event( $ts, $hook );
			}
		}
	}

	public static function add_cron_schedules( $schedules ) {
		if ( ! isset( $schedules['skyhshoso_5min'] ) ) {
			$schedules['skyhshoso_5min'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'SkyHS Every 5 Minutes', 'skyhs-hosting-solution' ),
			);
		}
		return $schedules;
	}

	// -------------------------------------------------------------------------
	// Order complete → enqueue matching campaigns
	// -------------------------------------------------------------------------

	public function handle_order_complete( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( $order->get_meta( '_skyhshoso_campaign_processed' ) ) {
			return;
		}

		$campaigns = SkyHSHOSO_Email_Campaign_DB::get_active_campaigns();
		if ( empty( $campaigns ) ) {
			return;
		}

		$order_products = $this->get_order_product_data( $order );
		if ( empty( $order_products ) ) {
			return;
		}

		$user_id   = (int) $order->get_customer_id();
		$user      = $user_id ? get_userdata( $user_id ) : null;
		$user_data = $user ? array(
			'id'           => $user->ID,
			'display_name' => $user->display_name,
			'first_name'   => $user->first_name,
			'last_name'    => $user->last_name,
			'email'        => $user->user_email,
		) : array();

		$enqueued = 0;

		foreach ( $campaigns as $campaign ) {
			$matching_products = $this->get_matching_products( $campaign, $order_products, $order );
			if ( empty( $matching_products ) ) {
				continue;
			}

			foreach ( $matching_products as $match ) {
				if ( SkyHSHOSO_Email_Campaign_DB::queue_entry_exists( (int) $campaign->id, (int) $order_id ) ) {
					continue;
				}

				$scheduled_at = gmdate( 'Y-m-d H:i:s' );

				if ( 'scheduled' === $campaign->trigger_type && $campaign->delay_value > 0 ) {
					$interval_str = '+' . (int) $campaign->delay_value . ' ' . $campaign->delay_unit;
					$scheduled_at = gmdate( 'Y-m-d H:i:s', strtotime( $interval_str ) );
				}

				$queue_data = array(
					'campaign_id'     => (int) $campaign->id,
					'subscription_id' => (int) $match['subscription_id'],
					'order_id'        => (int) $order_id,
					'user_id'         => $user_id,
					'product_id'      => (int) $match['product_id'],
					'status'          => 'pending',
					'scheduled_at'    => $scheduled_at,
				);

				$queue_id = SkyHSHOSO_Email_Campaign_DB::insert_queue( $queue_data );

				if ( $queue_id ) {
					$enqueued++;

					if ( class_exists( 'SkyHSHOSO_Activity_Log' ) ) {
						SkyHSHOSO_Activity_Log::log(
							'email_campaign',
							sprintf(
								'Campaign "%s" enqueued for order #%d (queue #%d, scheduled %s).',
								$campaign->name,
								$order_id,
								$queue_id,
								$scheduled_at
							),
							'info',
							0,
							$order_id,
							$user_id
						);
					}

				}
			}
		}

		if ( $enqueued > 0 ) {
			$order->update_meta_data( '_skyhshoso_campaign_processed', true );
			$order->save();
		}
	}

	// -------------------------------------------------------------------------
	// Product matching logic
	// -------------------------------------------------------------------------

	private function get_order_product_data( $order ) {
		$products = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}

			$product_id   = $item->get_product_id();
			$variation_id = $item->get_variation_id();
			$product      = wc_get_product( $variation_id ?: $product_id );

			if ( ! $product ) {
				continue;
			}

			$cat_ids = $variation_id
				? wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) )
				: wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );

			$subscription_id = 0;
			$subs = SkyHSHOSO_Subscription_DB::query( array(
				'order_id'    => $order->get_id(),
				'product_id'  => (int) $product_id,
				'limit'       => 1,
			) );
			if ( ! empty( $subs ) ) {
				$subscription_id = (int) $subs[0]->id;
			}

			$products[] = array(
				'product_id'      => (int) $product_id,
				'variation_id'    => (int) $variation_id,
				'category_ids'    => $cat_ids,
				'subscription_id' => $subscription_id,
				'product_name'    => $product->get_name(),
				'quantity'        => $item->get_quantity(),
			);
		}

		return $products;
	}

	private function get_matching_products( $campaign, $order_products, $order ) {
		$matches      = array();
		$target_type  = $campaign->target_type;
		$target_ids   = $campaign->target_ids ? json_decode( $campaign->target_ids, true ) : array();
		if ( ! is_array( $target_ids ) ) {
			$target_ids = array();
		}
		$target_ids = array_map( 'absint', $target_ids );

		foreach ( $order_products as $op ) {
			switch ( $target_type ) {
				case 'products':
					$parent_id = $op['variation_id'] ? $op['product_id'] : $op['product_id'];
					if ( in_array( $parent_id, $target_ids, true ) ) {
						$matches[] = $op;
					}
					break;

				case 'categories':
					$intersection = array_intersect( $op['category_ids'], $target_ids );
					if ( ! empty( $intersection ) ) {
						$matches[] = $op;
					}
					break;

				case 'manual':
					$order_user_id = (int) $order->get_customer_id();
					if ( in_array( $order_user_id, $target_ids, true ) ) {
						$matches[] = $op;
					}
					break;

				default:
					break;
			}
		}

		return $matches;
	}

	// -------------------------------------------------------------------------
	// Cron: process queue in batches
	// -------------------------------------------------------------------------

	public function process_queue() {
		$lock_key = 'skyhshoso_email_campaign_lock';
		$lock     = get_transient( $lock_key );

		if ( $lock ) {
			return;
		}

		set_transient( $lock_key, true, 4 * MINUTE_IN_SECONDS );

		$pending = SkyHSHOSO_Email_Campaign_DB::get_pending_queue( 60 );

		if ( empty( $pending ) ) {
			delete_transient( $lock_key );
			return;
		}

		$sent   = 0;
		$failed = 0;

		foreach ( $pending as $entry ) {
			$campaign = SkyHSHOSO_Email_Campaign_DB::get_campaign( (int) $entry->campaign_id );

			if ( ! $campaign || ! $campaign->is_active ) {
				SkyHSHOSO_Email_Campaign_DB::update_queue( (int) $entry->id, array(
					'status'         => 'skipped',
					'status_message' => __( 'Campaign deleted or inactive.', 'skyhs-hosting-solution' ),
				) );
				$failed++;
				continue;
			}

			$result = $this->send_campaign_email( $campaign, $entry );

			if ( $result ) {
				SkyHSHOSO_Email_Campaign_DB::update_queue( (int) $entry->id, array(
					'status'  => 'sent',
					'sent_at' => gmdate( 'Y-m-d H:i:s' ),
				) );
				$sent++;
			} else {
				SkyHSHOSO_Email_Campaign_DB::update_queue( (int) $entry->id, array(
					'status'         => 'failed',
					'status_message' => __( 'Email failed to send.', 'skyhs-hosting-solution' ),
				) );
				$failed++;
			}
		}

		if ( class_exists( 'SkyHSHOSO_Activity_Log' ) ) {
			SkyHSHOSO_Activity_Log::log(
				'email_campaign',
				sprintf( 'Process queue batch: %d sent, %d failed/skipped.', $sent, $failed ),
				'success'
			);
		}

		delete_transient( $lock_key );
	}

	// -------------------------------------------------------------------------
	// Send a single campaign email
	// -------------------------------------------------------------------------

	public function send_campaign_email( $campaign, $queue_entry ) {
		$user = get_userdata( (int) $queue_entry->user_id );
		if ( ! $user || empty( $user->user_email ) ) {
			return false;
		}

		$placeholders = $this->build_placeholders( $queue_entry, $user );

		$subject = str_replace(
			array_keys( $placeholders ),
			array_values( $placeholders ),
			$campaign->subject
		);

		$body = str_replace(
			array_keys( $placeholders ),
			array_values( $placeholders ),
			$campaign->body
		);

		$body = $this->wrap_email_html( $body );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$sent    = wp_mail( $user->user_email, $subject, $body, $headers );

		if ( class_exists( 'SkyHSHOSO_Activity_Log' ) ) {
			SkyHSHOSO_Activity_Log::log(
				'email_campaign',
				sprintf(
					'Campaign "%s" email %s to %s (queue #%d, order #%d).',
					$campaign->name,
					$sent ? 'sent' : 'FAILED',
					$user->user_email,
					$queue_entry->id,
					$queue_entry->order_id
				),
				$sent ? 'success' : 'error',
				0,
				$queue_entry->order_id,
				$user->ID
			);
		}

		return $sent;
	}

	// -------------------------------------------------------------------------
	// Placeholder replacement
	// -------------------------------------------------------------------------

	private function build_placeholders( $queue_entry, $user ) {
		$order = wc_get_order( (int) $queue_entry->order_id );

		$product_name    = '';
		$variation_name  = '';
		$product_quantity = '1';
		$order_total     = '';

		if ( $order ) {
			$order_total = wp_strip_all_tags( $order->get_formatted_order_total() );

			foreach ( $order->get_items() as $item ) {
				if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
					continue;
				}
				$pid = $item->get_product_id();
				$vid = $item->get_variation_id();
				if ( ( $vid && (int) $vid === (int) $queue_entry->product_id ) || (int) $pid === (int) $queue_entry->product_id ) {
					$product_name     = $item->get_name();
					$product_quantity  = $item->get_quantity();
					if ( $vid ) {
						$variation = wc_get_product( $vid );
						$variation_name = $variation ? $variation->get_name() : $product_name;
					} else {
						$variation_name = $product_name;
					}
					break;
				}
			}
		}

		$billing_address = '';
		if ( $order ) {
			$billing_address = $order->get_formatted_billing_address();
		}

		$order_date = '';
		if ( $order && $order->get_date_created() ) {
			$order_date = date_i18n( get_option( 'date_format' ), $order->get_date_created()->getTimestamp() );
		}

		return array(
			'{{customer_name}}'        => esc_html( $user->display_name ),
			'{{customer_first_name}}'  => esc_html( $user->first_name ),
			'{{customer_last_name}}'   => esc_html( $user->last_name ),
			'{{customer_email}}'       => esc_html( $user->user_email ),
			'{{product_name}}'         => esc_html( $product_name ),
			'{{variation_name}}'       => esc_html( $variation_name ),
			'{{product_quantity}}'     => esc_html( $product_quantity ),
			'{{order_id}}'             => esc_html( $queue_entry->order_id ),
			'{{order_date}}'           => esc_html( $order_date ),
			'{{order_total}}'          => wp_kses_post( $order_total ),
			'{{billing_address}}'      => wp_kses_post( nl2br( $billing_address ) ),
			'{{site_name}}'            => esc_html( get_bloginfo( 'name' ) ),
			'{{site_url}}'             => esc_url( home_url() ),
		);
	}

	private function wrap_email_html( $body ) {
		if ( false !== strpos( $body, '<html' ) || false !== strpos( $body, '<body' ) ) {
			return $body;
		}

		$html = '<html><body style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;padding:20px;color:#1d2327;">';
		$html .= '<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">';
		$html .= '<div style="padding:30px;">';
		$html .= nl2br( $body );
		$html .= '</div></div></body></html>';

		return $html;
	}

	public function cleanup_queue() {
		SkyHSHOSO_Email_Campaign_DB::cleanup_completed_queue( 30 );

		if ( class_exists( 'SkyHSHOSO_Activity_Log' ) ) {
			SkyHSHOSO_Activity_Log::log(
				'email_campaign',
				'Cleaned up completed/failed queue entries older than 30 days.',
				'info'
			);
		}
	}
}
