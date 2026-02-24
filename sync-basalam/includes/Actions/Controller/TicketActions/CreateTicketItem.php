<?php

namespace SyncBasalam\Actions\Controller\TicketActions;

use SyncBasalam\Actions\Controller\ActionController;
use SyncBasalam\Services\TicketServiceManager;

defined('ABSPATH') || exit;

class CreateTicketItem extends ActionController
{
    public function __invoke()
    {
        $ticketManager = new TicketServiceManager();

        $content  = isset($_POST['content'])   ? (\wp_unslash($_POST['content']))   : null;
        $ticketId = isset($_POST['ticket_id']) ? (\wp_unslash($_POST['ticket_id'])) : null;

        $fileIds = isset($_POST['file_ids']) && is_array($_POST['file_ids'])
            ? array_map('intval', $_POST['file_ids'])
            : [];

        $ticketManager->createTicketItem($ticketId, $content, $fileIds);
    }
}
