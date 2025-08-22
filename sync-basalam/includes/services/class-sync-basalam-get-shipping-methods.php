<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Get_Shipping_Methods
{
    function get_woo_shipping_methods()
    {
        $zones = WC_Shipping_Zones::get_zones();
        $shipping_methods = [];
        foreach ($zones as $zone) {
            foreach ($zone['shipping_methods'] as $method) {
                $shipping_methods[] = [
                    'method_id' => $method->id,
                    'method_title' => $method->get_title(),
                    'enabled' => $method->enabled,
                ];
            }
        }
        return $shipping_methods;
    }
}
