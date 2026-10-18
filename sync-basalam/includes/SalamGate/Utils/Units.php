<?php

namespace SyncBasalam\SalamGate\Utils;

defined('ABSPATH') || exit;

/**
 * تبدیل واحدها — دو مسیر پولی مستقل که هرگز با هم اشتباه گرفته نمی‌شوند:
 *
 * ۱) ورودی فرم با واحد صریح تومان → همیشه ×۱۰ (فرقی نمی‌کند ارز فروشگاه چیست).
 * ۲) مبلغ سفارش ووکامرس → از ارز ذخیره‌شده همان سفارش (IRR=ریال ×۱، IRT=تومان ×۱۰).
 *    ارزهای غیر ایرانی نرخ ارز ندارند → null (تبدیل ساختگی ممنوع).
 *
 * همه محاسبات پول با عدد صحیح انجام می‌شود (ریال، بدون float).
 * وزن ووکامرس به گرم و ابعاد به سانتی‌متر تبدیل می‌شوند.
 * کدپستی/موبایل/کدملی رشته هستند (صفر ابتدا حفظ می‌شود) — اینجا عدد نمی‌شوند.
 */
final class Units
{
    /**
     * ورودی فرم (تومان) → ریال سرویس. همیشه ×۱۰.
     * ورودی باید قبلاً با Digits::toEnglish نرمال شده باشد.
     */
    public static function formTomanToRial($toman): int
    {
        $toman = is_string($toman) ? (int) preg_replace('/[^\d\-]/', '', $toman) : (int) $toman;

        return $toman * 10;
    }

    /**
     * ریال سرویس → تومان برای نمایش.
     */
    public static function toToman(int $rial): int|float
    {
        // Keep the payable amount exact, including its last rial after a discount.
        return $rial % 10 === 0 ? intdiv($rial, 10) : $rial / 10;
    }

    /**
     * نمایش تومان با جداکننده هزارگان و ارقام فارسی.
     */
    public static function formatToman(int|float $toman): string
    {
        // Display only: preserve exact rial/toman values in conversions and payment.
        return Digits::toPersian(number_format(floor($toman), 0));
    }

    /** Persian/Arabic digits and presentation-only grouping must not truncate submitted prices. */
    public static function parseFormAmount(string $value): int
    {
        $value = preg_replace('/[,٬،\s\x{00a0}]/u', '', Digits::toEnglish(trim($value)));
        if (!is_string($value) || !preg_match('/^(0|[1-9]\d*)$/D', $value)) { return 0; }
        $amount = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => intdiv(PHP_INT_MAX, 10)]]);
        return $amount === false ? 0 : $amount;
    }

    /** Reject explicit malformed input before casts/defaults can change the user's value. */
    public static function parseBoundedFormInteger($value, int $minimum, int $maximum, bool $grouped = false): ?int
    {
        if (!is_string($value) && !is_int($value)) { return null; }
        $value = Digits::toEnglish(trim((string) $value));
        if ($grouped) { $value = preg_replace('/[,٬،\s\x{00a0}]/u', '', $value); }
        if (!is_string($value) || !preg_match('/^\d+$/D', $value)) { return null; }
        $value = ltrim($value, '0');
        $value = $value === '' ? '0' : $value;
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum, 'max_range' => $maximum]]);
        return $number === false ? null : $number;
    }

    /** Exact declared-value form conversion: one decimal toman is one integer rial. */
    public static function parseFormTomanToRial($value, int $minimumRial, int $maximumRial): ?int
    {
        if ((!is_string($value) && !is_int($value)) || $minimumRial < 0 || $maximumRial < $minimumRial) {
            return null;
        }
        $value = str_replace('٫', '.', Digits::toEnglish(trim((string) $value)));
        $value = preg_replace('/[,٬،\s\x{00a0}]/u', '', $value);
        if (!is_string($value) || !preg_match('/^(\d+)(?:\.(\d))?$/D', $value, $parts)) { return null; }
        $whole = ltrim($parts[1], '0');
        $whole = filter_var($whole === '' ? '0' : $whole, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => intdiv(PHP_INT_MAX, 10)]]);
        if ($whole === false) { return null; }
        $fraction = isset($parts[2]) ? (int) $parts[2] : 0;
        $rial = $whole * 10;
        if ($rial > PHP_INT_MAX - $fraction) { return null; }
        $rial += $fraction;
        return $rial >= $minimumRial && $rial <= $maximumRial ? $rial : null;
    }

    /**
     * مبلغ سفارش ووکامرس (با ارز خودِ سفارش) → ریال.
     *
     * @param float|int|string $amount مبلغ خام سفارش/قلم
     * @param string $currency کد ارز ووکامرس سفارش (IRR/IRT/…)
     *
     * @return int|null null = ارز پشتیبانی‌نشده؛ نرخ ارز ساختگی ساخته نمی‌شود
     */
    public static function orderAmountToRial($amount, string $currency): ?int
    {
        $currency = strtoupper(trim($currency));

        if ($currency !== 'IRR' && $currency !== 'IRT') {
            return null;
        }

        // گرد کردن به ریال صحیح؛ مبالغ اعشاری نیم‌ریال به نزدیک‌ترین ریال گرد می‌شوند.
        $rial = (int) round((float) $amount);

        return $currency === 'IRT' ? $rial * 10 : $rial;
    }

    /**
     * وزن به واحد وزنی فروشگاه → گرم.
     */
    public static function weightToGrams($weight): int
    {
        return (int) round(self::convertWeight((float) $weight));
    }

    /**
     * بُعد محصول (به واحد ابعادی فروشگاه) → سانتی‌متر.
     */
    public static function dimensionToCm($dimension): float
    {
        return self::convertDimension((float) $dimension);
    }

    /**
     * وابستگی به wc_get_weight/wc_get_dimension جدا نگه داشته شده تا در تست override شود.
     */
    private static function convertWeight(float $weight): float
    {
        if (function_exists('wc_get_weight')) {
            return (float) wc_get_weight($weight, 'g');
        }

        // در نبود ووکامرس (محیط تست) فرض kg
        return $weight * 1000;
    }

    private static function convertDimension(float $dimension): float
    {
        if (function_exists('wc_get_dimension')) {
            return (float) wc_get_dimension($dimension, 'cm');
        }

        // در نبود ووکامرس (محیط تست) فرض cm
        return $dimension;
    }
}
