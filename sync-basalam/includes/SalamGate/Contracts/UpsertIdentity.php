<?php

namespace SyncBasalam\SalamGate\Contracts;

if (! defined('ABSPATH')) exit;

/**
 * قواعد هویت مرسوله در upsert (POST /v1/markets/{market}/parcels):
 *
 * - بازار باسلام: external_id شناسه مرسوله غرفه است؛ invoice_id شناسه سفارش است و جایگزین آن نمی‌شود.
 * - بازارهای دیگر (وب‌سایت و …): در ساخت external_id اختیاری است (نگاشت سفارش وو)؛
 *   در ویرایش باید شناسه سلام‌گیت (id) ارسال شود.
 * - id و external_id هیچ‌گاه با هم ارسال نمی‌شوند (در قرارداد تک‌تک mutually exclusive).
 *
 * منبع: upsert_parcel_controller.py + parcel_external_id_required_validator.py
 */
final class UpsertIdentity
{
    /**
     * هویت ساخت مرسوله.
     *
     * @return array<string, int> یکی از ['external_id'=>int] یا [] (بازار غیر بسالم بدون نگاشت)
     */
    public static function forCreate(string $market, ?int $externalId): array
    {
        if ($externalId !== null && $externalId > 0) {
            return ['external_id' => $externalId];
        }

        return [];
    }

    /**
     * هویت ویرایش مرسوله موجود.
     *
     * @return array<string, int> ['external_id'=>..] برای بسالم؛ ['id'=>..] برای بقیه بازارها
     */
    public static function forUpdate(string $market, int $parcelId, int $externalId): array
    {
        if ($market === 'basalam') {
            return ['external_id' => $externalId];
        }

        return ['id' => $parcelId];
    }

    /**
     * دقیقاً یکی از id/external_id — نه هر دو، نه هیچ‌کدام.
     */
    public static function isValidIdentity(array $identity): bool
    {
        $hasId = array_key_exists('id', $identity) && (int) $identity['id'] > 0;
        $hasExternal = array_key_exists('external_id', $identity) && (int) $identity['external_id'] > 0;

        return $hasId xor $hasExternal;
    }
}
