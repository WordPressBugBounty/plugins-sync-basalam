<?php

namespace SyncBasalam\Services\Ticket;

use SyncBasalam\Services\ApiServiceManager;

class FetchTicketSubjects
{
    private $url;

    public function __construct()
    {
        $this->url  = "https://api.hamsalam.ir/api/v1/tickets/subjects";
    }
    public function execute($hamsalamToken)
    {
        $apiService = new ApiServiceManager();
        $headers = [
            'Authorization' => 'Bearer ' . $hamsalamToken,
            'X-App-Name' => 'woosalam'
        ];

        return $apiService->sendGetRequest($this->url, $headers);
    }
}
