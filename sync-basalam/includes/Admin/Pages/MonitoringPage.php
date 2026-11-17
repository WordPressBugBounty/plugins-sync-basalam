<?php

namespace SyncBasalam\Admin\Pages;

defined('ABSPATH') || exit;

class MonitoringPage extends AdminPageAbstract
{
    public const SLUG = 'sync_basalam_monitoring';

    public $checkToken = false;

    protected function renderContent()
    {
        require syncBasalamPlugin()->templatePath('admin/main/Monitoring.php');
    }
}
