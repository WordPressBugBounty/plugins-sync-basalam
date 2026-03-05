<?php

namespace SyncBasalam\Services\Ticket;

use SyncBasalam\Config\Endpoints;
use SyncBasalam\Services\ApiServiceManager;

class CreateTicket
{
    private $url;

    public function __construct()
    {
        $this->url  = Endpoints::TICKET_LIST;
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
                'error' => 'خطا در ایجاد تیکت: ' . $e->getMessage(),
            ];
        }
    }
}
