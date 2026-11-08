<?php
namespace SyncBasalam\SalamGate\Application;

use SyncBasalam\SalamGate\Parcel\QuoteNormalizer;
use SyncBasalam\SalamGate\Services\ParcelService;
use SyncBasalam\SalamGate\SalamGateException;

defined('ABSPATH') || exit;

final class PaymentQuotes
{
    public static function checked($body, int $parcelId): array
    {
        if (!is_array($body) || ParcelService::positiveId($body['parcel_id'] ?? null) !== $parcelId
            || !is_array($body['quotes'] ?? null)
        ) {
            throw self::invalid();
        }
        foreach ($body['quotes'] as $quote) {
            if (!is_array($quote) || !is_bool($quote['selected'] ?? null)) {
                throw self::invalid();
            }
            if (isset($quote['error']) && !is_string($quote['error'])) {
                throw self::invalid();
            }
            if (($quote['error'] ?? null) !== null && ($quote['error'] ?? '') !== '') {
                if ($quote['selected']) {
                    throw self::invalid();
                }
                continue;
            }
            if (!is_int($quote['service_type']['id'] ?? null) || $quote['service_type']['id'] < 1
                || !is_string($quote['hash_id'] ?? null) || $quote['hash_id'] === '' || strlen($quote['hash_id']) > 256
                || !is_int($quote['costs']['total'] ?? null) || $quote['costs']['total'] < 0
            ) {
                throw self::invalid();
            }
        }
        $quotes = QuoteNormalizer::normalize($body);
        if (count(array_filter($quotes['quotes'], fn (array $q): bool => $q['selected'])) > 1) {
            throw self::invalid();
        }
        return $quotes;
    }

    private static function invalid(): SalamGateException
    {
        return new SalamGateException('پاسخ نرخ ارسال معتبر نیست؛ انتخاب و مبلغ تأیید نشد.', 200, [], SalamGateException::KIND_UNEXPECTED_RESPONSE);
    }
}
