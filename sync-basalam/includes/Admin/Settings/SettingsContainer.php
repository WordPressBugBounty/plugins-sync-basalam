<?php

namespace SyncBasalam\Admin\Settings;

use SyncBasalam\Admin\Settings;
use SyncBasalam\Admin\Settings\SettingsConfig;

defined('ABSPATH') || exit;

class SettingsContainer
{
    private static ?self $instance = null;
    private ?array $settings = null;
    private array $oauthData = [];

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function getSettings($setting = null)
    {
        if ($this->settings === null) {
            $this->settings = Settings::getSettings();
        }

        if ($setting === null) return $this->settings;

        return $this->settings[$setting] ?? null;
    }

    public function getOauthData($forceRefresh = false): array
    {
        if ($forceRefresh || empty($this->oauthData)) {
            $this->oauthData = Settings::getOauthData($forceRefresh);
        }

        return $this->oauthData;
    }

    public function hasToken(): bool
    {
        $token = ($this->getSettings(SettingsConfig::TOKEN));
        if (!$token) return false;
        return true;
    }
}
