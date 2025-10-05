<?php
if (! defined('ABSPATH')) exit;

class SyncBasalamAutoConfirmOrders extends Sync_BasalamController
{
    public function __invoke()
    {
        $auto_confirm_status = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::AUTO_CONFIRM_ORDER);
        $auto_confirm_status = !$auto_confirm_status;
        $auto_confrim_orders_service = new SyncBasalamPostAutoConfirmOrder();
        $result = $auto_confrim_orders_service->post_auto_confirm_order($auto_confirm_status);

        if ($result['success']) {
            $data = [
                sync_basalam_Admin_Settings::AUTO_CONFIRM_ORDER => $auto_confirm_status,
            ];
            sync_basalam_Admin_Settings::update_settings($data);
        }else{
            Sync_basalam_Logger::error("خطا در فعالسازی تایید خودکار سفارشات: " . $result['message']);
        }
    }
}
