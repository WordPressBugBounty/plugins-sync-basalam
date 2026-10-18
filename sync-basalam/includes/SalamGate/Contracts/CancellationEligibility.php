<?php

namespace SyncBasalam\SalamGate\Contracts;

defined('ABSPATH') || exit;

/** Mirrors CancelParcelController; carrier acceptance is always checked upstream. */
final class CancellationEligibility
{
    public const MARKETS = ['website', 'instagram', 'telegram', 'eitaa', 'bale', 'digikala', 'mixin', 'other'];

    public static function canCancel(array $parcel): bool
    {
        return is_int($parcel['id'] ?? null) && $parcel['id'] > 0
            && in_array($parcel['market'] ?? '', self::MARKETS, true)
            && ($parcel['status_id'] ?? null) === ParcelStatuses::POSTAL_BARCODE
            && ($parcel['invoice_status'] ?? null) === 2
            && is_int($parcel['invoice_id'] ?? null) && $parcel['invoice_id'] > 0
            && is_string($parcel['tracking_code'] ?? null) && trim($parcel['tracking_code']) !== '';
    }
}
