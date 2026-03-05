<?php

namespace SyncBasalam\Services\Ticket;

use SyncBasalam\Config\Endpoints;
use SyncBasalam\Services\ApiServiceManager;

class FetchTicket
{
    private $url;

    public function __construct($ticket_id)
    {
        $this->url  = sprintf(Endpoints::TICKET_DETAIL, $ticket_id);
    }
    public function execute($hamsalamToken)
    {
        $apiService = syncBasalamContainer()->get(ApiServiceManager::class);
        $header = ['Authorization' => 'Bearer ' . $hamsalamToken];

        try {
            return $apiService->get($this->url, $header);
        } catch (\Exception $e) {
            return [
                'status_code' => 500,
                'body' => null,
                'error' => 'خطا در دریافت تیکت: ' . $e->getMessage(),
            ];
        }
    }
}
