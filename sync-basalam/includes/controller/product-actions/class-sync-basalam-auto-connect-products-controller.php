<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_connect_products extends Sync_BasalamController
{
    public function __invoke()
    {
        sync_basalam_Product_Queue_Manager::sync_basalam_auto_connect_all_products();
        wp_send_json_success(['message' => 'فرایند اتصال اتوماتیک محصولات با موفقیت آغاز شد.'], 200);
    }
}
