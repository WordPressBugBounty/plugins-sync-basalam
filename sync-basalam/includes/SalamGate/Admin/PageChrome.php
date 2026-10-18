<?php

namespace SyncBasalam\SalamGate\Admin;

defined('ABSPATH') || exit;

/** Shared presentation metadata; account routing and permissions stay in their existing handlers. */
class PageChrome
{
    public static function status(string $state): array
    {
        $statuses = [
            'active'         => ['label' => 'حساب فعال', 'tone' => 'active'],
            'pending'        => ['label' => 'در انتظار تأیید', 'tone' => 'pending'],
            'inactive'       => ['label' => 'حساب غیرفعال', 'tone' => 'inactive'],
            'rejected'       => ['label' => 'درخواست رد شده', 'tone' => 'inactive'],
            'not_registered' => ['label' => 'فعال‌سازی نشده', 'tone' => 'neutral'],
            'not_connected'  => ['label' => 'اتصال برقرار نیست', 'tone' => 'error'],
            'error_auth'     => ['label' => 'نیاز به اتصال مجدد', 'tone' => 'error'],
            'error_forbidden' => ['label' => 'مجوز سلام‌گیت کافی نیست', 'tone' => 'error'],
            'error_network'  => ['label' => 'بررسی وضعیت ناموفق', 'tone' => 'error'],
            'loading'        => ['label' => 'در حال بررسی وضعیت', 'tone' => 'neutral'],
        ];

        return $statuses[$state] ?? $statuses['loading'];
    }

    public static function page(string $view, string $state): array
    {
        $pages = [
            ''             => ['title' => $state === 'active' ? 'داشبورد سلام‌گیت' : 'سلام‌گیت', 'subtitle' => 'ارسال و پیگیری مرسوله‌های فروشگاه', 'icon' => 'shipment'],
            'send'         => ['title' => 'ثبت مرسوله', 'subtitle' => 'ارسال سفارش‌های فروشگاه با سلام‌گیت', 'icon' => 'airplane'],
            'parcels'      => ['title' => 'مرسوله‌ها', 'subtitle' => 'مشاهده، پیگیری و چاپ برچسب مرسوله‌های سلام‌گیت', 'icon' => 'archive'],
            'calculator'   => ['title' => 'ماشین‌حساب هزینه ارسال', 'subtitle' => 'برآورد هزینه ارسال با سلام‌گیت', 'icon' => 'calculator'],
            'settings'     => ['title' => 'تنظیمات سلام‌گیت', 'subtitle' => 'آدرس‌های فرستنده، ابزارها و پرسش‌های متداول', 'icon' => 'admin-generic'],
            'activation'   => ['title' => 'فعال‌سازی سلام‌گیت', 'subtitle' => 'ثبت و بررسی وضعیت حساب سلام‌گیت', 'icon' => 'admin-network'],
            'post-offices' => ['title' => 'مراکز تحویل', 'subtitle' => 'پیدا کردن مرکز تحویل مرسوله در منطقه شما', 'icon' => 'location-alt'],
            'tracking'     => ['title' => 'پیگیری مرسوله', 'subtitle' => 'آخرین وضعیت و سابقه ارسال مرسوله', 'icon' => 'search'],
        ];

        return $pages[$view] ?? $pages[''];
    }

    public static function navigation(string $view, string $state): array
    {
        $current = in_array($view, ['', 'activation'], true) ? 'dashboard' : $view;
        $mainUrl = admin_url('admin.php?page=' . Menus::MAIN_SLUG);
        $items = [
            ['id' => 'dashboard', 'label' => 'داشبورد', 'url' => $mainUrl, 'requires_active' => false],
            ['id' => 'send', 'label' => 'ثبت مرسوله', 'url' => $mainUrl . '&view=send', 'requires_active' => true],
            ['id' => 'parcels', 'label' => 'مرسوله‌ها', 'url' => admin_url('admin.php?page=' . Menus::PARCELS_SLUG), 'requires_active' => true],
            ['id' => 'tracking', 'label' => 'پیگیری مرسوله', 'url' => $mainUrl . '&view=tracking', 'requires_active' => false],
            ['id' => 'calculator', 'label' => 'ماشین‌حساب', 'url' => $mainUrl . '&view=calculator', 'requires_active' => false],
            ['id' => 'post-offices', 'label' => 'مراکز تحویل', 'url' => $mainUrl . '&view=post-offices', 'requires_active' => false],
            ['id' => 'settings', 'label' => 'تنظیمات', 'url' => $mainUrl . '&view=settings', 'requires_active' => true],
        ];

        foreach ($items as &$item) {
            $item['requires_registration'] = $item['id'] === 'activation';
            $item['visible'] = (!$item['requires_active'] || $state === 'active')
                && (!$item['requires_registration'] || $state === 'not_registered' || $view === 'activation');
            $item['current'] = $item['id'] === $current;
            $item['aria_current'] = 'page';
        }
        unset($item);

        return $items;
    }
}
