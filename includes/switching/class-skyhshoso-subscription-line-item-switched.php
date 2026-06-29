<?php
/**
 * Subscription Line Item (product) Switched
 *
 * Line items which have been switched by a customer have the line_item_switched line item type. This class extends WC_Order_Item_Product to implement this line item type.
 *
 * @category Class
 * @package  SkyHS Hosting Solution
 * @since    1.0.0
 */

class SkyHSHOSO_Subscription_Line_Item_Switched extends WC_Order_Item_Product {

	/**
	 * Get item type.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_type() {
		return 'line_item_switched';
	}
}
