<?php

namespace SyncBasalam\Admin\Pages;

defined('ABSPATH') || exit;
class MainPage extends AdminPageAbstract
{
    public $checkToken = false;

    protected function renderContent()
    {
        $template = syncBasalamPlugin()->templatePath("admin/Dashboard.php");
        if (file_exists($template)) {
            require_once($template);
        }
    }
}
