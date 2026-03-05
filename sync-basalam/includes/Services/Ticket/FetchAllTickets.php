<?php

namespace SyncBasalam\Services\Ticket;

use SyncBasalam\Config\Endpoints;
use SyncBasalam\Services\ApiServiceManager;

class FetchAllTickets
{
    private $url;

    public function __construct()
    {
        $this->url  = Endpoints::TICKET_LIST;
    }
    public function execute($hamsalamToken, $page = 1)
    {
        $apiService = syncBasalamContainer()->get(ApiServiceManager::class);
        $url = $this->url . "?page=$page";
        $header = ['Authorization' => 'Bearer ' . $hamsalamToken];

        try {
            return $apiService->get($url, $header);
        } catch (\Exception $e) {
            return [
                'status_code' => 500,
                'body' => null,
                'error' => 'خطا در دریافت لیست تیکت‌ها: ' . $e->getMessage(),
            ];
        }
    }
}
