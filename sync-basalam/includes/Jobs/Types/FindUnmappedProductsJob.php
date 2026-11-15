<?php

namespace SyncBasalam\Jobs\Types;

use SyncBasalam\Jobs\AbstractJobType;
use SyncBasalam\Jobs\JobResult;
use SyncBasalam\Logger\Logger;
use SyncBasalam\Services\Products\UnmappedProducts\UnmappedProductsRepository;
use SyncBasalam\Services\Products\UnmappedProducts\UnmappedProductsScanner;
use SyncBasalam\Services\Products\UnmappedProducts\UnmappedScanState;

defined('ABSPATH') || exit;

/**
 * پیمایش صفحه‌به‌صفحهٔ محصولات فعال غرفه برای یافتن موارد بدون مپینگ ووکامرس.
 *
 * هر اجرا یک صفحهٔ ۳۰تایی را بررسی و صفحهٔ بعد را به‌صورت job جدید صف می‌کند؛
 * خطاهای موقت API با backoff همین job تکرار می‌شوند و cursor حفظ می‌ماند.
 */
class FindUnmappedProductsJob extends AbstractJobType
{
    public const TYPE = 'sync_basalam_find_unmapped_products';

    private $scanner;
    private $repository;

    public function __construct($jobManager, $scanner = null, $repository = null)
    {
        parent::__construct($jobManager);
        $this->scanner = $scanner;
        $this->repository = $repository;
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    public function getPriority(): int
    {
        return 8;
    }

    public function execute(array $payload): JobResult
    {
        $vendorId = (string) ($payload['vendor_id'] ?? UnmappedProductsScanner::currentVendorId());
        if ($vendorId === '') {
            return $this->success(['skipped' => true, 'reason' => 'شناسهٔ غرفه تنظیم نشده است.']);
        }

        $state = new UnmappedScanState($vendorId);
        if (!$state->isScanning()) {
            // بررسی از بیرون لغو/ریست شده؛ ادامهٔ زنجیره بی‌معناست.
            return $this->success(['skipped' => true, 'reason' => 'بررسی فعالی در جریان نیست.']);
        }

        $cursor = isset($payload['cursor']) && $payload['cursor'] !== '' ? (string) $payload['cursor'] : null;

        try {
            $scanner = $this->scanner ?: new UnmappedProductsScanner();
            $result = $scanner->scanPage($vendorId, $cursor);
        } catch (\Exception $e) {
            Logger::error('خطا در بررسی محصولات متصل‌نشدهٔ باسلام: ' . $e->getMessage(), [
                'operation' => 'بررسی محصولات متصل‌نشدهٔ باسلام',
                'cursor' => (string) $cursor,
            ]);
            throw $e;
        }

        // پیشرفت زنده برای کارت وضعیت صفحه (تعداد بررسی‌شده/یافته‌شده تا این لحظه).
        $state->recordProgress((int) $result['scanned'], (int) $result['found']);

        if ($result['has_more']) {
            $this->jobManager->createJob(self::TYPE, 'pending', json_encode([
                'vendor_id' => $vendorId,
                'cursor' => $result['next_cursor'],
            ]));

            return $this->success([
                'scanned' => $result['scanned'],
                'found' => $result['found'],
                'next_cursor' => $result['next_cursor'],
            ]);
        }

        return $this->success($this->finalize($vendorId, $state));
    }

    private function finalize(string $vendorId, UnmappedScanState $state): array
    {
        $repository = $this->repository ?: new UnmappedProductsRepository();

        // اتصال‌هایی که حین بررسی انجام شده‌اند نباید در گزارش «متصل‌نشده» بمانند.
        $repository->resolveRowsThatBecameMapped($vendorId);

        $unresolved = $repository->unresolvedCount($vendorId);

        if ($unresolved > 0) {
            $state->markAwaitingResolution($unresolved);
        } else {
            $state->markIdle();
        }

        Logger::info('بررسی محصولات متصل‌نشدهٔ باسلام کامل شد.', [
            'operation' => 'بررسی محصولات متصل‌نشدهٔ باسلام',
            'unmapped_count' => $unresolved,
        ]);

        return ['completed' => true, 'unmapped_count' => $unresolved];
    }
}
