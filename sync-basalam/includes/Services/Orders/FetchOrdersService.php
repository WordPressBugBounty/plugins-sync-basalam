<?php

namespace SyncBasalam\Services\Orders;

use SyncBasalam\Services\ApiServiceManager;
use SyncBasalam\Logger\Logger;
use SyncBasalam\Jobs\Exceptions\RetryableException;
use SyncBasalam\Jobs\Exceptions\NonRetryableException;

defined('ABSPATH') || exit;

class FetchOrdersService
{
    private string $url;
    private ApiServiceManager $apiService;

    public function __construct()
    {
        $this->url = "https://openapi.basalam.com/v1/vendor-parcels";
        $this->apiService = new ApiServiceManager();
    }

    public function fetchPage(int $day = 7, $cursor = null): array
    {
        $timestamp = current_time('timestamp', true) - ($day * 24 * 60 * 60);
        $isoDate = gmdate('c', $timestamp);

        $url = $this->url . '?per_page=10&created_at%5Bgte%5D=' . urlencode($isoDate);

        if ($cursor) $url .= '&cursor=' . urlencode($cursor);

        try {
            $response = $this->apiService->sendGetRequest($url);

            if (!isset($response['body'])) {
                return [
                    'success' => false,
                    'orders' => [],
                    'next_cursor' => null,
                    'message' => 'پاسخی از API دریافت نشد.'
                ];
            }

            $bodyData = json_decode($response['body'], true);

            if (!isset($bodyData['data'])) {
                return [
                    'success' => false,
                    'orders' => [],
                    'next_cursor' => null,
                    'message' => 'داده‌ای در پاسخ API یافت نشد.'
                ];
            }

            $nextCursor = $bodyData['next_cursor'] ?? null;

            Logger::debug("دریافت صفحه سفارشات: " . count($bodyData['data']) . " سفارش، cursor بعدی: " . ($nextCursor ?: 'ندارد'));

            return [
                'success' => true,
                'orders' => $bodyData['data'],
                'next_cursor' => $nextCursor,
                'message' => null
            ];
        } catch (RetryableException $e) {
            throw $e;
        } catch (NonRetryableException $e) {
            throw $e;
        } catch (\Throwable $th) {
            throw $th;
        }
    }
}
