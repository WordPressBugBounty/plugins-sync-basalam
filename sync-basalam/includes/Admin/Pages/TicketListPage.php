<?php

namespace SyncBasalam\Admin\Pages;

defined('ABSPATH') || exit;

class TicketListPage extends AdminPageAbstract
{
    public $checkToken = true;

    protected function renderContent()
    {
        $template = syncBasalamPlugin()->templatePath("admin/Ticket/List.php");
        if (file_exists($template)) require_once($template);
    }
}
