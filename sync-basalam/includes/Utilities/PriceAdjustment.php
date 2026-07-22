<?php

namespace SyncBasalam\Utilities;

defined('ABSPATH') || exit;

/**
 * A price adjustment value is one of:
 * - 'commission' : calculated from the Basalam category commission
 * - -100 to 100  : a percentage (negative means a price reduction; capped at 35% either way)
 * - outside that range : a fixed amount in Toman (negative means a reduction)
 */
class PriceAdjustment
{
    public const COMMISSION = 'commission';

    /** Range in which a value is read as a percentage; outside it the value is a fixed Toman amount. */
    public const PERCENT_RANGE_MIN = -100;
    public const PERCENT_RANGE_MAX = 100;

    /** Maximum allowed increase and decrease percentage. */
    public const MAX_PERCENT = 35;
    public const MIN_PERCENT = -35;

    public static function isCommission($value): bool
    {
        return $value === self::COMMISSION;
    }

    public static function isPercent($value): bool
    {
        return is_numeric($value) && intval($value) >= self::PERCENT_RANGE_MIN && intval($value) <= self::PERCENT_RANGE_MAX;
    }

    /**
     * Turns a raw input value into a storable value.
     *
     * @return string|null null means the value is empty or invalid
     */
    public static function normalize($value): ?string
    {
        if (self::isCommission($value)) return self::COMMISSION;

        if ($value === '' || $value === null || !is_numeric($value)) return null;

        return (string) self::clamp(intval($value));
    }

    /**
     * Clamps percentages to the allowed -35..35 range; fixed Toman amounts are left untouched.
     */
    public static function clamp(int $value): int
    {
        if (!self::isPercent($value)) return $value;

        if ($value > self::MAX_PERCENT) return self::MAX_PERCENT;
        if ($value < self::MIN_PERCENT) return self::MIN_PERCENT;

        return $value;
    }

    public static function unitLabel($value): string
    {
        if (self::isCommission($value) || !is_numeric($value)) return 'درصد';

        return self::isPercent($value) ? 'درصد' : 'تومان';
    }
}
