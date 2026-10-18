<?php

namespace SyncBasalam\SalamGate\Contracts;

if (! defined('ABSPATH')) exit;

/**
 * وضعیت‌های مرسوله سلام‌گیت — enum سرویس: src/services/enums/parcel_status_enum.py
 *
 * 7 جدید · 8 بارکد پستی صادر شد · 9 ارسال شد · 10 تحویل شد · 11 تحویل به نامه‌رسان
 * 12 مرجوع به فرستنده · 44 ابطال شده
 *
 * مقایسه عددی (مثل status >= 8) برای هیچ تصمیمی مجاز نیست؛ فقط همین ماشین وضعیت.
 * «بارکد صادر شد» به معنی تحویل بسته به پست نیست؛ ثبت موفق پرداخت به معنی فعال بودن حساب نیست.
 */
final class ParcelStatuses
{
    public const NEW                    = 7;
    public const POSTAL_BARCODE         = 8;
    public const SHIPPED                = 9;
    public const DELIVERED              = 10;
    public const HANDED_TO_POSTMAN      = 11;
    public const RETURNED_TO_SENDER     = 12;
    public const VOIDED                 = 44;

    /** وضعیت‌های پایانی — پس از آن‌ها چرخه ارسال تمام است. */
    public const FINAL_STATUSES = [
        self::DELIVERED,
        self::RETURNED_TO_SENDER,
        self::VOIDED,
    ];

    /**
     * وضعیت‌های دارای رسید پستی = پرداخت مؤثر ثبت شده و بارکد صادر شده.
     * مرجوع (12) هم رسید دارد اما پایانی است؛ ابطال (44) رسید باطل‌شده است.
     */
    public const RECEIPT_STATUSES = [
        self::POSTAL_BARCODE,
        self::SHIPPED,
        self::DELIVERED,
        self::HANDED_TO_POSTMAN,
        self::RETURNED_TO_SENDER,
    ];

    public const TITLES = [
        self::NEW                => 'مرسوله جدید',
        self::POSTAL_BARCODE     => 'بارکد پستی صادر شد',
        self::SHIPPED            => 'ارسال شد',
        self::DELIVERED          => 'تحویل شد',
        self::HANDED_TO_POSTMAN  => 'تحویل به نامه‌رسان',
        self::RETURNED_TO_SENDER => 'مرجوع به فرستنده',
        self::VOIDED             => 'ابطال شده',
    ];

    public static function title(int $id): string
    {
        return self::TITLES[$id] ?? 'نامشخص';
    }

    public static function isValid(int $id): bool
    {
        return isset(self::TITLES[$id]);
    }

    public static function isFinal(int $id): bool
    {
        return in_array($id, self::FINAL_STATUSES, true);
    }

    /**
     * رسید پستی صادر شده (پرداخت ثبت‌شده). این همان معنای «پرداخت شده» است — نه >= 8.
     */
    public static function hasReceipt(int $id): bool
    {
        return in_array($id, self::RECEIPT_STATUSES, true);
    }

    public static function isVoided(int $id): bool
    {
        return $id === self::VOIDED;
    }

    /**
     * فقط مرسوله جدید (7) قابل ویرایش است (parcel_not_locked_validator).
     */
    public static function isEditable(int $id): bool
    {
        return $id === self::NEW;
    }

    /**
     * آیا هنوز باید هزینه ارسال پرداخت شود؟ (همان وضعیت جدید)
     */
    public static function requiresPayment(int $id): bool
    {
        return $id === self::NEW;
    }
}
