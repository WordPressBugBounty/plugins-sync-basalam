<?php
namespace SyncBasalam\SalamGate\Admin\Assets;

use SyncBasalam\SalamGate\Paths;
use SyncBasalam\SalamGate\FeatureAvailability;

defined('ABSPATH') || exit;

final class AssetRegistrar
{
    public static function enqueue(string $hook): void
    {
        self::script('woosalam-send-draft');
        $settings = get_option('sync_basalam_settings', []);
        $settings = is_array($settings) ? $settings : [];
        $generation = $settings['salam_gate_draft_connection_id'] ?? null;
        $vendor = $settings['vendor_id'] ?? null;
        $business = $settings['hamsalam_business_id'] ?? null;
        $connection = is_string($generation) && $generation !== '' ? 'connection:' . $generation
            : (!empty($vendor) || !empty($business) ? 'account:' . (string) $vendor . ':' . (string) $business
                : 'legacy:' . hash('sha256', (string) ($settings['token'] ?? '')));
        wp_localize_script('woosalam-send-draft', 'woosalamSendDraftConfig', [
            'scope' => hash_hmac('sha256', get_current_user_id() . '|' . get_site_url() . '|' . $connection, wp_salt('auth')),
        ]);
        $page = is_string($_GET['page'] ?? null) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $view = is_string($_GET['view'] ?? null) ? sanitize_key(wp_unslash($_GET['view'])) : '';
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $screenId = $screen->id ?? $hook;
        $order = in_array($screenId, ['shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders'], true);
        $shipping = in_array($page, ['salam_gate', 'salam_gate_parcels', 'salam_gate_status'], true) || strpos($hook, 'salam_gate') !== false;
        if ($order && !FeatureAvailability::isVisible()) return;
        if (!$order && !$shipping) {
            if (in_array($page, ['sync_basalam', 'sync_basalam_help'], true)) self::style('woosalam-connection-gate');
            return;
        }
        self::base('basalam-admin-font-style', 'assets/css/font.css');
        self::base('basalam-admin-toast-style', 'assets/css/toast.css');
        self::base('basalam-admin-toast-script', 'assets/js/toast.js', true);
        if ($shipping) self::base('basalam-admin-style', 'assets/css/style.css');
        self::style('woosalam-connection-gate');
        self::style('woosalam-salam-gate-admin');
        self::style('woosalam-salam-gate-' . ($order ? 'order' : 'layout'));
        foreach (['feedback', 'payment', 'pickers', 'validation'] as $name) self::style('woosalam-salam-gate-' . $name);
        self::script('woosalam-salam-gate-script');
        wp_localize_script('woosalam-salam-gate-script', 'basalam_ajax', ['ajaxurl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('basalam_pro_nonce')]);
        if ($shipping) {
            self::style('woosalam-salam-gate-activation');
            self::script('woosalam-salam-gate-navigation');
        }
        if ($shipping && in_array($view, ['settings', 'tracking'], true)) self::style('woosalam-salam-gate-' . $view);
        if ($shipping && $view === 'tracking') self::script('woosalam-salam-gate-tracking');
        if ($order || $view === 'send') {
            foreach (['order', 'order-parcels', 'order-source-status', 'product-search'] as $name) self::style('woosalam-salam-gate-' . $name);
        }
        if ($view === 'send') self::style('woosalam-salam-gate-basalam-orders');
        if ($page === 'salam_gate_parcels') {
            foreach (['parcels', 'order-parcels', 'cancel-dialog'] as $name) self::style('woosalam-salam-gate-' . $name);
        }
        $list = $order && in_array($screenId, ['edit-shop_order', 'woocommerce_page_wc-orders'], true) && empty($_GET['action']);
        if ($list) {
            self::style('woosalam-sg-order-status');
            if (current_user_can('manage_woocommerce')) {
                self::style('woosalam-salam-gate-order-list-preview');
                self::style('woosalam-salam-gate-bulk-shipping');
                self::script('woosalam-sg-order-list');
                wp_localize_script('woosalam-salam-gate-bulk-shipping', 'woosalamBulkShippingConfig', [
                    'maxItems' => 20, 'orderAction' => \SyncBasalam\SalamGate\Admin\OrderBulkActions::ACTION,
                ]);
            }
        }
        ScriptModules::enqueue($page, $view, $order);
        // These layers intentionally follow the selected component/page styles.
        $dependencies = array_keys(array_filter(AssetManifest::styles(), static fn ($entry, $handle): bool => wp_style_is($handle, 'enqueued'), ARRAY_FILTER_USE_BOTH));
        foreach (['motion', 'mobile', 'icons'] as $layer) {
            $handle = 'woosalam-salam-gate-' . $layer;
            $path = AssetManifest::styles()[$handle]['path'];
            wp_enqueue_style($handle, Paths::assetsUrl($path), $dependencies, (string) filemtime(Paths::assetPath($path)));
            $dependencies = [$handle];
        }
    }

    public static function help(): void
    {
        self::base('basalam-admin-style', 'assets/css/style.css');
        self::base('basalam-admin-font-style', 'assets/css/font.css');
        self::style('woosalam-salam-gate-help');
        $entry = AssetManifest::styles()['woosalam-salam-gate-icons'];
        wp_enqueue_style('woosalam-salam-gate-icons', Paths::assetsUrl($entry['path']), ['woosalam-salam-gate-help'], (string) filemtime(Paths::assetPath($entry['path'])));
        self::script('woosalam-salam-gate-help');
    }

    public static function script(string $handle): void
    {
        if (wp_script_is($handle, 'enqueued')) return;
        $all = AssetManifest::scripts();
        if (!isset($all[$handle])) throw new \LogicException('Unknown SalamGate script.');
        $entry = $all[$handle];
        foreach ($entry['deps'] as $dependency) if (isset($all[$dependency])) self::script($dependency);
        wp_enqueue_script($handle, Paths::assetsUrl($entry['path']), $entry['deps'], (string) filemtime(Paths::assetPath($entry['path'])), true);
    }

    public static function style(string $handle): void
    {
        if (wp_style_is($handle, 'enqueued')) return;
        $all = AssetManifest::styles();
        if (!isset($all[$handle])) throw new \LogicException('Unknown SalamGate style.');
        $entry = $all[$handle];
        foreach ($entry['deps'] as $dependency) if (isset($all[$dependency])) self::style($dependency);
        wp_enqueue_style($handle, Paths::assetsUrl($entry['path']), $entry['deps'], (string) filemtime(Paths::assetPath($entry['path'])));
    }

    public static function base(string $handle, string $relative, bool $script = false): void
    {
        $registered = $script ? wp_script_is($handle, 'registered') : wp_style_is($handle, 'registered');
        if (!$registered) {
            $url = plugins_url($relative, Paths::root() . '/sync-basalam.php');
            if ($script) wp_register_script($handle, $url, [], null, true);
            else wp_register_style($handle, $url, [], null);
        }
        if ($script) wp_enqueue_script($handle);
        else wp_enqueue_style($handle);
    }
}
