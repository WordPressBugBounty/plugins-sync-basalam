<?php

namespace SyncBasalam\SalamGate\Parcel;

use SyncBasalam\SalamGate\Utils\Units;

defined('ABSPATH') || exit;

/**
 * نرمال‌سازی پاسخ نرخ‌های مرسوله به ساختار UI (خالص و تست‌پذیر).
 */
class QuoteNormalizer
{
    /**
     * شناسه سرویس پست → برچسب فارسی.
     */
    public const SERVICE_TYPE_LABELS = [
        1 => 'پیشتاز',
        3 => 'ویژه',
        5 => 'اکسپرس',
    ];

    /**
     * @param array $body پاسخ GET/PUT /v1/parcels/{id}/quotes
     *
     * @return array{parcel_id: ?int, quotes: array<int, array>, errors: array<int, string>}
     */
    public static function normalize(array $body): array
    {
        $quotes = [];

        foreach (($body['quotes'] ?? []) as $quote) {
            if (!is_array($quote)) {
                continue;
            }

            $costs = is_array($quote['costs'] ?? null) ? $quote['costs'] : [];
            $serviceTypeId = (int) ($quote['service_type']['id'] ?? 0);
            $total = self::rialAmount($costs['total'] ?? null);
            $rawCost = self::rialAmount($costs['raw_cost'] ?? null);
            $baseCost = self::rialAmount($costs['base_cost'] ?? null);
            $additionalTotal = self::rialAmount($costs['additional']['total'] ?? null);

            $quotes[] = [
                'hash_id'           => (string) ($quote['hash_id'] ?? ''),
                'service_type_id'   => $serviceTypeId,
                'service_type_label' => self::SERVICE_TYPE_LABELS[$serviceTypeId] ?? 'سرویس پستی',
                'service_name'      => (string) ($quote['service_name'] ?? ''),
                'selected'          => !empty($quote['selected']),
                'error'             => isset($quote['error']) && $quote['error'] !== null ? (string) $quote['error'] : null,
                // costs.total is the service's final payable total, including provider/coupon discounts.
                'total_rial'        => $total,
                'total_toman'       => $total !== null ? Units::toToman($total) : null,
                'raw_cost_toman'    => $rawCost !== null ? Units::toToman($rawCost) : null,
                'discount_percent'  => $costs['discount_percent'] ?? null,
                // Preserve the native invoice sections; payable total is never recomputed from rows.
                'base_cost_toman'   => $baseCost !== null ? Units::toToman($baseCost) : null,
                'fare_cost_toman'   => self::fareCost($costs),
                'discount_rows'    => self::normalizeSectionRows($costs, 'discount'),
                'additional_rows'  => self::normalizeSectionRows($costs, 'additional'),
                'additional_total_toman' => $additionalTotal !== null ? Units::toToman($additionalTotal) : null,
                'cost_rows'         => self::normalizeCostRows($costs),
            ];
        }

        $errors = [];
        foreach (($body['errors'] ?? []) as $error) {
            if (is_array($error) && !empty($error['message'])) {
                $errors[] = (string) $error['message'];
            } elseif (is_string($error) && $error !== '') {
                $errors[] = $error;
            }
        }

        return [
            'parcel_id' => isset($body['parcel_id']) ? (int) $body['parcel_id'] : null,
            'quotes'    => $quotes,
            'errors'    => $errors,
        ];
    }

    /**
     * سطرهای ریز هزینه (تخفیف منفی/بیمه و مالیات مثبت) برای نمایش.
     *
     * @return array<int, array{title: string, amount_toman: int|float}>
     */
    private static function normalizeCostRows(array $costs): array
    {
        return array_merge(self::normalizeSectionRows($costs, 'discount'), self::normalizeSectionRows($costs, 'additional'));
    }

    private static function normalizeSectionRows(array $costs, string $section): array
    {
        $rows = [];
        foreach ((array) ($costs[$section]['items'] ?? []) as $item) {
            if (!is_array($item) || !isset($item['amount'])) {
                continue;
            }
            $amount = self::rialAmount($item['amount'], true);
            if ($amount === null) { continue; }
            $rows[] = [
                'title'        => (string) ($item['title'] ?? ($item['key'] ?? '')),
                'amount_toman' => Units::toToman($amount),
            ];
        }
        return $rows;
    }

    /** Provider discounts start from raw fare; coupons replace them and start from base fare. */
    private static function fareCost(array $costs): int|float|null
    {
        $coupon = false;
        foreach ((array) ($costs['discount']['items'] ?? []) as $item) {
            if (is_array($item) && ($item['key'] ?? '') === 'coupon_discount') { $coupon = true; break; }
        }
        $fare = self::rialAmount($coupon ? ($costs['base_cost'] ?? null) : ($costs['raw_cost'] ?? $costs['base_cost'] ?? null));
        return $fare !== null ? Units::toToman($fare) : null;
    }

    /** Malformed money is unavailable, never a rounded or fabricated payable amount. */
    private static function rialAmount($value, bool $signed = false): ?int
    {
        if (!is_int($value) && !(is_string($value) && preg_match($signed ? '/^-?(0|[1-9]\d*)$/D' : '/^(0|[1-9]\d*)$/D', $value))) {
            return null;
        }
        $amount = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $signed ? -min(PHP_INT_MAX, 9007199254740991) : 0,
            'max_range' => min(PHP_INT_MAX, 9007199254740991)]]);
        return $amount === false ? null : $amount;
    }
}
