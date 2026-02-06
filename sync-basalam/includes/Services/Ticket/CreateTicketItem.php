<?php

namespace SyncBasalam\Services\Ticket;

use SyncBasalam\Services\ApiServiceManager;

class CreateTicketItem
{
    private $url;

    public function __construct($ticket_id)
    {
        $this->url  = "https://api.hamsalam.ir/api/v1/tickets/$ticket_id/ticket-items";
    }
    public function execute($hamsalamToken, $data)
    {
        $apiService = new ApiServiceManager();
        $header = ['Authorization' => 'Bearer ' . $hamsalamToken];

        return $apiService->sendPostRequest($this->url, $data, $header);
    }
}
