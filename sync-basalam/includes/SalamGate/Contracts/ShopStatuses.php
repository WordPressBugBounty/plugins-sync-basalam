<?php

namespace SyncBasalam\SalamGate\Contracts;

if (! defined('ABSPATH')) exit;

/**
 * وضعیت‌های فروشگاه (غرفه) سلام‌گیت — enum سرویس: src/services/enums/shop_status_enum.py
 *
 * 14 فعال · 15 در انتظار تأیید · 16 غیرفعال · 17 رد شده
 * «ثبت موفق» با «فعال» یکی نیست؛ PATCH واقعی وضعیت را به 15 برمی‌گرداند.
 */
final class ShopStatuses
{
    public const ACTIVE           = 14;
    public const PENDING_APPROVAL = 15;
    public const INACTIVE         = 16;
    public const REJECTED         = 17;

    public const TITLES = [
        self::ACTIVE           => 'فعال',
        self::PENDING_APPROVAL => 'در انتظار تأیید',
        self::INACTIVE         => 'غیرفعال',
        self::REJECTED         => 'رد شده',
    ];

    public static function title(int $id): string
    {
        return self::TITLES[$id] ?? 'نامشخص';
    }

    public static function isValid(int $id): bool
    {
        return isset(self::TITLES[$id]);
    }

    /**
     * فعال‌سازی مجدد فقط برای فروشگاه غیرفعال (16 → 14) مجاز است.
     */
    public static function canActivate(int $id): bool
    {
        return $id === self::INACTIVE;
    }

    /**
     * غیرفعال‌سازی فقط برای فروشگاه فعال.
     */
    public static function canDeactivate(int $id): bool
    {
        return $id === self::ACTIVE;
    }

    /**
     * آیا این وضعیت اجازه ساخت/پرداخت مرسوله را می‌دهد؟ (فعال بودن حساب پستی)
     */
    public static function isOperational(int $id): bool
    {
        return $id === self::ACTIVE;
    }
}
