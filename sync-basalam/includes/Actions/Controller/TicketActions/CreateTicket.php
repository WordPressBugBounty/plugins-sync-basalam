<?php

namespace SyncBasalam\Actions\Controller\TicketActions;

use SyncBasalam\Actions\Controller\ActionController;
use SyncBasalam\Services\TicketServiceManager;
use SyncBasalam\Utilities\TicketExtraInfoFormatter;
use SyncBasalam\Utilities\TicketAccessData;

defined('ABSPATH') || exit;

class CreateTicket extends ActionController
{
    public function __invoke()
    {
        if (!current_user_can('manage_options')) {
            wp_die('دسترسی غیرمجاز!');
        }

        $ticketId = $this->processSubmission();

        wp_safe_redirect(admin_url("admin.php?page=sync_basalam_ticket&ticket_id=" . $ticketId));
        exit();
    }

    /**
     * Run the two-step submission without redirecting.
     *
     * The optional manager keeps the action flow directly testable while the
     * normal WordPress invocation still creates the production manager.
     */
    public function processSubmission(?TicketServiceManager $ticketManager = null): int
    {
        $ticketManager = $ticketManager ?: new TicketServiceManager();

        $title   = isset($_POST['title'])   ? \sanitize_text_field(\wp_unslash($_POST['title']))   : null;
        $subject = isset($_POST['subject']) ? \sanitize_text_field(\wp_unslash($_POST['subject'])) : null;
        $content = isset($_POST['content']) ? TicketExtraInfoFormatter::sanitizeContent($_POST['content']) : '';

        try {
            $accessData = TicketAccessData::fromRequest($_POST);
        } catch (\InvalidArgumentException $e) {
            wp_die(esc_html($e->getMessage()));
        }

        $fileIds = isset($_POST['file_ids']) && is_array($_POST['file_ids'])
            ? array_map('intval', $_POST['file_ids'])
            : [];

        $result = $ticketManager->createTicket($title, $subject, $content, $fileIds);
        if (TicketServiceManager::isSuccessful($result) && isset($result['body'])) {
            $ticket = json_decode($result['body'], true);
        } else {
            wp_die('خطایی در ارسال تیکت رخ داده است. لطفا مجددا تلاش کنید.');
        }

        $ticketId = intval($ticket['data']['id'] ?? 0);
        if ($ticketId <= 0) {
            wp_die('پاسخ ایجاد تیکت معتبر نیست. لطفا مجددا تلاش کنید.');
        }

        if (!empty($accessData)) {
            $accessResult = $ticketManager->upsertTicketSiteAccess($ticketId, $accessData);
            if (!TicketServiceManager::isSuccessful($accessResult)) {
                wp_die(esc_html(
                    'تیکت شماره ' . $ticketId . ' ثبت شد، اما اطلاعات دسترسی ذخیره نشد. '
                    . 'لطفا از صفحه تیکت دوباره اطلاعات دسترسی را ارسال کنید.'
                ));
            }
        }

        return $ticketId;
    }
}
