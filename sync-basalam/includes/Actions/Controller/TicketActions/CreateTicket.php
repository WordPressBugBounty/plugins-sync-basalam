<?php

namespace SyncBasalam\Actions\Controller\TicketActions;

use SyncBasalam\Actions\Controller\ActionController;
use SyncBasalam\Services\TicketServiceManager;

defined('ABSPATH') || exit;

class CreateTicket extends ActionController
{
    public function __invoke()
    {
        $ticketManager = new TicketServiceManager();

        $title = isset($_POST['title']) ? \sanitize_text_field(\wp_unslash($_POST['title'])) : null;
        $subject = isset($_POST['subject']) ? \sanitize_text_field(\wp_unslash($_POST['subject'])) : null;
        $content = isset($_POST['content']) ? \sanitize_text_field(\wp_unslash($_POST['content'])) : null;

        $result = $ticketManager->createTicket($title, $subject, $content);
        if (isset($result['body'])) $ticket = json_decode($result['body'], true);
        wp_redirect(admin_url("admin.php?page=sync_basalam_ticket&ticket_id=" . $ticket['data']['id']));
        exit();
    }
}
