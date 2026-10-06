<?php

namespace SyncBasalam\Registrar\ProductListeners;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Admin\Settings\SettingsManager;

defined('ABSPATH') || exit;

trait ProductStatusTrait
{
    public static function isProductSyncEnabled()
    {
        $status = syncBasalamSettings()->getSettings(SettingsConfig::SYNC_STATUS_PRODUCT);

        return (bool) $status;
    }

    public static function isProductCreationSyncEnabled(): bool
    {
        return SettingsManager::isProductCreationSyncEnabled();
    }

    public static function isProductUpdateSyncEnabled(): bool
    {
        return SettingsManager::isProductUpdateSyncEnabled();
    }
}
