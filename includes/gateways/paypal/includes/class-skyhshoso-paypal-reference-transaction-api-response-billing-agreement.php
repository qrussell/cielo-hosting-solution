<?php
/**
 * SkyHS Hosting Solution PayPal Reference Transaction API Response Class for Express Checkout API calls to create a billing agreement
 *
 * @link https://developer.paypal.com/docs/classic/api/merchant/CreateBillingAgreement_API_Operation_NVP/
 *
 * @package     SkyHS Hosting Solution
 * @subpackage  Gateways/PayPal
 * @category    Class
 * @since       1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class SkyHSHOSO_PayPal_Reference_Transaction_API_Response_Billing_Agreement extends SkyHSHOSO_PayPal_Reference_Transaction_API_Response {


	/**
	 * Get the billing agreement ID which is returned after a successful CreateBillingAgreement API call
	 *
	 * @return string|null
	 * @since 1.0.0
	 */
	public function get_billing_agreement_id() {
		return $this->get_parameter( 'BILLINGAGREEMENTID' );
	}

}
