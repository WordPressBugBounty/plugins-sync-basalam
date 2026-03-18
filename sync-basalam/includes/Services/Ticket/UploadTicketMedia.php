<?php

namespace SyncBasalam\Services\Ticket;

use SyncBasalam\Config\Endpoints;
use SyncBasalam\Services\ApiServiceManager;

defined('ABSPATH') || exit;

class UploadTicketMedia
{
    private $url = Endpoints::TICKET_MEDIA_UPLOAD;

    public function execute($hamsalamToken, $filePath)
    {
        $apiService = syncBasalamContainer()->get(ApiServiceManager::class);
        $header = ['Authorization' => 'Bearer ' . $hamsalamToken];

        try {
            $apiService->upload($this->url, $filePath, [], $header);
        } catch (\Exception $e) {
            return [
                'status_code' => $e->getCode() ?? 500,
                'body' => null,
                'error' => 'خطا در آپلود فایل: ' . $e->getMessage(),
            ];
        }
    }
}
