<?php

namespace SyncBasalam\Services\Products\UnmappedProducts;

use SyncBasalam\Admin\Settings\SettingsConfig;

defined('ABSPATH') || exit;

/**
 * فلگ و چرخهٔ عمر بررسی محصولات متصل‌نشدهٔ باسلام.
 *
 * idle → scanning → awaiting_resolution → idle
 *
 * تا وقتی گزارش قبلی برای همهٔ محصولات تعیین‌تکلیف نشده (آرشیو/اتصال/نادیده‌گرفتن)،
 * اجرای دوباره — چه خودکار و چه دستی — مجاز نیست.
 */
class UnmappedScanState
{
    public const OPTION = 'sync_basalam_unmapped_scan_state';

    public const STATUS_IDLE = 'idle';
    public const STATUS_SCANNING = 'scanning';
    public const STATUS_AWAITING = 'awaiting_resolution';

    private $vendorId;

    public function __construct($vendorId = null)
    {
        $this->vendorId = $vendorId !== null
            ? (string) $vendorId
            : (string) syncBasalamSettings()->getSettings(SettingsConfig::VENDOR_ID);
    }

    public function vendorId(): string
    {
        return $this->vendorId;
    }

    /** وضعیت ذخیره‌شده؛ گزارش غرفهٔ دیگر برای غرفهٔ فعلی معتبر نیست. */
    public function get(): array
    {
        // این option بین چند پروسهٔ هم‌زمان (AJAX ادمین و worker صف) هماهنگی
        // می‌کند؛ کش درون‌پروسه‌ای options در یک batch طولانی worker مقدار کهنه
        // برمی‌گرداند و مثلا scanning تازه را idle می‌بیند و زنجیرهٔ اسکن را
        // بی‌صدا skip می‌کند. قبل از هر خواندن، کش همین کلید دور ریخته می‌شود.
        if (function_exists('wp_cache_delete')) {
            \wp_cache_delete(self::OPTION, 'options');
        }

        $stored = \get_option(self::OPTION, []);
        if (!is_array($stored)) $stored = [];

        $state = array_merge($this->defaults(), $stored);

        if ((string) $state['vendor_id'] !== $this->vendorId) {
            return $this->defaults();
        }

        if (!in_array($state['status'], [self::STATUS_IDLE, self::STATUS_SCANNING, self::STATUS_AWAITING], true)) {
            $state['status'] = self::STATUS_IDLE;
        }

        return $state;
    }

    public function status(): string
    {
        return $this->get()['status'];
    }

    public function isScanning(): bool
    {
        return $this->status() === self::STATUS_SCANNING;
    }

    public function isAwaitingResolution(): bool
    {
        return $this->status() === self::STATUS_AWAITING;
    }

    public function isArchiveStopRequested(): bool
    {
        return !empty($this->get()['archive_stop_requested']);
    }

    /** Keep the stop request durable until the queue drains and a new run starts. */
    public function setArchiveStopRequested(bool $requested): bool
    {
        $state = $this->get();
        if ((bool) $state['archive_stop_requested'] === $requested) return true;

        $state['archive_stop_requested'] = $requested;
        return \update_option(self::OPTION, $state, false)
            || $this->isArchiveStopRequested() === $requested;
    }

    /** اسکن تازه فقط وقتی مجاز است که نه اسکنی در جریان باشد و نه گزارش بازی مانده باشد. */
    public function canStart(): bool
    {
        return $this->status() === self::STATUS_IDLE;
    }

    public function blockReason(): string
    {
        $status = $this->status();
        if ($status === self::STATUS_SCANNING) {
            return 'بررسی محصولات در حال اجراست؛ تا پایان آن صبر کنید.';
        }
        if ($status === self::STATUS_AWAITING) {
            return 'نتیجهٔ بررسی قبلی هنوز برای همهٔ محصولات اعمال نشده است. ابتدا محصولات باقی‌مانده را آرشیو، متصل یا نادیده بگیرید.';
        }

        return '';
    }

    public function markScanning(): void
    {
        $this->save([
            'status' => self::STATUS_SCANNING,
            'vendor_id' => $this->vendorId,
            'started_at' => time(),
            'finished_at' => 0,
            'total_found' => 0,
            'scanned' => 0,
        ]);
    }

    /** پیشرفت زنده برای نمایش در صفحه؛ بعد از هر صفحهٔ پیمایش‌شده صدا زده می‌شود. */
    public function recordProgress(int $scannedDelta, int $foundDelta): void
    {
        $state = $this->get();
        if ($state['status'] !== self::STATUS_SCANNING) return;

        $state['scanned'] = (int) $state['scanned'] + max(0, $scannedDelta);
        $state['total_found'] = (int) $state['total_found'] + max(0, $foundDelta);

        $this->save($state);
    }

    public function markAwaitingResolution(int $totalFound): void
    {
        $state = $this->get();
        $this->save([
            'status' => self::STATUS_AWAITING,
            'vendor_id' => $this->vendorId,
            'started_at' => (int) $state['started_at'],
            'finished_at' => time(),
            'total_found' => $totalFound,
            'scanned' => (int) $state['scanned'],
        ]);
    }

    public function markIdle(): void
    {
        $state = $this->get();
        $this->save([
            'status' => self::STATUS_IDLE,
            'vendor_id' => $this->vendorId,
            'started_at' => (int) $state['started_at'],
            'finished_at' => (int) $state['finished_at'] ?: time(),
            'total_found' => (int) $state['total_found'],
            'scanned' => (int) $state['scanned'],
        ]);
    }

    /** وقتی همهٔ آیتم‌های باز بسته شدند، قفل اجرای مجدد برداشته می‌شود. */
    public function releaseIfResolved(int $unresolvedCount): bool
    {
        if ($unresolvedCount > 0) return false;
        if ($this->status() !== self::STATUS_AWAITING) return false;

        $this->markIdle();

        return true;
    }

    /**
     * وضعیت «در حال بررسی» بدون job زنده یعنی زنجیره شکست خورده؛ قفل باید باز شود
     * تا زمان‌بندی صف یا کاربر بتواند بررسی را دوباره شروع کند.
     */
    public function reconcileWithQueue(bool $hasActiveScanJob): void
    {
        if ($this->status() === self::STATUS_SCANNING && !$hasActiveScanJob) {
            $this->markIdle();
        }
    }

    private function save(array $state): void
    {
        \update_option(self::OPTION, $state, false);
    }

    private function defaults(): array
    {
        return [
            'status' => self::STATUS_IDLE,
            'vendor_id' => $this->vendorId,
            'started_at' => 0,
            'finished_at' => 0,
            'total_found' => 0,
            'scanned' => 0,
            'archive_stop_requested' => false,
        ];
    }
}
