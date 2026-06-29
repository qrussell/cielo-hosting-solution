<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin payment retry email
 *
 * Email sent to admins when an attempt to automatically process a subscription renewal payment has failed
 * and a retry rule has been applied to retry the payment in the future.
 *
 * @class       SkyHSHOSO_Email_Payment_Retry
 * @version 2.1
 * @package SkyHS Hosting Solution/Includes/Emails
 * @extends WC_Email_Failed_Order
 */
class SkyHSHOSO_Email_Payment_Retry extends WC_Email_Failed_Order {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->id             = 'payment_retry';
		$this->title          = __( 'Payment Retry', 'skyhs-hosting-solution' );
		$this->description    = __( 'Payment retry emails are sent to chosen recipient(s) when an attempt to automatically process a subscription renewal payment has failed and a retry rule has been applied to retry the payment in the future.', 'skyhs-hosting-solution' );

		$this->heading        = __( 'Automatic renewal payment failed', 'skyhs-hosting-solution' );
		$this->subject        = __( '[{site_title}] Automatic payment failed for {order_number}, retry scheduled to run {retry_time}', 'skyhs-hosting-solution' );

		$this->template_html  = 'emails/admin-payment-retry.php';
		$this->template_plain = 'emails/plain/admin-payment-retry.php';
		$this->template_base  = SKYHSHOSO_PLUGIN_DIR . 'templates/';

		// We want all the parent's methods, with none of its properties, so call its parent's constructor, rather than my parent constructor
		WC_Email::__construct();

		// Other settings
		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
	}

	/**
	 * Get the default e-mail subject.
	 *
	 * @since 2.5.3
	 * @return string
	 */
	public function get_default_subject() {
		return $this->subject;
	}

	/**
	 * Get the default e-mail heading.
	 *
	 * @since 2.5.3
	 * @return string
	 */
	public function get_default_heading() {
		return $this->heading;
	}

	/**
	 * Trigger.
	 *
	 * @param int $order_id
	 */
	public function trigger( $order_id, $order = null ) {
		$this->object                  = $order;
		$this->retry                   = SkyHSHOSO_Retry_Manager::store()->get_last_retry_for_order( $order->get_id() );
		$this->find['order-date']      = '{order_date}';
		$this->find['order-number']    = '{order_number}';
		$this->find['retry-time']      = '{retry_time}';
		$this->replace['order-date']   = $this->object->get_date_created()->date_i18n( wc_date_format() );
		$this->replace['order-number'] = $this->object->get_order_number();
		$this->replace['retry-time']   = sprintf( __( 'in %s', 'skyhs-hosting-solution' ), human_time_diff( time(), $this->retry->get_time() ) );

		if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
			return;
		}

		$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
	}

	/**
	 * Get content html.
	 *
	 * @access public
	 * @return string
	 */
	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			array(
				'order'              => $this->object,
				'retry'              => $this->retry,
				'email_heading'      => $this->get_heading(),
				'additional_content' => is_callable( array( $this, 'get_additional_content' ) ) ? $this->get_additional_content() : '', // WC 3.7 introduced an additional content field for all emails.
				'sent_to_admin'      => true,
				'plain_text'         => false,
				'email'              => $this,
			),
			'',
			$this->template_base
		);
	}

	/**
	 * Get content plain.
	 *
	 * @return string
	 */
	public function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			array(
				'order'              => $this->object,
				'retry'              => $this->retry,
				'email_heading'      => $this->get_heading(),
				'additional_content' => is_callable( array( $this, 'get_additional_content' ) ) ? $this->get_additional_content() : '', // WC 3.7 introduced an additional content field for all emails.
				'sent_to_admin'      => true,
				'plain_text'         => true,
				'email'              => $this,
			),
			'',
			$this->template_base
		);
	}
}
