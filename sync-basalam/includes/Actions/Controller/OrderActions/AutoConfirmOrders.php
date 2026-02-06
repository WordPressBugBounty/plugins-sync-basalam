<?php

namespace SyncBasalam\Actions\Controller\OrderActions;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Admin\Settings;
use SyncBasalam\Logger\Logger;
use SyncBasalam\Services\Orders\PostAutoConfirmOrder;
use SyncBasalam\Actions\Controller\ActionController;

defined('ABSPATH') || exit;

class AutoConfirmOrders extends ActionController
{
    public function __invoke()
    {
        $autoConfirmStatus = syncBasalamSettings()->getSettings(SettingsConfig::AUTO_CONFIRM_ORDER);
        $autoConfirmStatus = !$autoConfirmStatus;
        $autoConfrimOrdersService = new PostAutoConfirmOrder();
        $result = $autoConfrimOrdersService->postAutoConfirmOrder($autoConfirmStatus);

        if ($result['success']) {
            $data = [
                SettingsConfig::AUTO_CONFIRM_ORDER => $autoConfirmStatus,
            ];
            Settings::updateSettings($data);
        } else {
            Logger::error("خطا در فعالسازی تایید خودکار سفارشات: " . $result['message']);
        }
    }
}
