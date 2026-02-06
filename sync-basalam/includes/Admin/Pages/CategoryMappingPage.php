<?php

namespace SyncBasalam\Admin\Pages;

defined('ABSPATH') || exit;

class CategoryMappingPage extends AdminPageAbstract
{
    public $checkToken = true;

    protected function renderContent()
    {
        $template = syncBasalamPlugin()->templatePath("admin/CategoryMapping.php");
        if (file_exists($template)) {
            require_once($template);
        }
    }
}
