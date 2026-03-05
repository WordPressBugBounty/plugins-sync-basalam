<?php

namespace SyncBasalam\Services\Ticket;

use SyncBasalam\Config\Endpoints;
use SyncBasalam\Services\ApiServiceManager;

class FetchTicketSubjects
{
    private $url;

    public function __construct()
    {
        $this->url  = Endpoints::TICKET_SUBJECTS;
    }
    public function execute($hamsalamToken)
    {
        $apiService = syncBasalamContainer()->get(ApiServiceManager::class);
        $headers = [
            'Authorization' => 'Bearer ' . $hamsalamToken,
            'X-App-Name' => 'woosalam'
        ];

        try {
            return $apiService->get($this->url, $headers);
        } catch (\Exception $e) {
            return [
                'status_code' => 500,
                'body' => null,
                'error' => 'خطا در دریافت موضوعات تیکت: ' . $e->getMessage(),
            ];
        }
    }
}
