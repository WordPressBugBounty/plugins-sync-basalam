<?php

namespace SyncBasalam\Services\Ticket;

use SyncBasalam\Services\ApiServiceManager;

defined('ABSPATH') || exit;

class UploadTicketMedia
{
    private $url = 'https://api.hamsalam.ir/api/v1/media?type=ticket_item&collection=IMAGE';

    public function execute($hamsalamToken, $filePath)
    {
        $apiService = new ApiServiceManager();
        $header = ['Authorization' => 'Bearer ' . $hamsalamToken];

        return $apiService->uploadFileRequest($this->url, $filePath, [], $header);
    }
}
