<?php

namespace SyncBasalam\Actions\Controller\TicketActions;

use SyncBasalam\Actions\Controller\ActionController;
use SyncBasalam\Services\TicketServiceManager;

defined('ABSPATH') || exit;

class UploadTicketMediaAjax extends ActionController
{
    public function __invoke()
    {
        if (empty($_FILES['file']['tmp_name'])) {
            wp_send_json_error(['message' => 'فایلی انتخاب نشده است.']);
            return;
        }

        $originalName = $_FILES['file']['name'] ?? 'unknown';
        $fileSize     = $_FILES['file']['size'] ?? 0;

        $moveResult = wp_handle_upload($_FILES['file'], ['test_form' => false]);

        if (isset($moveResult['error'])) {
            wp_send_json_error(['message' => $moveResult['error']]);
            return;
        }

        $filePath = $moveResult['file'];

        $ticketManager = new TicketServiceManager();
        $uploadResult  = $ticketManager->uploadTicketMedia($filePath);

        @unlink($filePath);

        if (empty($uploadResult['body']['data']['id'])) {
            wp_send_json_error(['message' => 'خطا در آپلود فایل به سرور همسلام.']);
            return;
        }

        $fileId = $uploadResult['body']['data']['id'];

        wp_send_json_success(['file_id' => $fileId]);
    }
}
