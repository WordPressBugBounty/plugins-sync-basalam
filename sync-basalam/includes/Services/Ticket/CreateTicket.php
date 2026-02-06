<?php

namespace SyncBasalam\Services\Ticket;

use SyncBasalam\Services\ApiServiceManager;

class CreateTicket
{
    private $url;

    public function __construct()
    {
        $this->url  = 'https://api.hamsalam.ir/api/v1/tickets';
    }
    public function execute($hamsalamToken, $data)
    {
        $apiService = new ApiServiceManager();
        $header = ['Authorization' => 'Bearer ' . $hamsalamToken];

        return $apiService->sendPostRequest($this->url, $data, $header);
    }
}
