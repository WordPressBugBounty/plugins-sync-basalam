<?php

namespace SyncBasalam\Jobs\Types;

use SyncBasalam\Jobs\AbstractJobType;
use SyncBasalam\Jobs\JobResult;
use SyncBasalam\Jobs\Exceptions\RetryableException;
use SyncBasalam\Logger\Logger;
use SyncBasalam\Services\Products\UnmappedProducts\ArchiveBasalamProductService;
use SyncBasalam\Services\Products\UnmappedProducts\UnmappedProductsRepository;
use SyncBasalam\Services\Products\UnmappedProducts\UnmappedProductsScanner;
use SyncBasalam\Services\Products\UnmappedProducts\UnmappedScanState;

defined('ABSPATH') || exit;

/**
 * «آرشیو همهٔ محصولات متصل‌نشده»: آیتم‌های باز گزارش را دسته‌دسته آرشیو می‌کند.
 *
 * هر اجرا حداکثر BATCH_SIZE محصول را PATCH می‌کند و ادامه را به job بعدی می‌سپارد
 * تا فشار درخواست‌ها روی سایت و API پخش شود. خطای دائمی یک محصول فقط همان ردیف
 * را failed می‌کند و بقیهٔ صف را متوقف نمی‌کند.
 */
class ArchiveUnmappedProductsJob extends AbstractJobType
{
    public const TYPE = 'sync_basalam_archive_unmapped_products';
    private const BATCH_SIZE = 5;

    private $archiveService;
    private $repository;

    public function __construct($jobManager, $archiveService = null, $repository = null)
    {
        parent::__construct($jobManager);
        $this->archiveService = $archiveService;
        $this->repository = $repository;
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    public function getPriority(): int
    {
        return 9;
    }

    public function execute(array $payload): JobResult
    {
        $vendorId = (string) ($payload['vendor_id'] ?? UnmappedProductsScanner::currentVendorId());
        if ($vendorId === '') {
            return $this->success(['skipped' => true, 'reason' => 'شناسهٔ غرفه تنظیم نشده است.']);
        }

        $state = new UnmappedScanState($vendorId);
        if ($state->isArchiveStopRequested()) {
            return $this->success(['stopped' => true, 'archived' => 0, 'skipped' => 0, 'failed' => 0]);
        }

        $repository = $this->repository ?: new UnmappedProductsRepository();
        $archiveService = $this->archiveService ?: new ArchiveBasalamProductService();

        $rows = $repository->claimOpenForArchive($vendorId, self::BATCH_SIZE);

        $archived = 0;
        $skipped = 0;
        $failed = 0;

        foreach (array_values($rows) as $index => $row) {
            // Read fresh state between requests; never release the in-flight row early.
            if ($state->isArchiveStopRequested()) {
                $this->releaseClaims($repository, array_slice(array_values($rows), $index));
                return $this->success(['stopped' => true, 'archived' => $archived, 'skipped' => $skipped, 'failed' => $failed]);
            }

            $rowId = (int) $row->id;
            $basalamProductId = (int) $row->basalam_product_id;

            // اگر همین الان به محصولی وصل شده باشد، آرشیوکردنش اشتباه است.
            if ($repository->isMapped($basalamProductId)) {
                $repository->transition($rowId, [UnmappedProductsRepository::STATE_ARCHIVING], UnmappedProductsRepository::STATE_CONNECTED);
                $skipped++;
                continue;
            }

            try {
                $archiveService->archive($basalamProductId);
                $repository->transition($rowId, [UnmappedProductsRepository::STATE_ARCHIVING], UnmappedProductsRepository::STATE_ARCHIVED);
                $archived++;
            } catch (RetryableException $e) {
                // خطای موقت: این ردیف و همهٔ ردیف‌های claim‌شدهٔ باقی‌مانده به صف
                // برمی‌گردند و همین job با backoff تکرار می‌شود؛ وگرنه در وضعیت
                // archiving گیر می‌کردند و claim بعدی آن‌ها را نمی‌دید.
                $this->releaseClaims($repository, array_slice(array_values($rows), $index));
                if ($state->isArchiveStopRequested()) {
                    return $this->success(['stopped' => true, 'archived' => $archived, 'skipped' => $skipped, 'failed' => $failed]);
                }
                throw $e;
            } catch (\Exception $e) {
                $repository->transition(
                    $rowId,
                    [UnmappedProductsRepository::STATE_ARCHIVING],
                    UnmappedProductsRepository::STATE_FAILED,
                    mb_substr($e->getMessage(), 0, 500)
                );
                Logger::error('آرشیو محصول متصل‌نشده ناموفق بود: ' . $e->getMessage(), [
                    'operation' => 'آرشیو گروهی محصولات متصل‌نشده',
                    'basalam_product_id' => $basalamProductId,
                ]);
                $failed++;
            }
        }

        if ($repository->hasOpen($vendorId) && !$state->isArchiveStopRequested()) {
            $this->jobManager->createJob(self::TYPE, 'pending', json_encode(['vendor_id' => $vendorId]));
        } else {
            (new UnmappedScanState($vendorId))->releaseIfResolved($repository->unresolvedCount($vendorId));
        }

        return $this->success([
            'archived' => $archived,
            'skipped' => $skipped,
            'failed' => $failed,
        ]);
    }

    private function releaseClaims($repository, array $rows): void
    {
        foreach ($rows as $row) {
            $repository->transition((int) $row->id, [UnmappedProductsRepository::STATE_ARCHIVING], UnmappedProductsRepository::STATE_OPEN);
        }
    }
}
