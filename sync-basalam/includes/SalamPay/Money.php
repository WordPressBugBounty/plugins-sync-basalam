<?php

namespace SyncBasalam\SalamPay;

defined('ABSPATH') || exit;

final class Money
{
    private const FACTORS = ['IRR' => 1, 'IRT' => 10, 'TOMAN' => 10, 'IRHR' => 1000, 'IRHT' => 10000];

    public static function supports(string $currency): bool
    {
        return isset(self::FACTORS[strtoupper($currency)]);
    }

    /** Reject unknown currencies and negative/overflowing values instead of absint. */
    public static function rial($amount, string $currency): int
    {
        $factor = self::FACTORS[strtoupper($currency)] ?? 0;
        if (!$factor || !is_numeric($amount) || !is_finite((float) $amount)
            || (float) $amount < 0 || (float) $amount > (PHP_INT_MAX / $factor) - 1) {
            throw new \InvalidArgumentException('Invalid Salam Pay amount or currency.');
        }
        return (int) round((float) $amount * $factor, 0, PHP_ROUND_HALF_UP);
    }

    public static function fromRial(int $amount, string $currency): string
    {
        $factor = self::FACTORS[strtoupper($currency)] ?? 0;
        if (!$factor || $amount < 0) {
            throw new \InvalidArgumentException('Invalid Salam Pay currency.');
        }
        return wc_format_decimal($amount / $factor, false);
    }
}
