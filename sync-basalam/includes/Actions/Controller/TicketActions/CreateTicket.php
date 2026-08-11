<?php

namespace SyncBasalam\Actions\Controller\TicketActions;

use SyncBasalam\Actions\Controller\ActionController;
use SyncBasalam\Services\TicketServiceManager;
use SyncBasalam\Utilities\TicketExtraInfoFormatter;
use SyncBasalam\Utilities\TicketAccessData;
use SyncBasalam\Utilities\TicketFlashNotice;

defined('ABSPATH') || exit;

class CreateTicket extends ActionController
{
    private $createdTicketId = 0;

    public function __invoke()
    {
        if (!current_user_can('manage_options')) {
            if (wp_doing_ajax()) {
                wp_send_json_error(['message' => 'دسترسی غیرمجاز!'], 403);
            }
            wp_die('دسترسی غیرمجاز!');
        }

        try {
            $ticketId = $this->processSubmission();
        } catch (\Throwable $e) {
            $message = $e instanceof \InvalidArgumentException || $e instanceof \RuntimeException
                ? $e->getMessage()
                : 'خطای غیرمنتظره‌ای در ثبت تیکت رخ داد. لطفا مجددا تلاش کنید.';

            if (wp_doing_ajax()) {
                wp_send_json_error([
                    'message' => $message,
                    'ticket_id' => $this->createdTicketId,
                ], 400);
            }

            TicketFlashNotice::push($message, 'error');

            $redirectUrl = $this->createdTicketId > 0
                ? admin_url('admin.php?page=sync_basalam_ticket&ticket_id=' . $this->createdTicketId)
                : admin_url('admin.php?page=sync_basalam_new_ticket');
            wp_safe_redirect($redirectUrl);
            exit();
        }

        $redirectUrl = admin_url('admin.php?page=sync_basalam_ticket&ticket_id=' . $ticketId);
        if (wp_doing_ajax()) {
            wp_send_json_success([
                'ticket_id' => $ticketId,
                'redirect_url' => $redirectUrl,
            ]);
        }

        wp_safe_redirect($redirectUrl);
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
            throw $e;
        }

        $fileIds = isset($_POST['file_ids']) && is_array($_POST['file_ids'])
            ? array_map('intval', $_POST['file_ids'])
            : [];

        $existingTicketId = isset($_POST['existing_ticket_id'])
            ? intval(wp_unslash($_POST['existing_ticket_id']))
            : 0;
        if ($existingTicketId > 0) {
            if (empty($accessData)) {
                throw new \RuntimeException('اطلاعات دسترسی برای ارسال مجدد کامل نیست.');
            }
            $ticketId = $existingTicketId;
            $this->createdTicketId = $ticketId;
        } else {
            $result = $ticketManager->createTicket($title, $subject, $content, $fileIds);
            if (TicketServiceManager::isSuccessful($result) && isset($result['body'])) {
                $ticket = json_decode($result['body'], true);
            } else {
                throw new \RuntimeException('خطایی در ارسال تیکت رخ داده است. لطفا مجددا تلاش کنید.');
            }

            $ticketId = intval($ticket['data']['id'] ?? 0);
            if ($ticketId <= 0) {
                throw new \RuntimeException('پاسخ ایجاد تیکت معتبر نیست. لطفا مجددا تلاش کنید.');
            }
            $this->createdTicketId = $ticketId;
        }

        if (!empty($accessData)) {
            $accessResult = $ticketManager->upsertTicketSiteAccess($ticketId, $accessData);
            if (!TicketServiceManager::isSuccessful($accessResult)) {
                throw new \RuntimeException(
                    'تیکت شماره ' . $ticketId . ' ثبت شد، اما اطلاعات دسترسی ذخیره نشد. '
                    . 'لطفا از صفحه تیکت دوباره اطلاعات دسترسی را ارسال کنید.'
                );
            }
        }

        return $ticketId;
    }
}
