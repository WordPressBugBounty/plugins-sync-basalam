<?php

namespace SyncBasalam\SalamGate\Contracts;

if (! defined('ABSPATH')) exit;

/**
 * دو مجموعه‌ی مستقل روش پرداخت — هیچ‌گاه با هم اشتباه گرفته نشوند:
 *
 * رشته ۱ — ثبت/فعال‌سازی فروشگاه (pay_method در POST /v1/shops/register):
 *   8 تراز غرفه‌دار · 2 کیف پول
 *
 * رشته ۲ — پرداخت هزینه ارسال مرسوله (method در POST /v1/parcels/{id}/pay):
 *   19 مبلغ سفارش بسالم (فقط بازار basalam) · 20 تراز غرفه‌دار · 21 کیف پول
 *
 * منبع: src/services/enums/payhub_pay_method_enums.py و pay_method_enums.py
 */
final class PayMethods
{
    // رشته ۱ — فعال‌سازی فروشگاه
    public const SHOP_ACTIVATION_VENDOR_BALANCE = 8;
    public const SHOP_ACTIVATION_WALLET         = 2;

    // رشته ۲ — پرداخت مرسوله
    public const PARCEL_ORDER_BALANCE  = 19;
    public const PARCEL_VENDOR_BALANCE = 20;
    public const PARCEL_WALLET         = 21;

    public const SHOP_ACTIVATION_TITLES = [
        self::SHOP_ACTIVATION_VENDOR_BALANCE => 'تراز غرفه‌دار',
        self::SHOP_ACTIVATION_WALLET         => 'کیف پول',
    ];

    public const PARCEL_TITLES = [
        self::PARCEL_ORDER_BALANCE  => 'مبلغ سفارش باسلام',
        self::PARCEL_VENDOR_BALANCE => 'تراز غرفه‌دار',
        self::PARCEL_WALLET         => 'کیف پول',
    ];

    /**
     * @return array<int, string>
     */
    public static function parcelMethodsForMarket(string $market): array
    {
        if ($market === 'basalam') {
            return [self::PARCEL_ORDER_BALANCE => self::PARCEL_TITLES[self::PARCEL_ORDER_BALANCE]];
        }

        return [
            self::PARCEL_VENDOR_BALANCE => self::PARCEL_TITLES[self::PARCEL_VENDOR_BALANCE],
            self::PARCEL_WALLET         => self::PARCEL_TITLES[self::PARCEL_WALLET],
        ];
    }

    public static function isValidParcelMethodForMarket(int $method, string $market): bool
    {
        return array_key_exists($method, self::parcelMethodsForMarket($market));
    }

    public static function isValidShopActivationMethod(int $method): bool
    {
        return isset(self::SHOP_ACTIVATION_TITLES[$method]);
    }

    public static function shopActivationTitle(int $method): string
    {
        return self::SHOP_ACTIVATION_TITLES[$method] ?? 'نامشخص';
    }

    public static function parcelTitle(int $method): string
    {
        return self::PARCEL_TITLES[$method] ?? 'نامشخص';
    }
}
