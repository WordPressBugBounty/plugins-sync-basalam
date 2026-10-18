<?php

namespace SyncBasalam\SalamGate\Utils;

defined('ABSPATH') || exit;

/**
 * ابزار کار با ارقام فارسی/عربی و نرمال‌سازی شماره‌ها.
 */
class Digits
{
    private const PERSIAN_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    private const ARABIC_DIGITS  = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    private const ENGLISH_DIGITS = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    public static function toEnglish(string $value): string
    {
        return str_replace(
            array_merge(self::PERSIAN_DIGITS, self::ARABIC_DIGITS),
            array_merge(self::ENGLISH_DIGITS, self::ENGLISH_DIGITS),
            $value
        );
    }

    public static function toPersian(string $value): string
    {
        return str_replace(
            self::ENGLISH_DIGITS,
            self::PERSIAN_DIGITS,
            $value
        );
    }

    /**
     * نرمال‌سازی موبایل: ارقام لاتین، حذف غیرارقام، پیشوند +98/0098/98 → 0
     * (و افزودن 0 ابتدای شماره‌های 9xxxxxxxxx).
     */
    public static function normalizeMobile(string $value): string
    {
        $value = preg_replace('/\D+/', '', self::toEnglish($value));

        if (preg_match('/^0*98(9\d{9})$/', $value, $matches)) {
            return '0' . $matches[1];
        }

        if (preg_match('/^9\d{9}$/', $value)) {
            return '0' . $value;
        }

        return $value;
    }

    public static function isValidMobile(string $value): bool
    {
        return (bool) preg_match('/^09\d{9}$/', $value);
    }

    /**
     * نرمال‌سازی کد پستی؛ اگر دقیقاً ۱۰ رقم نشد null.
     */
    public static function normalizePostalCode(string $value): ?string
    {
        $value = preg_replace('/\D+/', '', self::toEnglish($value));

        return (strlen($value) === 10) ? $value : null;
    }
}
