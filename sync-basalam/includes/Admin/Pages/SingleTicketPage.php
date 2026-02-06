<?php

namespace SyncBasalam\Admin\Pages;

defined('ABSPATH') || exit;

class SingleTicketPage extends AdminPageAbstract
{
    public $checkToken = true;

    protected function renderContent()
    {
        $template = syncBasalamPlugin()->templatePath("admin/Ticket/Single.php");
        if (file_exists($template)) require_once($template);
    }
}
