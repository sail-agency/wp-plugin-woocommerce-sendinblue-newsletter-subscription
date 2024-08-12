<?php

namespace SendinblueWoocommerce\Managers;

class SailExtensionsManager
{
    public static function getOrderTrackingContent($order)
    {
        $tracking_content = "Your order has been shipped!<br>You will receive another email with your tracking information soon.";

        try {
            if (class_exists('WC_Shipment_Tracking_Actions') && $order->get_status() === 'completed') {
                $shipmentTrackingInstance = new \WC_Shipment_Tracking_Actions();
                $tracking_items = $shipmentTrackingInstance->get_tracking_items($order->get_order_number(), true);

                $latest_tracking_item = array_pop($tracking_items);
                $tracking_content = "Your order has been shipped with {$latest_tracking_item['formatted_tracking_provider']} using tracking number <a href='{$latest_tracking_item['formatted_tracking_link']}' target='_blank'>{$latest_tracking_item['tracking_number']}</a>.<br>Please note that it can take up to <strong>24 hours</strong> until the tracking number is active.";
            }
        }
        catch (\Exception $e) {
            error_log($e->getMessage());
        }

        return $tracking_content;
    }

    public static function doesOrderContainOnlyVirtualProducts($order): bool
    {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product->is_virtual()) {
                return false;
            }
        }

        return true;
    }
}