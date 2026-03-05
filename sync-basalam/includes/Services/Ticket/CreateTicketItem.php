<?php

namespace SyncBasalam\Services\Ticket;

use SyncBasalam\Config\Endpoints;
use SyncBasalam\Services\ApiServiceManager;

class CreateTicketItem
{
    private $url;

    public function __construct($ticket_id)
    {
        $this->url  = sprintf(Endpoints::TICKET_ITEMS, $ticket_id);
    }
    public function execute($hamsalamToken, $data)
    {
        $apiService = syncBasalamContainer()->get(ApiServiceManager::class);
        $header = ['Authorization' => 'Bearer ' . $hamsalamToken];

        try {
            return $apiService->post($this->url, $data, $header);
        } catch (\Exception $e) {
            return [
                'status_code' => 500,
                'body' => null,
                'error' => 'خطا در ثبت پاسخ تیکت: ' . $e->getMessage(),
            ];
        }
    }
}
