<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Admin_Check_sync_basalam_Order
{
    public static function show_button_on_top_list($which)
    {
        if (! in_array(get_current_screen()->id, [
            'edit-shop_order',
            wc_get_page_screen_id('shop-order'),
        ])) {
            return;
        }

        if (! in_array($which, [
            'top',
            'shop_order',
        ])) {
            return;
        }
        sync_basalam_Admin_UI::render_check_sync_basalam_orders_button();
    }

}