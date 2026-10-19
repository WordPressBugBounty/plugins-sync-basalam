<?php

namespace SyncBasalam\SalamGate\Services;

use SyncBasalam\SalamGate\Contracts\ShopStatuses;
use SyncBasalam\SalamGate\SalamGateException;

defined('ABSPATH') || exit;

/** Site opt-out, independent of the provider's paid registration and OAuth credentials. */
final class SiteActivation
{
    public const OPTION_KEY = 'woosalam_salam_gate_site_enabled';

    public static function isEnabled(): bool
    {
        return in_array(get_option(self::OPTION_KEY, true), [true, 1, '1'], true);
    }

    public static function setEnabled(bool $enabled): void
    {
        if ($enabled) {
            // Reuse an existing, verified registration. Never register a new shop
            // or submit an activation payment to turn this site's feature back on.
            $identity = ConnectionCache::identityHash();
            $shops = (new ShopsService())->getShops(true);
            ConnectionCache::assertCurrentIdentity($identity);
            $active = array_filter($shops, static fn (array $shop): bool =>
                (int) ($shop['status_id'] ?? 0) === ShopStatuses::ACTIVE && (int) ($shop['id'] ?? 0) > 0
            );
            if ($active === []) {
                throw new SalamGateException(
                    'حساب پستی فعالی تأیید نشد. وضعیت حساب سلام‌گیت را بررسی کنید؛ ثبت یا پرداخت جدیدی انجام نشده است.',
                    422, [], SalamGateException::KIND_VALIDATION, true
                );
            }
        }

        update_option(self::OPTION_KEY, $enabled ? 1 : 0, false);
        if (self::isEnabled() !== $enabled) {
            throw new SalamGateException('ذخیره وضعیت سلام‌گیت انجام نشد؛ دوباره تلاش کنید.',
                0, [], SalamGateException::KIND_UNEXPECTED_RESPONSE, true);
        }
        // Do not clear saved sync preferences, postal receipts, drafts, shops,
        // account credentials or the provider's registration/payment records.
        StatusSyncScheduler::reconcile();
    }

    public static function assertEnabled(): void
    {
        if (!self::isEnabled()) {
            throw new SalamGateException('سلام‌گیت در این سایت غیرفعال است؛ ابتدا از بخش ارسال مرسوله آن را فعال کنید.',
                403, [], SalamGateException::KIND_FORBIDDEN, true);
        }
    }
}
