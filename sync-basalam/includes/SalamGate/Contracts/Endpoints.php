<?php

namespace SyncBasalam\SalamGate\Contracts;

if (! defined('ABSPATH')) exit;

/**
 * نقشه قرارداد مسیرهای سرویس سلام‌گیت که افزونه استفاده می‌کند.
 *
 * auth: 'public' بدون توکن · 'user' توکن OAuth بسالم کاربر · 'admin' اسکوپ مدیریتی سرویس · 'client' فقط client-secret سرویس
 * (مسیرهای client هرگز از افزونه صدا زده نمی‌شوند و secret در افزونه توزیع نمی‌شود).
 *
 * قرارداد هر مسیر شامل روش، احراز هویت و اثر جانبی در جدول زیر ثبت شده است.
 */
final class Endpoints
{
    /** احراز هویت لازم مسیر */
    public const AUTH_PUBLIC = 'public';
    public const AUTH_USER   = 'user';
    public const AUTH_ADMIN  = 'admin'; // اسکوپ مدیریتی سلام‌گیت، نه مدیر وردپرس
    public const AUTH_CLIENT = 'client'; // فقط برای مستندسازی — فراخوانی ممنوع

    /**
     * @return array<string, array{method: string, path: string, auth: string, side_effects: bool, note?: string}>
     */
    public static function all(): array
    {
        return [
            'locations.list' => ['method' => 'GET', 'path' => '/v1/locations', 'auth' => self::AUTH_PUBLIC, 'side_effects' => false],
            'box_types.list' => ['method' => 'GET', 'path' => '/v1/parcels/box-types', 'auth' => self::AUTH_PUBLIC, 'side_effects' => false],
            'calculator.estimate' => ['method' => 'POST', 'path' => '/v1/base/calculator', 'auth' => self::AUTH_PUBLIC, 'side_effects' => false, 'note' => 'بدون hash/selected — برآورد بدون مرسوله'],

            'users.profile' => ['method' => 'GET', 'path' => '/v1/users/profile', 'auth' => self::AUTH_USER, 'side_effects' => false, 'note' => '404 = هنوز ثبت‌نام نشده'],
            'users.stats' => ['method' => 'GET', 'path' => '/v1/users/{user_id}/stats', 'auth' => self::AUTH_USER, 'side_effects' => false],
            'users.update_profile' => ['method' => 'PATCH', 'path' => '/v1/users/profile', 'auth' => self::AUTH_USER, 'side_effects' => true, 'note' => 'فقط mobile'],

            'providers.show' => ['method' => 'GET', 'path' => '/v1/providers/{provider_id}', 'auth' => self::AUTH_USER, 'side_effects' => false, 'note' => 'activation_cost از اینجا'],
            'providers.locations' => ['method' => 'GET', 'path' => '/v1/providers/{provider_id}/locations', 'auth' => self::AUTH_USER, 'side_effects' => false, 'note' => 'شناسه‌های منطقه پستی provider — با city_id عمومی فرق دارد'],
            'providers.nodes' => ['method' => 'GET', 'path' => '/v1/providers/{provider_id}/nodes', 'auth' => self::AUTH_USER, 'side_effects' => false, 'note' => 'پارامتر city_id مسیر در واقع provider_location_id می‌خواهد'],
            'providers.categories' => ['method' => 'GET', 'path' => '/v1/providers/{provider_id}/categories', 'auth' => self::AUTH_USER, 'side_effects' => false, 'note' => 'is_default وابسته به کاربر — کش باید per-account باشد'],

            'shops.list' => ['method' => 'GET', 'path' => '/v1/shops', 'auth' => self::AUTH_USER, 'side_effects' => false],
            'shops.register' => ['method' => 'POST', 'path' => '/v1/shops/register', 'auth' => self::AUTH_USER, 'side_effects' => true, 'note' => 'ثبت اولیه؛ پاسخ async می‌تواند id صف (نه غرفه) و status 15 برگرداند'],
            'shops.update' => ['method' => 'PATCH', 'path' => '/v1/shops/{id}', 'auth' => self::AUTH_USER, 'side_effects' => true, 'note' => 'فقط provider_node_id و national_code_serial؛ تغییر واقعی → وضعیت 15'],
            'shops.activate' => ['method' => 'POST', 'path' => '/v1/shops/{id}/activate', 'auth' => self::AUTH_USER, 'side_effects' => true, 'note' => 'فقط 16 → 14'],
            'shops.deactivate' => ['method' => 'POST', 'path' => '/v1/shops/{id}/deactivate', 'auth' => self::AUTH_USER, 'side_effects' => true, 'note' => 'فقط 14'],
            'addresses.update' => ['method' => 'PATCH', 'path' => '/v1/addresses/{address_id}', 'auth' => self::AUTH_USER, 'side_effects' => true, 'note' => 'با address.id نه active_version_id؛ تغییر کدپستی → غرفه‌ها 15'],

            'parcels.upsert' => ['method' => 'POST', 'path' => '/v1/markets/{market_name}/parcels', 'auth' => self::AUTH_USER, 'side_effects' => true, 'note' => 'پاسخ {parcel_id}؛ UPDATE اقلام را sync نمی‌کند'],
            'parcels.by_external' => ['method' => 'GET', 'path' => '/v1/markets/{market_name}/parcels/{external_id}', 'auth' => self::AUTH_ADMIN, 'side_effects' => false, 'note' => 'نیازمند vendor.salamgate.read.admin؛ افزونه عادی جزئیات را با parcel_id از parcels.detail می‌گیرد'],
            'parcels.list' => ['method' => 'GET', 'path' => '/v1/parcels', 'auth' => self::AUTH_USER, 'side_effects' => false, 'note' => 'default بازارها بدون basalam؛ فیلتر آرایه‌ای با کلید تکراری'],
            'parcels.detail' => ['method' => 'GET', 'path' => '/v1/parcels/{id}', 'auth' => self::AUTH_USER, 'side_effects' => false, 'note' => 'بدون tracking_code/invoice/market/external_id'],
            'parcels.quotes' => ['method' => 'GET', 'path' => '/v1/parcels/{id}/quotes', 'auth' => self::AUTH_USER, 'side_effects' => true, 'note' => 'اثر نوشتنی: نرخ ارزان‌ترین را انتخاب/جایگزین می‌کند — خواندن بی‌اثر نیست'],
            'parcels.select_quote' => ['method' => 'PUT', 'path' => '/v1/parcels/{id}/quotes', 'auth' => self::AUTH_USER, 'side_effects' => true, 'note' => 'body {hash} — نه service_type'],
            'parcels.pay' => ['method' => 'POST', 'path' => '/v1/parcels/{id}/pay', 'auth' => self::AUTH_USER, 'side_effects' => true, 'note' => 'پاسخ invoice-rich بدون tracking_code مستقیم؛ قطع شدن = نتیجه نامعلوم'],
            'parcels.cancel' => ['method' => 'POST', 'path' => '/v1/parcels/{id}/cancel', 'auth' => self::AUTH_USER, 'side_effects' => true, 'note' => 'فقط غیرباسلامی، وضعیت 8 و فاکتور پرداخت‌شده؛ ابطال بارکد و عودت توسط سرویس، پاسخ {success:true}؛ بدون تکرار خودکار'],
            'parcels.track' => ['method' => 'GET', 'path' => '/v1/parcels/{id}/track', 'auth' => self::AUTH_USER, 'side_effects' => false],
            'parcels.label' => ['method' => 'POST', 'path' => '/v1/parcels/generate-label', 'auth' => self::AUTH_USER, 'side_effects' => true, 'note' => 'فقط مرسولات پرداخت‌شده؛ async با request_id'],
            'parcels.label_status' => ['method' => 'GET', 'path' => '/v1/parcels/generate-label', 'auth' => self::AUTH_USER, 'side_effects' => false],
            'tracking.public' => ['method' => 'GET', 'path' => '/v1/tracking/{tracking_code}', 'auth' => self::AUTH_PUBLIC, 'side_effects' => false, 'note' => 'قرارداد فعلی: مپ رویدادها یا null؛ بدون کپچا و بدون دنبال‌کردن redirect به سایت پست'],

            'invoices.list' => ['method' => 'GET', 'path' => '/v1/invoices', 'auth' => self::AUTH_USER, 'side_effects' => false],
            'invoices.detail' => ['method' => 'GET', 'path' => '/v1/invoices/{id}', 'auth' => self::AUTH_USER, 'side_effects' => false],
            'invoices.factor' => ['method' => 'GET', 'path' => '/v1/invoices/{id}/factor', 'auth' => self::AUTH_USER, 'side_effects' => false, 'note' => 'خروجی URL فایل PDF'],

            // مستندسازی محدودیت‌ها — قابل فراخوانی از افزونه نیست:
            'parcels.ready_to_collect' => ['method' => 'POST', 'path' => '/v1/parcels/ready-to-collect', 'auth' => self::AUTH_CLIENT, 'side_effects' => true, 'note' => 'client-secret سرویس — ممنوع در افزونه'],
            'market_parcel.payment_query' => ['method' => 'POST', 'path' => '/v1/markets/{market_name}/parcels/{external_id}/payments', 'auth' => self::AUTH_CLIENT, 'side_effects' => false, 'note' => 'ادغام سمت سرور — از افزونه استفاده نمی‌شود'],
            'payments.*' => ['method' => '-', 'path' => '/v1/payments/...', 'auth' => self::AUTH_CLIENT, 'side_effects' => false, 'note' => 'در router سرویس سوار نشده — قابل اتکا نیست'],
        ];
    }

    public static function meta(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }
}
