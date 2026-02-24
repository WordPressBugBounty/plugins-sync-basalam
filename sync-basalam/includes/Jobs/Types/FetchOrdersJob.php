<?php

namespace SyncBasalam\Jobs\Types;

use SyncBasalam\Jobs\AbstractJobType;
use SyncBasalam\Jobs\JobResult;
use SyncBasalam\Jobs\Exceptions\RetryableException;
use SyncBasalam\Jobs\Exceptions\NonRetryableException;
use SyncBasalam\Services\Orders\FetchOrdersService;
use SyncBasalam\Services\Orders\SyncOrderService;
use SyncBasalam\Logger\Logger;

defined('ABSPATH') || exit;

class FetchOrdersJob extends AbstractJobType
{
    private FetchOrdersService $fetchOrdersService;
    private SyncOrderService $syncOrderService;

    public function __construct()
    {
        parent::__construct();
        $this->fetchOrdersService = new FetchOrdersService();
        $this->syncOrderService = new SyncOrderService();
    }

    public function getType(): string
    {
        return 'sync_basalam_fetch_orders';
    }

    public function getPriority(): int
    {
        return 5;
    }

    public function execute(array $payload): JobResult
    {
        $cursor = $payload['cursor'] ?? null;
        $day = $payload['day'] ?? 7;

        try {
            $fetchResult = $this->fetchOrdersService->fetchPage($day, $cursor);
        } catch (RetryableException $e) {
            throw $e;
        } catch (NonRetryableException $e) {
            throw $e;
        } catch (\Throwable $th) {
            throw $th;
        }

        if (!$fetchResult['success']) {
            Logger::error("خطا در دریافت سفارشات: " . ($fetchResult['message'] ?? 'خطای نامشخص'));
            return $this->retryable($fetchResult['message'] ?? 'خطا در دریافت سفارشات از API');
        }

        $orders = $fetchResult['orders'];
        $nextCursor = $fetchResult['next_cursor'];

        $syncResult = $this->syncOrderService->syncOrders($orders);

        if ($nextCursor) {
            $this->jobManager->createJob(
                'sync_basalam_fetch_orders',
                'pending',
                json_encode([
                    'cursor' => $nextCursor,
                    'day' => $day
                ])
            );
        }

        return $this->success([
            'synced' => $syncResult['synced'],
            'skipped' => $syncResult['skipped'],
            'errors_count' => count($syncResult['errors']),
            'errors' => $syncResult['errors'],
            'has_more_pages' => $nextCursor !== null,
            'next_cursor' => $nextCursor
        ]);
    }
}
