<?php

namespace SyncBasalam\Services\Products\UnmappedProducts;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\JobManager;
use SyncBasalam\Jobs\Types\ArchiveUnmappedProductsJob;
use SyncBasalam\Jobs\Types\FindUnmappedProductsJob;

defined('ABSPATH') || exit;

/**
 * نقطهٔ مشترک شروع بررسی، آرشیو گروهی و گزارش وضعیت برای UI، زمان‌بندی صف و نوتیس.
 *
 * قانون اصلی: تا وقتی بررسی در جریان است یا گزارش قبلی آیتم باز دارد، بررسی
 * تازه — دستی یا خودکار — شروع نمی‌شود.
 */
class UnmappedProductsManager
{
    private $jobManager;
    private $repository;

    public function __construct($jobManager = null, $repository = null)
    {
        $this->jobManager = $jobManager ?: syncBasalamContainer()->get(JobManager::class);
        $this->repository = $repository ?: new UnmappedProductsRepository();
    }

    public function vendorId(): string
    {
        return (string) syncBasalamSettings()->getSettings(SettingsConfig::VENDOR_ID);
    }

    public function state(): UnmappedScanState
    {
        return new UnmappedScanState($this->vendorId());
    }

    public function repository(): UnmappedProductsRepository
    {
        return $this->repository;
    }

    public function hasActiveScanJob(): bool
    {
        return $this->hasActiveJob(FindUnmappedProductsJob::TYPE);
    }

    public function hasActiveArchiveJob(): bool
    {
        return $this->hasActiveJob(ArchiveUnmappedProductsJob::TYPE);
    }

    /**
     * زنجیرهٔ شکست‌خورده نباید قفل ابدی بسازد: وضعیت scanning بدون job زنده به idle
     * برمی‌گردد و ردیف‌های archiving بدون صف آرشیو فعال دوباره open می‌شوند.
     */
    public function reconcile(): void
    {
        $vendorId = $this->vendorId();
        if ($vendorId === '') return;

        $state = $this->state();
        $state->reconcileWithQueue($this->hasActiveScanJob());

        if ($state->isAwaitingResolution() && !$this->hasActiveArchiveJob()) {
            $this->repository->releaseStaleArchiving($vendorId);
            $state->releaseIfResolved($this->repository->unresolvedCount($vendorId));
        }
    }

    /** @return array{success: bool, message: string, status_code: int} */
    public function startScan(): array
    {
        $vendorId = $this->vendorId();
        if ($vendorId === '' || !syncBasalamSettings()->hasToken()) {
            return [
                'success' => false,
                'message' => 'برای بررسی محصولات، ابتدا اتصال افزونه به غرفهٔ باسلام را کامل کنید.',
                'status_code' => 409,
            ];
        }

        $this->reconcile();

        $state = $this->state();
        if (!$state->canStart()) {
            return ['success' => false, 'message' => $state->blockReason(), 'status_code' => 409];
        }

        if ($this->hasActiveScanJob()) {
            return ['success' => false, 'message' => 'بررسی محصولات در حال اجراست؛ تا پایان آن صبر کنید.', 'status_code' => 409];
        }

        UnmappedProductsRepository::ensureTable();
        $this->repository->clearForVendor($vendorId);
        $state->markScanning();

        $created = $this->jobManager->createJob(FindUnmappedProductsJob::TYPE, 'pending', json_encode([
            'vendor_id' => $vendorId,
            'cursor' => null,
        ]));

        if ($created === false) {
            $state->markIdle();

            return ['success' => false, 'message' => 'خطا در شروع فرایند بررسی محصولات.', 'status_code' => 500];
        }

        return ['success' => true, 'message' => 'بررسی محصولات غرفه آغاز شد؛ نتیجه پس از پایان اطلاع داده می‌شود.', 'status_code' => 200];
    }

    /** @return array{success: bool, message: string, status_code: int} */
    public function startBulkArchive(): array
    {
        $vendorId = $this->vendorId();
        $this->reconcile();

        if (!$this->state()->isAwaitingResolution() || !$this->repository->hasOpen($vendorId)) {
            return ['success' => false, 'message' => 'محصول باز برای آرشیو وجود ندارد.', 'status_code' => 409];
        }

        if ($this->hasActiveArchiveJob()) {
            return ['success' => false, 'message' => 'فرایند آرشیو گروهی هم‌اکنون در صف است.', 'status_code' => 409];
        }

        if (!$this->state()->setArchiveStopRequested(false)) {
            return ['success' => false, 'message' => 'آماده‌سازی آرشیو گروهی ناموفق بود؛ دوباره تلاش کنید.', 'status_code' => 500];
        }

        $created = $this->jobManager->createJob(ArchiveUnmappedProductsJob::TYPE, 'pending', json_encode([
            'vendor_id' => $vendorId,
        ]));

        if ($created === false) {
            return ['success' => false, 'message' => 'خطا در شروع فرایند آرشیو گروهی.', 'status_code' => 500];
        }

        return ['success' => true, 'message' => 'آرشیو همهٔ محصولات متصل‌نشده در صف قرار گرفت.', 'status_code' => 200];
    }

    /** Stop cooperatively: keep the active job visible until its current request settles. */
    public function stopBulkArchive(): array
    {
        if (!$this->hasActiveArchiveJob()) {
            return ['success' => true, 'message' => 'آرشیو گروهی در حال اجرا نیست.', 'status_code' => 200];
        }

        if ($this->vendorId() === '' || !$this->state()->setArchiveStopRequested(true)) {
            return ['success' => false, 'message' => 'ثبت درخواست توقف آرشیو ناموفق بود؛ دوباره تلاش کنید.', 'status_code' => 500];
        }

        // A delayed retry only needs to acknowledge the stop, without contacting the provider.
        $this->jobManager->updateJob(['retry_after' => 0], [
            'job_type' => ArchiveUnmappedProductsJob::TYPE,
            'status' => 'pending',
        ]);

        return ['success' => true, 'message' => 'درخواست توقف ثبت شد؛ پس از پایان درخواست جاری، آرشیو متوقف می‌شود.', 'status_code' => 200];
    }

    /** خلاصهٔ وضعیت برای polling و رندر صفحه/نوتیس. */
    public function statusSummary(): array
    {
        $vendorId = $this->vendorId();
        $state = $this->state()->get();
        $counts = $vendorId !== '' ? $this->repository->counts($vendorId) : ['total' => 0, 'unresolved' => 0, 'open' => 0, 'archiving' => 0, 'archived' => 0, 'connected' => 0, 'ignored' => 0, 'failed' => 0];
        $archiveActive = $this->hasActiveArchiveJob();
        // پیشرفت گزارش از وضعیت‌های پایدار خوانده می‌شود؛ retry و بارگذاری مجدد
        // شمارنده را دوباره زیاد نمی‌کنند. موارد متصل/نادیده‌گرفته‌شده هدف آرشیو نیستند.
        $archiveTotal = (int) $counts['archived'] + (int) $counts['unresolved'];

        return [
            'status' => $state['status'],
            'started_at' => (int) $state['started_at'],
            'finished_at' => (int) $state['finished_at'],
            'total_found' => (int) $state['total_found'],
            'scanned' => (int) ($state['scanned'] ?? 0),
            'counts' => $counts,
            'archive_active' => $archiveActive,
            'archive_stopping' => $archiveActive && !empty($state['archive_stop_requested']),
            'archive_progress' => [
                'archived' => (int) $counts['archived'],
                'total' => $archiveTotal,
                'percent' => $archiveTotal > 0 ? (int) floor(100 * $counts['archived'] / $archiveTotal) : 0,
                'failed' => (int) $counts['failed'],
                'recent' => $archiveActive && $vendorId !== '' ? $this->repository->latestArchived($vendorId) : [],
            ],
        ];
    }

    /** پس از هر اقدام روی یک ردیف، اگر چیزی باز نمانده، قفل بررسی آزاد می‌شود. */
    public function releaseLockIfResolved(): bool
    {
        $vendorId = $this->vendorId();
        if ($vendorId === '') return false;

        return $this->state()->releaseIfResolved($this->repository->unresolvedCount($vendorId));
    }

    private function hasActiveJob(string $jobType): bool
    {
        // یک کوئری واحد: دو SELECT جدا بین گذار processing→pendingِ یک retry
        // می‌تواند job زنده را نبیند و reconcile قفل را به‌اشتباه باز کند.
        return $this->jobManager->getCountJobs([
            'job_type' => $jobType,
            'status' => ['pending', 'processing'],
        ]) > 0;
    }
}
