<?php

namespace SyncBasalam\Admin\Pages;

use SyncBasalam\Admin\Settings\SettingsConfig;

defined('ABSPATH') || exit;

abstract class AdminPageAbstract
{
    public $checkToken;

    public function render()
    {
        if ($this->checkToken == true && !$this->checkBasalamAccess()) {
            require_once(syncBasalamPlugin()->templatePath() . "/admin/main/NotConnected.php");
            return;
        }

        $instance = new static();
        $instance->renderContent();
    }

    public function checkBasalamAccess()
    {
        $token = syncBasalamSettings()->getSettings(SettingsConfig::TOKEN);
        $refreshToken = syncBasalamSettings()->getSettings(SettingsConfig::REFRESH_TOKEN);
        if ($token && $refreshToken) return true;
        else return false;
    }

    abstract protected function renderContent();
}
