<?php

namespace SyncBasalam\Services\Ticket;

use SyncBasalam\Services\ApiServiceManager;

class FetchAllTickets
{
    private $url;

    public function __construct()
    {
        $this->url  = 'https://api.hamsalam.ir/api/v1/tickets';
    }
    public function execute($hamsalamToken, $page = 1)
    {
        $apiService = new ApiServiceManager();
        $url = $this->url . "?page=$page";
        $header = ['Authorization' => 'Bearer ' . $hamsalamToken];

        return $apiService->sendGetRequest($url, $header);
    }
}
