<?php

namespace SyncBasalam\SalamGate\Admin;

if (! defined('ABSPATH')) exit;

/**
 * منوهای سلام‌گیت زیر منوی اصلی ووسلام.
 *
 * ورودی canonical با عنوان منوی «ارسال مرسوله» (slug: salam_gate) که
 * بر اساس وضعیت واقعی حساب route می‌شود (AccountStateRouter).
 * لینک‌های قدیمی salam_gate_status با redirect سازگار می‌مانند و صفحه‌ی
 * ثبت‌نشده‌ای باقی نمی‌ماند. صفحه مرسولات سر جایش است.
 *
 * capability: هر دو صفحه منو manage_options (هماهنگ با والد sync_basalam در پایه)؛
 * اکشن‌های AJAX متاباکس روی manage_woocommerce می‌مانند چون متاباکس ووکامرس
 * همان نقش را می‌بیند.
 */
class Menus
{
    /** مسیر canonical سلام‌گیت — router وضعیت حساب. */
    public const MAIN_SLUG = 'salam_gate';
    /** مسیر قدیمی — فقط redirect به MAIN_SLUG (سازگاری لینک‌های ذخیره‌شده). */
    public const STATUS_SLUG = 'salam_gate_status';
    public const PARCELS_SLUG = 'salam_gate_parcels';

    public static function register()
    {
        add_submenu_page(
            'sync_basalam',
            'سلام‌گیت',
            '<span class="basalom-icon-medium" style="display:inline-flex;vertical-align:middle;margin-left:6px;" aria-hidden="true">' . self::shipmentIcon() . '</span>ارسال مرسوله',
            'manage_options',
            self::MAIN_SLUG,
            [AccountStateRouter::class, 'render'],
        );

        // صفحه مرسولات در منو نمایش داده نمی‌شود (طبق مرجع فقط از داشبورد دسترس می‌شود)
        // اما URL آن باید کار کند — الگوی «صفحه مخفی»: والد null.
        // حذف ردیف این صفحه در زمان register دسترسی مستقیم را می‌شکند؛ تا load حفظ شود.
        $parcelsHook = add_submenu_page(
            null,
            'مرسوله‌ها | سلام‌گیت',
            'مرسوله‌ها | سلام‌گیت',
            'manage_options',
            self::PARCELS_SLUG,
            [__CLASS__, 'renderParcelsPage'],
        );

        // Keep the hidden parcels route associated with WooSalam in WordPress admin chrome.
        add_action('load-' . ($parcelsHook ?: 'admin_page_' . self::PARCELS_SLUG), [__CLASS__, 'prepareParcelsScreen']);

        // مسیر قدیمی salam_gate_status هرگز صفحه مستقل نیست — redirect به مسیر canonical.
        add_action('admin_init', [__CLASS__, 'redirectLegacySlug']);
    }

    /** Decorative shipment mark shared by the menu and page heading. */
    public static function shipmentIcon(): string
    {
        // Gentlecons / Konstantin Filatov, CC BY 4.0; see the bundled attribution.
        // currentColor keeps the sidebar mark neutral and the page heading orange.
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" fill="none" aria-hidden="true" focusable="false">'
            . '<path d="M3 3C1.34315 3 0 4.34315 0 6V15C0 16.3121 0.842366 17.4275 2.01581 17.8348C2.18436 19.6108 3.67994 21 5.5 21C7.26324 21 8.72194 19.6961 8.96456 18H15.0354C15.2781 19.6961 16.7368 21 18.5 21C20.3201 21 21.8156 19.6108 21.9842 17.8348C23.1576 17.4275 24 16.3121 24 15V10.7515C24 10.0248 23.7362 9.32283 23.2577 8.77596L20.8502 6.02449C20.2805 5.37344 19.4576 5 18.5925 5H16.8293C16.4175 3.83481 15.3062 3 14 3H3ZM4 17.4361V17.5639C4.03348 18.3634 4.69224 19.0013 5.5 19.0013C6.30776 19.0013 6.96652 18.3634 7 17.5639V17.4361C6.96652 16.6366 6.30776 15.9987 5.5 15.9987C4.69224 15.9987 4.03348 16.6366 4 17.4361ZM5.5 14C6.8962 14 8.10145 14.8175 8.66318 16H15.3368C15.8985 14.8175 17.1038 14 18.5 14C19.8245 14 20.9771 14.7357 21.5716 15.8207C21.8306 15.64 22 15.3398 22 15V11H17C15.8954 11 15 10.1046 15 9V6C15 5.44772 14.5523 5 14 5H3C2.44772 5 2 5.44772 2 6V15C2 15.3398 2.16945 15.64 2.42845 15.8207C3.02292 14.7357 4.17555 14 5.5 14ZM17 7V8C17 8.55229 17.4477 9 18 9H20.7962L19.345 7.34149C19.1552 7.12448 18.8808 7 18.5925 7H17ZM17 17.4361V17.5639C17.0335 18.3634 17.6922 19.0013 18.5 19.0013C19.3078 19.0013 19.9665 18.3634 20 17.5639V17.4361C19.9665 16.6366 19.3078 15.9987 18.5 15.9987C17.6922 15.9987 17.0335 16.6366 17 17.4361Z" clip-rule="evenodd" fill="currentColor" fill-rule="evenodd"/>'
            . '</svg>';
    }

    /**
     * سازگاری لینک‌های قدیمی: admin.php?page=salam_gate_status → salam_gate.
     * (admin_init پیش از رندر صفحه اجرا می‌شود؛ صفحه قدیمی ثبت نمی‌شود.)
     */
    public static function redirectLegacySlug()
    {
        if (!isset($_GET['page']) || $_GET['page'] !== self::STATUS_SLUG) {
            return;
        }

        $target = admin_url('admin.php?page=' . self::MAIN_SLUG);
        wp_safe_redirect($target, 301);
        exit;
    }

    public static function renderParcelsPage()
    {
        $template = __DIR__ . '/../../../templates/SalamGate/parcels-page.php';
        if (file_exists($template)) {
            require_once $template;
        }
    }

    public static function prepareParcelsScreen(): void
    {
        global $title;

        $title = 'مرسوله‌ها | سلام‌گیت';
        // At load-* WordPress has already checked access and selected the registered hook.
        // Remove only the hidden menu row now, so get_admin_page_parent() cannot reset
        // the highlighted WooSalam parent back to the empty parent after our filters.
        // Removing this during registration would break direct-route access checks.
        remove_submenu_page('', self::PARCELS_SLUG);
        add_filter('parent_file', [__CLASS__, 'parcelsParentFile']);
        add_filter('submenu_file', [__CLASS__, 'parcelsSubmenuFile']);
    }

    public static function parcelsParentFile(string $parentFile): string
    {
        return self::isParcelsPage() ? 'sync_basalam' : $parentFile;
    }

    public static function parcelsSubmenuFile(?string $submenuFile): ?string
    {
        return self::isParcelsPage() ? self::MAIN_SLUG : $submenuFile;
    }

    private static function isParcelsPage(): bool
    {
        global $plugin_page;

        return $plugin_page === self::PARCELS_SLUG
            || (isset($_GET['page']) && $_GET['page'] === self::PARCELS_SLUG);
    }
}
