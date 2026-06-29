<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class SkyHSHOSO_Auto_Complete_Virtual_Orders {
    public function __construct() {
        add_action('woocommerce_order_status_processing', array($this, 'auto_complete_virtual_orders'), 10, 1);
    }

    public function auto_complete_virtual_orders($order_id) {
        if (!$order_id) {
            return;
        }

        $order = wc_get_order($order_id);

        if ($order->has_status('processing') && $this->is_order_virtual($order)) {
            $order->update_status('completed', __('Order automatically completed as it contains only virtual products.', 'skyhs-hosting-solution'));
        }
    }

    private function is_order_virtual($order) {
        $virtual = true;

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product->is_virtual()) {
                $virtual = false;
                break;
            }
        }

        return $virtual;
    }
}

new SkyHSHOSO_Auto_Complete_Virtual_Orders();