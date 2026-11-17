<?php

namespace SyncBasalam\SalamGate\Admin;

use SyncBasalam\Admin\PromotionBanner;
use SyncBasalam\SalamGate\FeatureAvailability;
use SyncBasalam\SalamGate\Paths;
use SyncBasalam\SalamGate\Services\SiteActivation;

defined('ABSPATH') || exit;

/** A dismissible invitation. Visibility and preferences never contact SalamGate. */
final class DiscoveryNotice
{
    private const META_KEY = 'woosalam_sg_intro_dismissed';

    public static function register(): void
    {
        PromotionBanner::register('salamgate', 20, [self::class, 'slide']);
        add_action('admin_post_salam_gate_dismiss_intro', [self::class, 'dismiss']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    private static function visible(): bool
    {
        if (!current_user_can('manage_options') || FeatureAvailability::isVisible()
            || get_user_option(self::META_KEY) === '1') return false;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $page = is_string($_GET['page'] ?? null) ? $_GET['page'] : '';
        return $page === 'sync_basalam'
            || ($screen->id ?? '') === 'dashboard'
            || (in_array($screen->id ?? '', ['edit-shop_order', 'woocommerce_page_wc-orders'], true)
                && empty($_GET['action']));
    }

    public static function enqueue(): void
    {
        $page = is_string($_GET['page'] ?? null) ? $_GET['page'] : '';
        if ($page !== 'salam_gate') return;
        Assets\AssetRegistrar::base('basalam-admin-font-style', 'assets/css/font.css');
        wp_enqueue_style('woosalam-sg-discovery', Paths::assetsUrl('styles/salam-gate-discovery.css'), ['basalam-admin-font-style'],
            (string) filemtime(Paths::assetPath('styles/salam-gate-discovery.css')));
    }

    public static function slide(): ?array
    {
        if (!self::visible()) return null;
        $disabled = !SiteActivation::isEnabled();
        return [
            'name' => 'سلام‌گیت',
            'icon' => Menus::shipmentIcon(),
            'text' => $disabled
                ? 'هر وقت خواستید، دوباره با سلام‌گیت بفرستید! هزینه ارسال را حساب کنید، مراکز تحویل را ببینید و مرسوله‌هایتان را پیگیری کنید.'
                : 'با سلام‌گیت، سفارش‌های فروشگاه خود را در ووسلام تکی یا گروهی ارسال کنید و برچسب پستی بگیرید. محاسبه هزینه، مراکز تحویل و پیگیری مرسوله هم در دسترس شماست.',
            'url' => admin_url('admin.php?page=salam_gate'),
            'cta' => $disabled ? 'فعال‌سازی مجدد سلام‌گیت' : 'فعال‌سازی سلام‌گیت',
            'external' => false,
            'dismiss_action' => 'salam_gate_dismiss_intro',
            'dismiss_hint' => 'این اعلان دیگر نمایش داده نمی‌شود.',
        ];
    }

    public static function dismiss(): void
    {
        if (!current_user_can('manage_options') || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die('دسترسی مجاز نیست.', '', ['response' => 403]);
        }
        check_admin_referer('salam_gate_dismiss_intro');
        update_user_option(get_current_user_id(), self::META_KEY, '1', false);
        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=sync_basalam'));
        exit;
    }
}
