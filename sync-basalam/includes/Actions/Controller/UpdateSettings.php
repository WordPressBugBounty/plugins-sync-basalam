<?php

namespace SyncBasalam\Actions\Controller;

use SyncBasalam\Admin\Settings;

defined('ABSPATH') || exit;
class UpdateSettings extends ActionController
{
    public function __invoke()
    {
        try {
            Settings::saveSettings();
        } catch (\Exception $e) {
            wp_die('Error saving settings: ' . $e->getMessage());
        }
    }
}
