<?php
if (! defined('ABSPATH')) exit;
trait sync_basalam_Check_Product_Sync_Status
{
    public static function sync_basalam_Check_Product_Sync_Status()
    {
        $status_sync = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::SYNC_STATUS_PRODUCT);
        if ($status_sync) {
            return true;
        }
        return false;
    }
}
