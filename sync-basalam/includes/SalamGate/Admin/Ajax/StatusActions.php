<?php

namespace SyncBasalam\SalamGate\Admin\Ajax;

use SyncBasalam\SalamGate\Admin\AccountStateRouter;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\ReferenceDataService;
use SyncBasalam\SalamGate\Services\ShopsService;
use SyncBasalam\SalamGate\Utils\Digits;

if (! defined('ABSPATH')) exit;

/**
 * اکشن‌های AJAX وضعیت حساب سلام‌گیت.
 */
class StatusActions
{
    public static function siteActivation(): void
    {
        self::guard();
        $enabled = $_POST['enabled'] ?? null;
        if (!is_string($enabled) || !in_array($enabled, ['0', '1'], true)) {
            ResponseBoundary::error(['message' => 'وضعیت درخواستی معتبر نیست.'], 422);
        }
        try {
            \SyncBasalam\SalamGate\Services\SiteActivation::setEnabled($enabled === '1');
        } catch (SalamGateException $exception) {
            ResponseBoundary::error(['message' => $exception->firstMessage()], 422);
        } catch (\Throwable $exception) {
            ResponseBoundary::error(['message' => 'تغییر وضعیت انجام نشد؛ دوباره تلاش کنید. ثبت یا پرداخت جدیدی انجام نشده است.'], 503);
        }
        ResponseBoundary::success(['enabled' => $enabled === '1', 'redirect' => admin_url('admin.php?page=salam_gate')]);
    }

    /**
     * وضعیت حساب + HTML جزء وضعیت — برای «بررسی مجدد» بدون رفرش کامل صفحه (پارت ۳).
     */
    public static function accountState(): void
    {
        self::guard();

        $fresh = isset($_POST['fresh']) && $_POST['fresh'] === '1';

        try {
            $data = AccountStateRouter::resolveCurrent($fresh);
            ResponseBoundary::success([
                'state' => $data['state'],
                'message' => (string) ($data['message'] ?? ''),
                'html'  => AccountStateRouter::renderStatePartial($data),
            ]);
        } catch (\Throwable $exception) {
            ResponseBoundary::error(['message' => 'بررسی وضعیت سلام‌گیت ناموفق بود؛ کمی بعد دوباره تلاش کنید.']);
        }
    }

    public static function refreshShops(): void
    {
        self::guard();
        // Also protect direct/internal invocations; an old tab is not consent
        // to query the provider after this site has explicitly opted out.
        if (!\SyncBasalam\SalamGate\Services\SiteActivation::isEnabled()) {
            ResponseBoundary::error(['message' => 'سلام‌گیت در این سایت غیرفعال است؛ ابتدا آن را از بخش ارسال مرسوله فعال کنید.',
                'code' => 'site_disabled', 'kind' => 'validation', 'errors' => [], 'request_dispatched' => false], 409);
            return;
        }

        try {
            $service = new ShopsService();
            $service->flushCache();
            $shops = $service->getShops(true);

            ResponseBoundary::success(['shops' => $shops]);
        } catch (SalamGateException $exception) {
            ResponseBoundary::error(['message' => $exception->firstMessage()]);
        }
    }

    public static function activateShop(): void
    {
        self::shopAction('activate');
    }

    public static function deactivateShop(): void
    {
        self::shopAction('deactivate');
    }

    /**
     * حذف کش مراجع (شهرها/بسته‌ها) برای بروزرسانی مجدد.
     */
    public static function flushReferences(): void
    {
        self::guard();

        try {
            (new ReferenceDataService())->flush();
            ResponseBoundary::success(['message' => 'مراجع بروزرسانی شد.']);
        } catch (\Throwable $exception) {
            ResponseBoundary::error(['message' => 'خطا در بروزرسانی مراجع.']);
        }
    }

    private static function shopAction(string $action): void
    {
        self::guard();

        $rawId = isset($_POST['shop_id']) && is_scalar($_POST['shop_id'])
            ? Digits::toEnglish(trim((string) wp_unslash($_POST['shop_id'])))
            : '';
        $shopId = preg_match('/^\d+$/', $rawId) ? (int) $rawId : 0;

        if ($shopId <= 0) {
            ResponseBoundary::error(['message' => 'شناسه غرفه نامعتبر است.']);
        }

        try {
            (new ShopsService())->{$action}($shopId);

            ResponseBoundary::success(['message' => $action === 'activate' ? 'غرفه با موفقیت فعال شد.' : 'غرفه با موفقیت غیرفعال شد.']);
        } catch (SalamGateException $exception) {
            ResponseBoundary::error(['message' => $exception->firstMessage()]);
        }
    }

    /**
     * الگوی verifyNonce موجود: قابلیت + nonce با عملکرد basalam_pro_nonce.
     */
    private static function guard(): void
    {
        ActionGuard::forController(self::class, null);
    }
}
