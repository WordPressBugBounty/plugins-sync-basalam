<?php

namespace SyncBasalam\SalamGate;

use SyncBasalam\SalamGate\Admin\Ajax;
use SyncBasalam\SalamGate\Services\StatusSyncScheduler;

defined('ABSPATH') || exit;

/** Register one shipping owner after all active plugin bootstraps have loaded. */
final class Module
{
    private static bool $registered = false;
    private static bool $ownsShipping = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        // Current Pro releases bootstrap their own module before plugins_loaded.
        // Two namespaces would still share AJAX endpoints, money guards and cron.
        if (self::legacyProOwnsShipping()) {
            return;
        }
        if (!class_exists('WooCommerce')) {
            return;
        }

        self::$ownsShipping = true;
        add_action('sync_basalam_after_register_menus', [Admin\Menus::class, 'register'], 11);
        foreach ([Admin\AccountStateRouter::class, Admin\HelpContent::class,
            Admin\ConnectionGate::class, Auth\OAuthScopes::class, Order\BasalamOrderProducts::class,
            StatusSyncScheduler::class, Admin\OrderMetabox::class, Admin\OrderShippingStatus::class,
            Admin\OrderBulkActions::class] as $registrar) {
            $registrar::register();
        }
        foreach (self::ajaxCallbacks() as $action => $callback) {
            add_action('wp_ajax_' . $action, $callback);
        }
        add_action('admin_init', [self::class, 'guardDisabledActions'], 1);

        // Keep the ordinary application's own OAuth client and callback URL.
        add_filter('sync_basalam_oauth_save_extra_data', [self::class, 'draftConnectionData']);
        // The main handles must exist before the domain assets enqueue dependencies.
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets'], 5);
    }

    public static function ownsShipping(): bool
    {
        return self::$ownsShipping;
    }

    public static function legacyProOwnsShipping(): bool
    {
        if (!class_exists('SyncBasalam\\AddonPro\\ProVersionConfigure', false)) {
            return false;
        }

        // Do not autoload an installed-but-inactive Pro or suppress shipping for
        // an older Pro release that does not actually register this feature.
        return has_action('sync_basalam_after_register_menus',
            ['SyncBasalam\\AddonPro\\SalamGate\\Admin\\Menus', 'register']) !== false
            || has_action('wp_ajax_salam_gate_account_state',
                ['SyncBasalam\\AddonPro\\SalamGate\\Admin\\Ajax\\StatusActions', 'accountState']) !== false;
    }

    public static function deactivate(): void
    {
        if (!self::legacyProOwnsShipping()) {
            // Explicit deactivation must also clean an event left by a previous
            // registration or a bootstrap stopped by the force-update gate.
            // Keep this independent of the shipping domain classes and leave all
            // durable creation/payment/cancellation evidence untouched.
            wp_clear_scheduled_hook('salam_gate_sync_parcel_statuses');
        }
        // Pro deactivation can clear the shared event. On the next request the
        // base becomes owner, and the normal init reconciliation restores it.
    }

    public static function draftConnectionData(array $data): array
    {
        $data['salam_gate_draft_connection_id'] = wp_generate_uuid4();
        return $data;
    }

    /** Global draft cleanup is intentional; shipping controllers are not global. */
    public static function enqueueAssets($hook = ''): void
    {
        if (!self::$ownsShipping) {
            return;
        }
        self::enqueueScript('woosalam-send-draft', 'js/salam-gate-send-draft.js', []);

        // Read directly: an asset request must not initialize/write defaults or
        // contact an account API just to identify a browser draft's namespace.
        $settings = get_option('sync_basalam_settings', []);
        $settings = is_array($settings) ? $settings : [];
        $generation = $settings['salam_gate_draft_connection_id'] ?? null;
        $vendorId = $settings['vendor_id'] ?? null;
        $businessId = $settings['hamsalam_business_id'] ?? null;
        $connection = is_string($generation) && $generation !== '' ? 'connection:' . $generation
            : (!empty($vendorId) || !empty($businessId) ? 'account:' . (string) $vendorId . ':' . (string) $businessId
                : 'legacy:' . hash('sha256', (string) ($settings['token'] ?? '')));
        wp_localize_script('woosalam-send-draft', 'woosalamSendDraftConfig', [
            'scope' => hash_hmac('sha256', get_current_user_id() . '|' . get_site_url() . '|' . $connection, wp_salt('auth')),
        ]);

        $shippingScreen = self::isShippingScreen((string) $hook);
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $isOrderScreen = in_array($screen->id ?? $hook, ['shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders'], true);
        if ($isOrderScreen && !FeatureAvailability::isVisible()) return;
        $supportScreen = in_array(self::page(), ['sync_basalam', 'sync_basalam_help'], true);
        if (!$shippingScreen && !$supportScreen) {
            return;
        }
        self::enqueueStyle('woosalam-salam-gate-style', 'styles/style.css', []);
        self::enqueueStyle('woosalam-connection-gate', 'styles/salam-gate-connection.css', ['woosalam-salam-gate-style']);
        if (!$shippingScreen) {
            return;
        }
        self::enqueueScript('woosalam-feedback', 'js/salam-gate-feedback.js', []);
        self::enqueueScript('woosalam-salam-gate-script', 'js/index.js', ['jquery', 'woosalam-send-draft', 'woosalam-feedback']);
        wp_localize_script('woosalam-salam-gate-script', 'basalam_ajax', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('basalam_pro_nonce'),
        ]);
    }

    private static function isShippingScreen(string $hook = ''): bool
    {
        if (in_array(self::page(), ['salam_gate', 'salam_gate_parcels', 'salam_gate_status'], true)) {
            return true;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && in_array($screen->id ?? '', ['shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders'], true)) {
            return true;
        }
        return in_array($hook, ['shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders'], true);
    }

    private static function page(): string
    {
        return isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    }

    private static function enqueueStyle(string $handle, string $relative, array $dependencies): void
    {
        $file = Paths::assetPath($relative);
        wp_enqueue_style($handle, Paths::assetsUrl($relative), $dependencies, file_exists($file) ? (string) filemtime($file) : null);
    }

    private static function enqueueScript(string $handle, string $relative, array $dependencies): void
    {
        $file = Paths::assetPath($relative);
        wp_enqueue_script($handle, Paths::assetsUrl($relative), $dependencies, file_exists($file) ? (string) filemtime($file) : null, true);
    }

    /** Reject stale screens locally before their controllers can initialize any remote services. */
    public static function guardDisabledActions(): void
    {
        if (!function_exists('wp_doing_ajax') || !wp_doing_ajax()) return;
        $action = $_REQUEST['action'] ?? '';
        if (!is_string($action) || !isset(self::ajaxCallbacks()[$action])) return;
        if (!Services\SiteActivation::isEnabled()) {
            // Re-enabling performs its own explicit verification. The availability
            // probe is cache-only; legacy refresh_shops is NOT an opt-out exception.
            if (in_array($action, ['salam_gate_site_activation', 'salam_gate_order_availability'], true)) return;
            wp_send_json_error(['message' => 'سلام‌گیت در این سایت غیرفعال است؛ ابتدا آن را از بخش ارسال مرسوله فعال کنید.',
                'code' => 'site_disabled', 'kind' => 'validation', 'errors' => [], 'request_dispatched' => false], 409);
            return;
        }
        Ajax\AccountAccess::guard($action);
    }

    /** Exact public/admin shipping action inventory; no anonymous mutation hooks. */
    private static function ajaxCallbacks(): array
    {
        return [
            'salam_gate_site_activation' => [Ajax\StatusActions::class, 'siteActivation'],
            'salam_gate_order_availability' => [Admin\OrderFeatureGate::class, 'availability'],
            'salam_gate_save_sync_settings' => [Ajax\SyncActions::class, 'save'],
            'salam_gate_sync_now' => [Ajax\SyncActions::class, 'run'],
            'salam_gate_activation_form' => [Ajax\OnboardingActions::class, 'form'],
            'salam_gate_onboarding_bootstrap' => [Ajax\OnboardingActions::class, 'bootstrap'],
            'salam_gate_post_offices_references' => [Ajax\OnboardingActions::class, 'postOfficesReferences'],
            'salam_gate_provider_locations' => [Ajax\OnboardingActions::class, 'providerLocations'],
            'salam_gate_provider_nodes' => [Ajax\OnboardingActions::class, 'providerNodes'],
            'salam_gate_register_shop' => [Ajax\OnboardingActions::class, 'register'],
            'salam_gate_stats' => [Ajax\DashboardActions::class, 'stats'],
            'salam_gate_settings_data' => [Ajax\DashboardActions::class, 'settingsData'],
            'salam_gate_update_address' => [Ajax\DashboardActions::class, 'updateAddress'],
            'salam_gate_update_shop' => [Ajax\DashboardActions::class, 'updateShop'],
            'salam_gate_calculate' => [Ajax\OnboardingActions::class, 'calculate'],
            'salam_gate_send_bootstrap' => [Ajax\SendActions::class, 'bootstrap'],
            'salam_gate_basalam_orders' => [Ajax\BasalamOrdersActions::class, 'listOrders'],
            'salam_gate_basalam_order_select' => [Ajax\BasalamOrdersActions::class, 'selectOrder'],
            'salam_gate_website_orders' => [Ajax\WebsiteOrdersActions::class, 'listOrders'],
            'salam_gate_website_order_select' => [Ajax\WebsiteOrdersActions::class, 'selectOrder'],
            'salam_gate_order_preview' => [Ajax\OrderPreviewActions::class, 'preview'],
            'salam_gate_send_creation_status' => [Ajax\SendActions::class, 'creationStatus'],
            'salam_gate_search_products' => [Ajax\ProductSearchActions::class, 'search'],
            'salam_gate_payment_balance' => [Ajax\PaymentBalanceActions::class, 'balance'],
            'salam_gate_send_upsert' => [Ajax\SendActions::class, 'upsert'],
            'salam_gate_send_select_quote' => [Ajax\SendActions::class, 'selectQuote'],
            'salam_gate_send_quotes' => [Ajax\SendActions::class, 'quotes'],
            'salam_gate_send_pay' => [Ajax\SendActions::class, 'pay'],
            'salam_gate_refresh_shops' => [Ajax\StatusActions::class, 'refreshShops'],
            'salam_gate_shop_activate' => [Ajax\StatusActions::class, 'activateShop'],
            'salam_gate_shop_deactivate' => [Ajax\StatusActions::class, 'deactivateShop'],
            'salam_gate_flush_references' => [Ajax\StatusActions::class, 'flushReferences'],
            'salam_gate_bulk_bootstrap' => [Ajax\BulkShippingActions::class, 'bootstrap'],
            'salam_gate_bulk_item' => [Ajax\BulkShippingActions::class, 'item'],
            'salam_gate_prepare_parcel' => [Ajax\ParcelActions::class, 'prepareParcel'],
            'salam_gate_order_parcels' => [Ajax\ParcelActions::class, 'nativeParcels'],
            'salam_gate_upsert_parcel' => [Ajax\ParcelActions::class, 'upsertParcel'],
            'salam_gate_get_quotes' => [Ajax\ParcelActions::class, 'getQuotes'],
            'salam_gate_select_quote' => [Ajax\ParcelActions::class, 'selectQuote'],
            'salam_gate_pay_parcel' => [Ajax\ParcelActions::class, 'payParcel'],
            'salam_gate_get_track' => [Ajax\ParcelActions::class, 'getTrack'],
            'salam_gate_get_label' => [Ajax\ParcelActions::class, 'getLabel'],
            'salam_gate_label_status' => [Ajax\ParcelActions::class, 'labelStatus'],
            'salam_gate_refresh_metabox' => [Ajax\ParcelActions::class, 'refreshMetabox'],
            'salam_gate_parcels_list' => [Ajax\ParcelsListActions::class, 'parcelsList'],
            'salam_gate_label_preview' => [Ajax\ParcelsListActions::class, 'labelPreview'],
            'salam_gate_parcel_checkout' => [Ajax\ParcelsListActions::class, 'checkout'],
            'salam_gate_parcel_checkout_select' => [Ajax\ParcelsListActions::class, 'checkoutSelect'],
            'salam_gate_parcel_checkout_pay' => [Ajax\ParcelsListActions::class, 'checkoutPay'],
            'salam_gate_parcel_detail' => [Ajax\ParcelsListActions::class, 'parcelDetail'],
            'salam_gate_parcel_cancel' => [Ajax\ParcelsListActions::class, 'cancelParcel'],
            'salam_gate_bulk_label' => [Ajax\ParcelsListActions::class, 'bulkLabel'],
            'salam_gate_bulk_label_by_date' => [Ajax\ParcelsListActions::class, 'bulkLabelByDate'],
            'salam_gate_label_poll' => [Ajax\ParcelsListActions::class, 'labelPoll'],
        ];
    }
}
