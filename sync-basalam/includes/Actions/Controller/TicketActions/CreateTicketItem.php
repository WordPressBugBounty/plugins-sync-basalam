<?php

namespace SyncBasalam\Actions\Controller\TicketActions;

use SyncBasalam\Actions\Controller\ActionController;
use SyncBasalam\Services\TicketServiceManager;
use SyncBasalam\Utilities\TicketExtraInfoFormatter;
use SyncBasalam\Utilities\TicketAccessData;
use SyncBasalam\Utilities\TicketFlashNotice;

defined('ABSPATH') || exit;

class CreateTicketItem extends ActionController
{
    public function __invoke()
    {
        if (!current_user_can('manage_options')) {
            wp_die('دسترسی غیرمجاز!');
        }

        try {
            $this->processSubmission();
        } catch (\Throwable $e) {
            $message = $e instanceof \InvalidArgumentException || $e instanceof \RuntimeException
                ? $e->getMessage()
                : 'خطای غیرمنتظره‌ای در ارسال پاسخ تیکت رخ داد. لطفا مجددا تلاش کنید.';
            TicketFlashNotice::push($message, 'error');

            $ticketId = isset($_POST['ticket_id']) ? intval(wp_unslash($_POST['ticket_id'])) : 0;
            $redirectUrl = $ticketId > 0
                ? admin_url('admin.php?page=sync_basalam_ticket&ticket_id=' . $ticketId)
                : admin_url('admin.php?page=sync_basalam_tickets');
            wp_safe_redirect($redirectUrl);
            exit();
        }
    }

    /** Run reply creation before the optional access replacement. */
    public function processSubmission(?TicketServiceManager $ticketManager = null): void
    {
        $ticketManager = $ticketManager ?: new TicketServiceManager();

        $content  = isset($_POST['content']) ? TicketExtraInfoFormatter::sanitizeContent($_POST['content']) : '';
        try {
            $accessData = TicketAccessData::fromRequest($_POST);
        } catch (\InvalidArgumentException $e) {
            throw $e;
        }
        if ($content === '' && !empty($accessData)) {
            $content = 'اطلاعات دسترسی به‌روزرسانی شد.';
        }
        $ticketId = isset($_POST['ticket_id']) ? intval(\wp_unslash($_POST['ticket_id'])) : 0;

        if ($ticketId <= 0) {
            throw new \RuntimeException('شناسه تیکت معتبر نیست.');
        }

        $ticketResponse = $ticketManager->fetchTicket($ticketId);
        $ticket = isset($ticketResponse['body']) ? json_decode($ticketResponse['body'], true) : null;

        if (is_array($ticket) && TicketServiceManager::isTicketClosed($ticket)) {
            throw new \RuntimeException('این تیکت بسته شده است و امکان ارسال پاسخ جدید وجود ندارد.');
        }

        $fileIds = isset($_POST['file_ids']) && is_array($_POST['file_ids'])
            ? array_map('intval', $_POST['file_ids'])
            : [];

        $itemResult = $ticketManager->createTicketItem($ticketId, $content, $fileIds);
        if (!TicketServiceManager::isSuccessful($itemResult)) {
            throw new \RuntimeException('خطایی در ارسال پاسخ تیکت رخ داد. لطفا مجددا تلاش کنید.');
        }

        if (!empty($accessData)) {
            $accessResult = $ticketManager->upsertTicketSiteAccess($ticketId, $accessData);
            if (!TicketServiceManager::isSuccessful($accessResult)) {
                throw new \RuntimeException(
                    'پاسخ تیکت ثبت شد، اما اطلاعات دسترسی ذخیره نشد. '
                    . 'لطفا دوباره اطلاعات دسترسی را ارسال کنید.'
                );
            }
        }
    }
}
