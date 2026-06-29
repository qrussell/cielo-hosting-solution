<?php
/**
 * Line Item Pending Switch
 *
 * Line items added to a subscription to record a switch use this item type before transitioning.
 *
 * @package SkyHS Hosting Solution
 */

class SkyHSHOSO_Order_Item_Pending_Switch extends WC_Order_Item_Product {

	public function get_type() {
		return 'line_item_pending_switch';
	}
}
