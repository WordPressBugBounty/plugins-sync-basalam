<?php

namespace SyncBasalam\Admin\Pages;

defined('ABSPATH') || exit;

class HelpPage extends AdminPageAbstract
{
    public $checkToken = false;

    protected function renderContent()
    {
        $template = syncBasalamPlugin()->templatePath("admin/Help/Main.php");
        if (file_exists($template)) require_once($template);
    }
}
