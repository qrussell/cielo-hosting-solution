<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
/**
 * Customer Retry
 *
 * Email sent to the customer when an attempt to automatically process a subscription renewal payment has failed
 * and a retry rule has been applied to retry the payment in the future.
 *
 * @version 2.1
 * @package SkyHS Hosting Solution/Includes/Emails
 * @extends WC_Email
 */
class SkyHSHOSO_Email_Customer_Payment_Retry extends WC_Email {

	/**
	 * Constructor
	 */
	function __construct() {

		$this->id             = 'customer_payment_retry';
		$this->title          = __( 'Customer Payment Retry', 'skyhs-hosting-solution' );
		$this->description    = __( 'Sent to a customer when an attempt to automatically process a subscription renewal payment has failed and a retry rule has been applied to retry the payment in the future. The email contains the renewal order information, date of the scheduled retry and payment links to allow the customer to pay for the renewal order manually instead of waiting for the automatic retry.', 'skyhs-hosting-solution' );
		$this->customer_email = true;

		$this->template_html  = 'emails/customer-payment-retry.php';
		$this->template_plain = 'emails/plain/customer-payment-retry.php';
		$this->template_base  = SKYHSHOSO_PLUGIN_DIR . 'templates/';

		$this->subject        = __( 'Automatic payment failed for {order_number}, we will retry {retry_time}', 'skyhs-hosting-solution' );
		$this->heading        = __( 'Automatic payment failed for order {order_number}', 'skyhs-hosting-solution' );

		WC_Email::__construct();
	}

	/**
	 * Get the default e-mail subject.
	 *
	 * @param bool $paid Whether the order has been paid or not.
	 * @since 2.5.3
	 * @return string
	 */
	public function get_default_subject( $paid = false ) {
		return $this->subject;
	}

	/**
	 * Get the default e-mail heading.
	 *
	 * @param bool $paid Whether the order has been paid or not.
	 * @since 2.5.3
	 * @return string
	 */
	public function get_default_heading( $paid = false ) {
		return $this->heading;
	}

	/**
	 * trigger function.
	 *
	 * @access public
	 * @param int    $order_id
	 * @param object $order
	 * @return void
	 */
	function trigger( $order_id, $order = null ) {

		$this->object = $order;
		$this->retry  = SkyHSHOSO_Retry_Manager::store()->get_last_retry_for_order( $order_id );

		$this->find['order-date']      = '{order_date}';
		$this->find['order-number']    = '{order_number}';
		$this->find['retry_time']      = '{retry_time}';
		$this->replace['order-date']   = $this->object ? $this->object->get_date_created()->date_i18n( wc_date_format() ) : '';
		$this->replace['order-number'] = $this->object ? $this->object->get_order_number() : '';
		$this->replace['retry_time']   = $this->retry ? sprintf( __( 'in %s', 'skyhs-hosting-solution' ), human_time_diff( time(), $this->retry->get_time() ) ) : '';

		if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
			return;
		}

		$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
	}

	/**
	 * get_subject function.
	 *
	 * @access public
	 * @return string
	 */
	function get_subject() {
		return apply_filters( 'skyhshoso_email_subject_customer_retry', parent::get_subject(), $this->object );
	}

	/**
	 * get_heading function.
	 *
	 * @access public
	 * @return string
	 */
	function get_heading() {
		return apply_filters( 'skyhshoso_email_heading_customer_retry', parent::get_heading(), $this->object );
	}

	/**
	 * get_content_html function.
	 *
	 * @access public
	 * @return string
	 */
	function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			array(
				'order'              => $this->object,
				'retry'              => $this->retry,
				'email_heading'      => $this->get_heading(),
				'additional_content' => is_callable( array( $this, 'get_additional_content' ) ) ? $this->get_additional_content() : '', // WC 3.7 introduced an additional content field for all emails.
				'sent_to_admin'      => false,
				'plain_text'         => false,
				'email'              => $this,
			),
			'',
			$this->template_base
		);
	}

	/**
	 * get_content_plain function.
	 *
	 * @access public
	 * @return string
	 */
	function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			array(
				'order'              => $this->object,
				'retry'              => $this->retry,
				'email_heading'      => $this->get_heading(),
				'additional_content' => is_callable( array( $this, 'get_additional_content' ) ) ? $this->get_additional_content() : '', // WC 3.7 introduced an additional content field for all emails.
				'sent_to_admin'      => false,
				'plain_text'         => true,
				'email'              => $this,
			),
			'',
			$this->template_base
		);
	}
}
