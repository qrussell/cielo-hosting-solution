<?php
/**
 * Manage the emails sent as part of the retry process
 *
 * @package     SkyHS Hosting Solution
 * @subpackage  SkyHSHOSO_Retry_Email
 * @category    Class
 * @since       2.1
 */

class SkyHSHOSO_Retry_Email {

	/* a property to cache the order ID when detaching/reattaching default emails in favour of retry emails */
	protected static $removed_emails_for_order_id;

	/**
	 * Attach callbacks and set the retry rules
	 *
	 * @since 2.1
	 */
	public static function init() {

		add_action( 'woocommerce_email_classes', __CLASS__ . '::add_emails', 12, 1 );

		add_action( 'skyhshoso_after_apply_retry_rule', __CLASS__ . '::send_email', 0, 2 );

		add_action( 'woocommerce_order_status_failed', __CLASS__ . '::maybe_detach_email', 9 );

		add_action( 'woocommerce_order_status_changed', __CLASS__ . '::maybe_reattach_email', 100, 3 );
	}

	/**
	 * Add default retry email classes to the available WooCommerce emails
	 *
	 * @since 2.1
	 */
	public static function add_emails( $email_classes ) {
		$email_classes['SkyHSHOSO_Email_Customer_Payment_Retry'] = new SkyHSHOSO_Email_Customer_Payment_Retry();
		$email_classes['SkyHSHOSO_Email_Payment_Retry']          = new SkyHSHOSO_Email_Payment_Retry();

		return $email_classes;
	}

	/**
	 * After a retry rule has been applied, send relevant emails for that rule.
	 *
	 * Attached to 'skyhshoso_after_apply_retry_rule' with a low priority.
	 *
	 * @param SkyHSHOSO_Retry_Rule The retry rule applied.
	 * @param WC_Order The order to which the retry rule was applied.
	 * @since 2.1
	 */
	public static function send_email( $retry_rule, $last_order ) {
		WC()->mailer();

		// maybe send emails about the renewal payment failure
		foreach ( array( 'customer', 'admin' ) as $recipient ) {
			if ( $retry_rule->has_email_template( $recipient ) ) {
				$email_class = $retry_rule->get_email_template( $recipient );
				if ( class_exists( $email_class ) ) {
					$email = new $email_class();
					$email->trigger( $last_order->get_id(), $last_order );
				}
			}
		}
	}

	/**
	 * Don't send the default failed order email when a payment fails if there are
	 * retry rules to apply, as the retry rules define which emails to send instead.
	 *
	 * @since 2.1
	 */
	public static function maybe_detach_email( $order_id ) {

		// We only want to detach the email if there is a retry
		if ( get_post_meta( $order_id, '_skyhshoso_renewal_subscription_id', true ) && SkyHSHOSO_Retry_Manager::rules()->has_rule( SkyHSHOSO_Retry_Manager::store()->get_retry_count_for_order( $order_id ), $order_id ) ) {

			$mailer = WC()->mailer();

			// Detach the WooCommerce failed order email from the status transition hooks
			// so that the retry email is sent instead.
			if ( isset( $mailer->emails['WC_Email_Failed_Order'] ) ) {
				$failed_order_email = $mailer->emails['WC_Email_Failed_Order'];
				remove_action( 'woocommerce_order_status_pending_to_failed', array( $failed_order_email, 'trigger' ), 10 );
				remove_action( 'woocommerce_order_status_on-hold_to_failed', array( $failed_order_email, 'trigger' ), 10 );
			}

			self::$removed_emails_for_order_id = $order_id;
		}
	}

	/**
	 * Check if we removed emails for a given order, and if we did, reattach them.
	 *
	 * @since 2.1
	 */
	public static function maybe_reattach_email( $order_id, $old_status, $new_status ) {

		if ( 'failed' === $new_status && $order_id == self::$removed_emails_for_order_id ) {

			$mailer = WC()->mailer();

			if ( isset( $mailer->emails['WC_Email_Failed_Order'] ) ) {
				$failed_order_email = $mailer->emails['WC_Email_Failed_Order'];
				add_action( 'woocommerce_order_status_pending_to_failed', array( $failed_order_email, 'trigger' ), 10, 2 );
				add_action( 'woocommerce_order_status_on-hold_to_failed', array( $failed_order_email, 'trigger' ), 10, 2 );
			}

			self::$removed_emails_for_order_id = null;
		}
	}
}
