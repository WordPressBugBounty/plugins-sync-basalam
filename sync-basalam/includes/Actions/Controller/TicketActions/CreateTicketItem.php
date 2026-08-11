<?php

namespace SyncBasalam\Actions\Controller\TicketActions;

use SyncBasalam\Actions\Controller\ActionController;
use SyncBasalam\Services\TicketServiceManager;
use SyncBasalam\Utilities\TicketExtraInfoFormatter;
use SyncBasalam\Utilities\TicketAccessData;

defined('ABSPATH') || exit;

class CreateTicketItem extends ActionController
{
    public function __invoke()
    {
        if (!current_user_can('manage_options')) {
            wp_die('دسترسی غیرمجاز!');
        }

        $this->processSubmission();
    }

    /** Run reply creation before the optional access replacement. */
    public function processSubmission(?TicketServiceManager $ticketManager = null): void
    {
        $ticketManager = $ticketManager ?: new TicketServiceManager();

        $content  = isset($_POST['content']) ? TicketExtraInfoFormatter::sanitizeContent($_POST['content']) : '';
        try {
            $accessData = TicketAccessData::fromRequest($_POST);
        } catch (\InvalidArgumentException $e) {
            wp_die(esc_html($e->getMessage()));
        }
        if ($content === '' && !empty($accessData)) {
            $content = 'اطلاعات دسترسی به‌روزرسانی شد.';
        }
        $ticketId = isset($_POST['ticket_id']) ? intval(\wp_unslash($_POST['ticket_id'])) : 0;

        if ($ticketId <= 0) return;

        $ticketResponse = $ticketManager->fetchTicket($ticketId);
        $ticket = isset($ticketResponse['body']) ? json_decode($ticketResponse['body'], true) : null;

        if (is_array($ticket) && TicketServiceManager::isTicketClosed($ticket)) return;

        $fileIds = isset($_POST['file_ids']) && is_array($_POST['file_ids'])
            ? array_map('intval', $_POST['file_ids'])
            : [];

        $itemResult = $ticketManager->createTicketItem($ticketId, $content, $fileIds);
        if (!TicketServiceManager::isSuccessful($itemResult)) {
            wp_die('خطایی در ارسال پاسخ تیکت رخ داد. لطفا مجددا تلاش کنید.');
        }

        if (!empty($accessData)) {
            $accessResult = $ticketManager->upsertTicketSiteAccess($ticketId, $accessData);
            if (!TicketServiceManager::isSuccessful($accessResult)) {
                wp_die(esc_html(
                    'پاسخ تیکت ثبت شد، اما اطلاعات دسترسی ذخیره نشد. '
                    . 'لطفا دوباره اطلاعات دسترسی را ارسال کنید.'
                ));
            }
        }
    }
}
