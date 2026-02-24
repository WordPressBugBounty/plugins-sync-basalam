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

        $title   = isset($_POST['title'])   ? \sanitize_text_field(\wp_unslash($_POST['title']))   : null;
        $subject = isset($_POST['subject']) ? \sanitize_text_field(\wp_unslash($_POST['subject'])) : null;
        $content = isset($_POST['content']) ? \sanitize_text_field(\wp_unslash($_POST['content'])) : null;

        $fileIds = isset($_POST['file_ids']) && is_array($_POST['file_ids'])
            ? array_map('intval', $_POST['file_ids'])
            : [];

        $result = $ticketManager->createTicket($title, $subject, $content, $fileIds);
        if (isset($result['body'])) $ticket = json_decode($result['body'], true);
        else {
            wp_die('خطایی در ارسال تیکت رخ داده است. لطفا مجددا تلاش کنید.');
        }

        wp_redirect(admin_url("admin.php?page=sync_basalam_ticket&ticket_id=" . $ticket['data']['id']));
        exit();
    }
}
