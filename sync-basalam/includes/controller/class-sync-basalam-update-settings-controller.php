<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Update_Setting extends Sync_BasalamController
{
    public function __invoke()
    {
        sync_basalam_Admin_Settings::save_settings();
    }
}
