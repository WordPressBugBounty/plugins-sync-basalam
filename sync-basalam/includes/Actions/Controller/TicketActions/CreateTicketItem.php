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

        $content = isset($_POST['content']) ? \sanitize_text_field(\wp_unslash($_POST['content'])) : null;
        $ticketId = isset($_POST['ticket_id']) ? \sanitize_text_field(\wp_unslash($_POST['ticket_id'])) : null;


        $result = $ticketManager->CreateTicketItem($ticketId,$content);
        // if (isset($result['body'])) $ticket = json_decode($result['body'], true);
    }
}
