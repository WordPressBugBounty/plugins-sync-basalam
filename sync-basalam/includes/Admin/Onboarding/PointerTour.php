<?php

namespace SyncBasalam\Admin\Onboarding;

class PointerTour
{
    public static function markPointerOnboardingCompleted(): void
    {
        check_ajax_referer('sync_basalam_mark_pointer_onboarding_completed', 'nonce', true);

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی مجاز نیست.'], 403);
        }

        update_option('sync_basalam_pointer_onboarding_completed', true, false);

        wp_send_json_success(['message' => 'راهنمای اولیه تکمیل شد.']);
    }

    public static function shouldLoadPointerTour(string $hook): bool
    {
        if ($hook !== 'toplevel_page_sync_basalam') {
            return false;
        }

        if (!syncBasalamSettings()->hasToken()) {
            return false;
        }

        $pointerOnboardingStatus = get_option('sync_basalam_pointer_onboarding_completed', null);

        if ($pointerOnboardingStatus === null) {
            return true;
        }

        return false;
    }

    public static function getPointerTourConfig(): array
    {
        return [
            'nonce' => wp_create_nonce('sync_basalam_mark_pointer_onboarding_completed'),
            'completeAction' => 'sync_basalam_mark_pointer_onboarding_completed',
            'steps' => self::getPointerSteps(),
        ];
    }

    private static function getPointerSteps(): array
    {
        return [
            [
                'selector' => '#toplevel_page_sync_basalam > ul > li:nth-child(4) > a',
                'content' => '
                    <div class="sync-basalam-pointer-step">
                        <h3 class="sync-basalam-pointer-title">منو افزونه</h3>
                        <p class="sync-basalam-pointer-text">این‌ها منوهای اصلی افزونه ووسلام هستند؛ از تب «خانه» می‌توانید وارد داشبورد شوید و عملیات اتصال، محصولات و سفارشات را مدیریت کنید، و از تب «پشتیبانی» تیکت جدید ثبت کنید. همچنین تب‌های «لاگ‌ها»، «اطلاعات»، «راهنما» و «اتصال دسته‌بندی‌ها» برای پیگیری وضعیت، آموزش و تنظیمات تکمیلی در دسترس شما هستند.</p>
                    </div>',
                'position' => ['edge' => 'right', 'align' => 'middle'],
                'nextLabel' => 'مرحله بعد',
            ],
            [
                'selector' => '#sync-basalam-onboarding-status',
                'content' => '
                    <div class="sync-basalam-pointer-step">
                        <h3 class="sync-basalam-pointer-title">وضعیت اتصال</h3>
                        <p class="sync-basalam-pointer-text">این بخش نمای کلی اتصال فروشگاه شما با باسلام است و تعداد محصولات منتشرشده ووکامرس و محصولات سینک‌شده باسلام را نمایش می‌دهد.</p>
                        <p class="sync-basalam-pointer-text">از همین بخش می‌توانید همگام‌سازی خودکار محصولات را فعال یا غیرفعال کنید تا تغییرات محصول‌ها به‌صورت خودکار در باسلام اعمال شوند.</p>
                    </div>',
                'position' => ['edge' => 'left', 'align' => 'middle'],
                'nextLabel' => 'مرحله بعد',
            ],
            [
                'selector' => '#sync-basalam-onboarding-products',
                'content' => '
                    <div class="sync-basalam-pointer-step">
                        <h3 class="sync-basalam-pointer-title">مدیریت محصولات</h3>
                        <p class="sync-basalam-pointer-text">در این قسمت سه عملیات اصلی محصول را دارید: ایجاد همه محصولات در باسلام، بروزرسانی گروهی محصولات باسلامی، و اتصال محصولات موجود غرفه به محصولات ووکامرس.</p>
                        <p class="sync-basalam-pointer-text">اگر بخشی از محصولات قبلاً سینک نشده‌اند، از لینک‌های «محصولات سینک نشده ووکامرس» و «محصولات سینک نشده باسلام» برای بررسی سریع استفاده کنید.</p>
                    </div>',
                'position' => ['edge' => 'right', 'align' => 'middle'],
                'nextLabel' => 'مرحله بعد',
            ],
            [
                'selector' => '#sync-basalam-onboarding-orders',
                'content' => '
                    <div class="sync-basalam-pointer-step">
                        <h3 class="sync-basalam-pointer-title">مدیریت سفارشات</h3>
                        <p class="sync-basalam-pointer-text">از این بخش، دریافت سفارش‌های باسلام در ووکامرس را کنترل می‌کنید و می‌توانید همگام‌سازی سفارشات را روشن یا متوقف کنید.</p>
                        <p class="sync-basalam-pointer-text">همچنین امکان فعال‌سازی تایید خودکار سفارشات باسلام وجود دارد تا فرایند پردازش سفارش‌ها سریع‌تر و یکپارچه‌تر انجام شود.</p>
                    </div>',
                'position' => ['edge' => 'right', 'align' => 'middle'],
                'nextLabel' => 'مرحله بعد',
            ],
            [
                'selector' => '#sync-basalam-onboarding-settings',
                'content' => '
                    <div class="sync-basalam-pointer-step">
                        <h3 class="sync-basalam-pointer-title">تنظیمات اصلی</h3>
                        <p class="sync-basalam-pointer-text">اینجا مهم‌ترین تنظیمات اولیه را انجام می‌دهید؛ مثل افزایش قیمت، موجودی پیش‌فرض، و انتخاب نوع قیمت ارسالی (قیمت اصلی یا حراجی).</p>
                        <p class="sync-basalam-pointer-text">پیشنهاد میشود حتما بر اساس اولویت های کسب و کارتان تمامی تنظیمات را بررسی کنید.</p>
                    </div>',
                'position' => ['edge' => 'left', 'align' => 'middle'],
                'nextLabel' => 'مرحله بعد',
            ],
            [
                'selector' => '#sync-basalam-onboarding-advanced-settings',
                'content' => '
                    <div class="sync-basalam-pointer-step">
                        <h3 class="sync-basalam-pointer-title">تنظیمات بیشتر</h3>
                        <p class="sync-basalam-pointer-text">با این دکمه تنظیمات پیشرفته باز می‌شود: تنظیمات محصول، تنظیمات سفارش، و تنظیمات اجرای عملیات (سرعت پردازش و مدیریت تسک‌ها).</p>
                        <p class="sync-basalam-pointer-text">اگر نیاز به کنترل دقیق‌تری روی فیلدهای ارسالی، وزن‌ها، وضعیت سفارش‌ها و عملکرد سیستم دارید، این بخش نقطه اصلی تنظیمات حرفه‌ای شماست.</p>
                    </div>',
                'position' => ['edge' => 'bottom', 'align' => 'middle'],
                'doneLabel' => 'پایان راهنما',
            ],
        ];
    }
}
