<?php

namespace SyncBasalam\Actions\Controller\TicketActions;

use SyncBasalam\Actions\Controller\ActionController;
use SyncBasalam\Services\TicketServiceManager;
use SyncBasalam\Utilities\TicketExtraInfoFormatter;

defined('ABSPATH') || exit;

class CreateTicketItem extends ActionController
{
    public function __invoke()
    {
        $ticketManager = new TicketServiceManager();

        $content  = isset($_POST['content']) ? \sanitize_textarea_field(\wp_unslash($_POST['content'])) : '';
        $content  = TicketExtraInfoFormatter::appendFromRequest($content, $_POST);
        $ticketId = isset($_POST['ticket_id']) ? intval(\wp_unslash($_POST['ticket_id'])) : 0;

        if ($ticketId <= 0) return;

        $ticketResponse = $ticketManager->fetchTicket($ticketId);
        $ticket = isset($ticketResponse['body']) ? json_decode($ticketResponse['body'], true) : null;

        if (is_array($ticket) && TicketServiceManager::isTicketClosed($ticket)) return;

        $fileIds = isset($_POST['file_ids']) && is_array($_POST['file_ids'])
            ? array_map('intval', $_POST['file_ids'])
            : [];

        $ticketManager->createTicketItem($ticketId, $content, $fileIds);
    }
}
