<?php

namespace SyncBasalam\Admin\Pages;

defined('ABSPATH') || exit;

class CreateTicketPage extends AdminPageAbstract
{
    public $checkToken = true;

    protected function renderContent()
    {
        $template = syncBasalamPlugin()->templatePath("admin/Ticket/Create.php");
        if (file_exists($template)) {
            require_once($template);
        }
    }
}
