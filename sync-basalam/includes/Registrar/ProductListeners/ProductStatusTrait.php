<?php

namespace SyncBasalam\Registrar\ProductListeners;

use SyncBasalam\Admin\Settings\SettingsConfig;

defined('ABSPATH') || exit;

trait ProductStatusTrait
{
    public static function isProductSyncEnabled()
    {
        $status = syncBasalamSettings()->getSettings(SettingsConfig::SYNC_STATUS_PRODUCT);

        return (bool) $status;
    }
}
