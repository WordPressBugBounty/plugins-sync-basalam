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

        return $apiService->upload($this->url, $filePath, [], $header);
    }
}
