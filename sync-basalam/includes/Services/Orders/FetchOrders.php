<?php

namespace SyncBasalam\Services\Orders;

use SyncBasalam\Services\ApiServiceManager;

defined('ABSPATH') || exit;

class FetchOrders
{
    private $url;
    private $apiservice;

    public function __construct()
    {
        $this->url = "https://openapi.basalam.com/v1/vendor-parcels";
        $this->apiservice = new ApiServiceManager();
    }

    public function getWeeklyOrders()
    {
        $oneWeekAgoTimestamp = current_time('timestamp', true) - (7 * 24 * 60 * 60);
        $oneWeekAgoIso = gmdate('c', $oneWeekAgoTimestamp);

        $firstPageUrl = $this->url . '?per_page=30&created_at%5Bgte%5D=' . urlencode($oneWeekAgoIso);

        return $this->fetchAllPages($firstPageUrl);
    }

    private function fetchAllPages($url, $collected = [])
    {
        $response = $this->apiservice->sendGetRequest($url);

        if (!isset($response['body'])) return $collected;

        $bodyData = json_decode($response['body'], true);

        if (!isset($bodyData['data'])) return $collected;

        $collected = array_merge($collected, $bodyData['data']);

        $next = $bodyData['next_cursor'] ?? null;

        if ($next) {
            $nextUrl = $url . '&cursor=' . urlencode($next);

            return $this->fetchAllPages($nextUrl, $collected);
        }

        return $collected;
    }
}
