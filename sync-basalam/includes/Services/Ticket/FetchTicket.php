<?php

namespace SyncBasalam\Services\Ticket;

use SyncBasalam\Services\ApiServiceManager;

class FetchTicket
{
    private $url;

    public function __construct($ticket_id)
    {
        $this->url  = "https://api.hamsalam.ir/api/v1/tickets/$ticket_id";
    }
    public function execute($hamsalamToken)
    {
        $apiService = new ApiServiceManager();
        $header = ['Authorization' => 'Bearer ' . $hamsalamToken];

        return $apiService->sendGetRequest($this->url, $header);
    }
}
