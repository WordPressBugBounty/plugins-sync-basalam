<?php

namespace SyncBasalam\SalamGate\Admin;

if (! defined('ABSPATH')) exit;

/**
 * Router وضعیت حساب سلام‌گیت — مسیر اصلی تجربه (پارت ۳).
 *
 * حالت‌ها (enum صریح؛ هیچ‌کدام با دیگری قابل جایگزینی نیست):
 * - not_connected   اتصال ووسلام به بسالم برقرار نیست (توکن نیست)
 * - loading         در حال دریافت (فقط مسیر async/AJAX؛ رندر سرور مستقیم resolve می‌کند)
 * - error_network   خطای ارتباط — کاربر تازه‌وارد فرض نمی‌شود، فرم ثبت تکراری نمی‌آید
 * - error_auth      احراز هویت رد شده (401) — راهنمای اتصال مجدد
 * - error_forbidden مجوز عملیات ناکافی (403) — تأیید دسترسی‌های سلام‌گیت، نه انقضای توکن
 * - not_registered  بدون ثبت (فهرست غرفه‌ها خالی) → صفحه معرفی/فعال‌سازی
 * - pending         ثبت در انتظار تأیید (15)
 * - rejected        رد شده (17)
 * - inactive        غیرفعال (16) → فعال‌سازی مجدد
 * - active          فعال (14) → داشبورد (پارت ۵؛ تا آن‌موقع حالت توسعه صریح)
 *
 * اولویت وضعیت‌ها با چند غرفه (تصمیم پیشنهادی؛ چندغرفه‌ای کامل در پارت ۵):
 * active > pending > inactive > rejected — اولین تجربه قابل‌اقدام مسلط می‌شود.
 */
class AccountStateRouter extends \SyncBasalam\SalamGate\Domain\AccountStateResolver
{
    /** viewهای مجاز روی مسیر canonical. */
    public const VIEW_STATES  = ['calculator', 'post-offices', 'tracking', 'activation', 'settings', 'send'];

    /** Compatibility entry point; Module owns registration. */
    public static function register(): void {}

    /**
     * رندر صفحه canonical — پوسته + وضعیت resolve‌شده (یا view توسعه‌ای).
     */
    public static function render()
    {
        if (!current_user_can('manage_options')) {
            wp_die('دسترسی به سلام‌گیت مجاز نیست؛ با مدیر فروشگاه تماس بگیرید.');
        }

        $view = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : '';

        $data = [
            'state'   => null,
            'view'    => in_array($view, self::VIEW_STATES, true) ? $view : '',
            'shops'   => [],
            'message' => '',
        ];

        if ($data['view'] === '') {
            $data = self::resolveForPage();
        } else {
            // viewها هم وضعیت واقعی حساب را بدانند (مثلاً ویزارد ثبت فقط برای «بدون ثبت»).
            // refresh/بازگشت کاربر وضعیت سرور را بازیابی می‌کند — دوباره ثبت نمی‌زند.
            $resolved = self::resolveForPage();
            $data['state'] = $resolved['state'];
            $data['shops'] = $resolved['shops'];
            $data['message'] = $resolved['message'];
            $data['missing_scope'] = !empty($resolved['missing_scope']);
        }

        $template = __DIR__ . '/../../../templates/SalamGate/page-router.php';
        if (file_exists($template)) {
            require_once $template;
        }
    }

    /**
     * Page navigation must not wait for an external API or OAuth renewal.
     * Only a current cache belonging to the saved connection is trusted here;
     * a miss renders a loading shell whose read-only AJAX request resolves the
     * account. Operational handlers continue to use their existing guards.
     *
     * @return array{state: string, shops: array, message: string}
     */
    public static function resolveForPage(): array
    {
        return \SyncBasalam\SalamGate\Services\AccountStateService::resolveForPage();
    }

    /**
     * وضعیت فعلی حساب را از دنیای واقعی resolve می‌کند (خالص نیست — WP-facing).
     *
     * @return array{state: string, shops: array, message: string}
     */
    public static function resolveCurrent(bool $fresh = false): array
    {
        return \SyncBasalam\SalamGate\Services\AccountStateService::resolveCurrent($fresh);
    }

    /**
     * HTML جزء وضعیت (برای رندر سروری و AJAX refresh).
     */
    public static function renderStatePartial(array $data): string
    {
        $state = in_array($data['state'] ?? '', self::ALL_STATES, true) ? $data['state'] : self::STATE_LOADING;
        $template = __DIR__ . '/../../../templates/SalamGate/states/state-' . $state . '.php';

        ob_start();
        if (file_exists($template)) {
            include $template;
        } else {
            echo '<div class="sg-state" data-state="' . esc_attr($state) . '"></div>';
        }

        return (string) ob_get_clean();
    }

    /** Compatibility entry point; the manifest owns all paths and dependencies. */
    public static function enqueueAssets(string $hookSuffix): void
    {
        Assets\AssetRegistrar::enqueue($hookSuffix);
    }
}
