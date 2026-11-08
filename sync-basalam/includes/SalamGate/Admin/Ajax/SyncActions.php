<?php

namespace SyncBasalam\SalamGate\Admin\Ajax;

use SyncBasalam\SalamGate\Services\StatusSyncScheduler;
use SyncBasalam\SalamGate\Services\StatusSyncService;
use SyncBasalam\SalamGate\Settings\SalamGateSettings;

defined('ABSPATH') || exit;

/** Local sync preferences and read-only remote reconciliation; no financial POST. */
final class SyncActions
{
    public static function save(): void
    {
        self::guard();
        $enabled = isset($_POST['enabled']) && is_scalar($_POST['enabled']) ? (string) $_POST['enabled'] : '';
        $interval = isset($_POST['interval']) && is_string($_POST['interval']) ? wp_unslash($_POST['interval']) : '';
        if (!in_array($enabled, ['0', '1'], true) || !in_array($interval, SalamGateSettings::SYNC_INTERVALS, true)) {
            ResponseBoundary::error(['message' => 'تنظیمات همگام‌سازی نامعتبر است.'], 400);
            return;
        }

        SalamGateSettings::instance()->save([
            SalamGateSettings::KEY_STATUS_SYNC_ENABLED => (int) $enabled,
            SalamGateSettings::KEY_STATUS_SYNC_INTERVAL => $interval,
        ]);
        StatusSyncScheduler::reconcile();
        ResponseBoundary::success(['message' => 'تنظیمات همگام‌سازی ذخیره شد.']);
    }

    public static function run(): void
    {
        self::guard();
        $result = (new StatusSyncService())->syncBatch();
        if ($result['skipped'] !== '') {
            ResponseBoundary::error(['message' => $result['skipped'] === 'locked'
                ? 'همگام‌سازی دیگری در حال اجراست؛ کمی بعد بررسی کنید.'
                : 'همگام‌سازی کامل نشد؛ اتصال و گزارش اجرا را بررسی کنید.', 'stats' => $result]);
            return;
        }
        ResponseBoundary::success(['message' => 'بررسی وضعیت مرسوله‌ها انجام شد.', 'stats' => $result]);
    }

    private static function guard(): void
    {
        ActionGuard::forController(self::class, null);
    }
}
