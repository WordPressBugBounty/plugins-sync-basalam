<?php
namespace SyncBasalam\Services;

defined('ABSPATH') || exit;

class MonitoringDashboardView
{
    public static function render(string $part, array $variables): string
    {
        if (!in_array($part, ['dashboard', 'history', 'changes', 'plans', 'payment'], true)) return '';
        extract($variables, EXTR_SKIP);
        ob_start();
        require syncBasalamPlugin()->templatePath('admin/monitoring/' . $part . '.php');
        return ob_get_clean();
    }

    public static function number($value, int $precision = 0): string
    {
        if (!is_numeric($value)) return '—';
        $text = number_format((float) $value, $precision, '.', ',');
        if ($precision) $text = rtrim(rtrim($text, '0'), '.');
        return strtr($text, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹',','=>'٬','.'=>'٫']);
    }

    public static function date($value): string
    {
        $time = is_string($value) ? strtotime($value) : false;
        if ($time === false) return '—';
        return '<time datetime="' . esc_attr(gmdate('c', $time)) . '">' . esc_html(wp_date('Y/m/d H:i', $time)) . '</time>';
    }

    public static function state(string $state, bool $issues = false): array
    {
        if ($state === 'completed' && $issues) return ['تکمیل‌شده؛ نیازمند رسیدگی', 'warning'];
        return ['completed'=>['تکمیل‌شده','success'], 'running'=>['در حال بررسی','warm'],
            'failed'=>['ناقص؛ نیازمند بررسی','warning'], 'interrupted'=>['متوقف‌شده','neutral']][$state] ?? ['در انتظار پایش','neutral'];
    }

    public static function error(string $reason): string
    {
        return ['disconnected'=>'ابتدا غرفهٔ باسلام را به ووسلام متصل کنید.',
            'purchase_request_invalid'=>'اطلاعات درخواست خرید معتبر نیست؛ صفحه را به‌روز و دوباره تلاش کنید.',
            'monitoring_billing_unavailable'=>'مبلغ پرداخت با پلن مطابقت ندارد؛ برای اصلاح تنظیمات پرداخت به پشتیبانی پیام بدهید.',
            'subscription_active'=>'تا پایان اشتراک فعلی، خرید پلن جدید مجاز نیست.',
            'purchase_terms'=>'شرایط خرید و مرجع‌بودن قیمت و موجودی ووکامرس را بپذیرید.',
            'purchase_pending'=>'نتیجهٔ خرید قبلی را مشخص کنید؛ پرداخت تازه‌ای شروع نشده است.',
            'authorization'=>'مجوز اتصال معتبر نیست؛ اتصال غرفه را در صفحهٔ اصلی ووسلام به‌روز کنید.',
            'connection_changed'=>'اتصال فروشگاه تغییر کرده است؛ اتصال غرفه را دوباره بررسی کنید.',
            'not_found'=>'این گزارش برای اتصال فعلی پیدا نشد؛ گزارش را تازه کنید.',
            'not_ready'=>'اشتراک یا مجوز اصلاح آماده نیست؛ وضعیت اتصال و ظرفیت را بررسی کنید.',
            'terms_required'=>'برای فعال‌سازی، مرجع‌بودن قیمت و موجودی ووکامرس را بپذیرید.',
            'settings_unconfirmed'=>'نتیجهٔ ثبت تنظیم مشخص نیست؛ گزارش را تازه کنید و وضعیت اصلاح را بررسی کنید.',
            'coverage_conflict'=>'فهرست پوشش هم‌زمان تغییر کرده است؛ آخرین فهرست را دریافت و انتخاب را دوباره بررسی کنید.',
            'coverage_full'=>'ظرفیت پر است؛ از فهرست در حال پایش یک محصول را برای جایگزینی انتخاب کنید.',
            'coverage_invalid'=>'انتخاب محصولات معتبر نیست؛ فهرست پوشش را تازه کنید.',
            'coverage_inactive'=>'برای تغییر محصولات در حال پایش، اشتراک فعال لازم است.',
            'coverage_product_unavailable'=>'محصول منتشرشده و متصل در دسترس نیست؛ وضعیت انتشار و اتصال آن را بررسی کنید.',
            'coverage_upgrade_required'=>'افزونهٔ ووسلام را برای مدیریت پوشش محصولات به‌روز کنید.',
            'coverage_not_ready'=>'فهرست پوشش هنوز آماده نیست؛ کمی بعد وضعیت را دوباره دریافت کنید.',
            'coverage_source_unavailable'=>'فهرست محصولات از فروشگاه دریافت نشد؛ اتصال فروشگاه را بررسی و گزارش را دوباره دریافت کنید.',
            'stale'=>'این آخرین گزارش ذخیره‌شده است؛ در حال دریافت اطلاعات تازه هستیم.',
        ][$reason] ?? 'گزارش تازه دریافت نشد. این وضعیت به معنی غیرفعال‌شدن اشتراک نیست؛ کمی بعد دوباره تلاش کنید.';
    }

    public static function reason(string $reason): string
    {
        return ['values_differ'=>'قیمت یا موجودی با ووکامرس متفاوت است.',
            'source_changed'=>'محصول حین بررسی تغییر کرده؛ در دور بعد دوباره بررسی می‌شود.',
            'source_removed'=>'محصول حذف شده یا اتصال آن قطع شده است.',
            'rate_limited'=>'تعداد درخواست‌ها محدود شده؛ بررسی با فاصله ادامه پیدا می‌کند.',
            'timeout'=>'پاسخ در مهلت مقرر نرسید؛ اصلاح موفق ثبت نشده است.',
            'verification_mismatch'=>'اصلاح ارسال شد، اما نتیجه هنوز در باسلام تأیید نشده است.',
            'repair_already_attempted'=>'فرمان این دور قبلاً ارسال شده؛ برای جلوگیری از تکرار دوباره ارسال نمی‌شود.',
            'repair_batch_pending'=>'بررسی بخشی از تنوع‌ها باقی مانده است.',
            'mapping_incomplete'=>'اتصال تنوع‌های محصول را در ووسلام بررسی کنید.',
            'mapping_missing'=>'اتصال تنوع باسلام تغییر کرده است.',
            'variant_limit_exceeded'=>'این محصول بیش از ۵۰۰ تنوع قابل پایش دارد؛ بررسی کامل آن به پردازش مرحله‌ای نیاز دارد و فعلاً انجام نشده است.',
            'coverage_source_unavailable'=>'فهرست محصولات از فروشگاه دریافت نشد؛ آماده‌سازی پوشش کامل نشده است.',
            'coverage_changed'=>'انتخاب محصولات در حال پایش تغییر کرده است؛ دور بعد با فهرست تازه اجرا می‌شود.',
            'wordpress_upgrade_required'=>'افزونه را برای پشتیبانی از اصلاح خودکار به‌روز کنید.',
            'wordpress_repair_failed'=>'اصلاح توسط افزونه تکمیل نشد؛ اتصال ووسلام را بررسی کنید.',
            'capacity_exceeded'=>'ظرفیت پلن کافی نیست؛ پایش محصول و اصلاح متوقف شده‌اند.',
            'source_updates_blocked'=>'تنظیمات یا وضعیت اتصال اجازهٔ به‌روزرسانی نمی‌دهد.',
            'price_invalid'=>'قیمت محصول معتبر نیست.',
            'permission_denied'=>'مجوز اصلاح معتبر نیست؛ اتصال غرفه را بررسی کنید.',
            'remote_owner_mismatch'=>'محصول باسلام متعلق به غرفهٔ متصل نیست.',
        ][$reason] ?? 'بررسی کامل نشد؛ وضعیت اتصال و اطلاعات محصول را بررسی کنید.';
    }

    public static function values(array $values): string
    {
        $labels = [];
        foreach (array_slice($values, 0, 5) as $value) {
            if (!is_array($value)) continue;
            $parts = [];
            if (isset($value['variation_id'])) $parts[] = 'تنوع ' . self::number($value['variation_id']);
            if (isset($value['primary_price']) && is_numeric($value['primary_price'])) $parts[] = self::number($value['primary_price'] / 10, 1) . ' تومان';
            if (isset($value['stock'])) $parts[] = 'موجودی ' . self::number($value['stock']);
            $labels[] = implode('، ', $parts);
        }
        $text = implode(' / ', $labels) ?: 'اطلاعات معتبر دریافت نشده';
        return $text . (count($values) > 5 ? ' / … (نمایش پنج تنوع نخست)' : '');
    }
}
