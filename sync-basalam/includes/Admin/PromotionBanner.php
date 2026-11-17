<?php

namespace SyncBasalam\Admin;

defined('ABSPATH') || exit;

/** One presentation surface; each service retains its own visibility and dismissal policy. */
final class PromotionBanner
{
    private static array $providers = [];

    public static function register(string $id, int $priority, callable $provider): void
    {
        if (self::$providers === []) {
            add_action('admin_notices', [self::class, 'renderAdminNotice'], 0);
            // WooCommerce suppresses third-party notices on its payments settings page.
            add_action('woocommerce_settings_checkout', [self::class, 'renderPaymentNotice'], 5);
            add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
            add_action('admin_footer', [self::class, 'renderDismissForms']);
        }
        self::$providers[$id] = ['priority' => $priority, 'provider' => $provider];
    }

    private static function slides(): array
    {
        $providers = self::$providers;
        uasort($providers, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);
        $slides = [];
        foreach ($providers as $id => $entry) {
            $slide = ($entry['provider'])();
            if ($slide !== null) $slides[] = ['id' => $id] + $slide;
        }
        // A single-service fallback would recreate the retired standalone notice.
        return count($slides) > 1 ? $slides : [];
    }

    public static function isPaymentScreen(): bool
    {
        $screen = get_current_screen();
        return $screen && $screen->id === 'woocommerce_page_wc-settings'
            && ($_GET['tab'] ?? '') === 'checkout';
    }

    public static function enqueue(): void
    {
        $slides = self::slides();
        if ($slides === [] || !get_current_screen()) return;
        $root = dirname(__DIR__, 2);
        $plugin = $root . '/sync-basalam.php';
        wp_enqueue_style('basalam-admin-font-style', plugins_url('assets/css/font.css', $plugin), [],
            (string) filemtime($root . '/assets/css/font.css'));
        wp_enqueue_style('woosalam-admin-promotions', plugins_url('assets/css/admin-promotions.css', $plugin),
            ['basalam-admin-font-style'], (string) filemtime($root . '/assets/css/admin-promotions.css'));
        if (count($slides) > 1) {
            wp_enqueue_script('woosalam-admin-promotions', plugins_url('assets/js/admin-promotions.js', $plugin), [],
                (string) filemtime($root . '/assets/js/admin-promotions.js'), true);
        }
    }

    public static function renderAdminNotice(): void
    {
        if (!self::isPaymentScreen()) self::render();
    }

    public static function renderPaymentNotice(): void
    {
        if (self::isPaymentScreen()) self::render();
    }

    private static function render(): void
    {
        $slides = self::slides();
        if ($slides === []) return;
        require dirname(__DIR__, 2) . '/templates/admin/PromotionBanner.php';
    }

    public static function renderDismissForms(): void
    {
        // External forms keep dismissal independent of WooCommerce's settings form.
        foreach (self::slides() as $slide) {
            ?>
            <form id="woosalam-promotion-dismiss-<?php echo esc_attr($slide['id']); ?>" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" hidden>
                <input type="hidden" name="action" value="<?php echo esc_attr($slide['dismiss_action']); ?>">
                <?php wp_nonce_field($slide['dismiss_action']); ?>
            </form>
            <?php
        }
    }
}
