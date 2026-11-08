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
            Admin\ConnectionGate::class, Order\BasalamOrderProducts::class,
            StatusSyncScheduler::class, Services\StorageMaintenance::class, Admin\OrderMetabox::class, Admin\OrderShippingStatus::class,
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
            wp_clear_scheduled_hook('salam_gate_storage_maintenance');
        }
        // Pro deactivation can clear the shared event. On the next request the
        // base becomes owner, and the normal init reconciliation restores it.
    }

    public static function draftConnectionData(array $data): array
    {
        $data['salam_gate_draft_connection_id'] = wp_generate_uuid4();
        return $data;
    }

    public static function enqueueAssets($hook = ''): void
    {
        if (self::$ownsShipping) Admin\Assets\AssetRegistrar::enqueue((string) $hook);
    }

    /** Reject stale screens locally before their controllers can initialize any remote services. */
    public static function guardDisabledActions(): void
    {
        if (!function_exists('wp_doing_ajax') || !wp_doing_ajax()) return;
        $action = $_REQUEST['action'] ?? '';
        if (!is_string($action) || ($definition = Ajax\ActionRegistry::get($action)) === null) return;
        if (!Services\SiteActivation::isEnabled()) {
            // Re-enabling performs its own explicit verification. The availability
            // probe is cache-only; legacy refresh_shops is NOT an opt-out exception.
            if (!$definition->requiresSite()) return;
            \SyncBasalam\SalamGate\Admin\Ajax\ResponseBoundary::error(['message' => 'سلام‌گیت در این سایت غیرفعال است؛ ابتدا آن را از بخش ارسال مرسوله فعال کنید.',
                'code' => 'site_disabled', 'kind' => 'validation', 'errors' => [], 'request_dispatched' => false], 409);
            return;
        }
        Ajax\AccountAccess::guard($action);
    }

    /** Exact public/admin shipping action inventory; no anonymous mutation hooks. */
    private static function ajaxCallbacks(): array
    {
        return array_map(static fn (Ajax\ActionDefinition $definition): array => $definition->callback(), Ajax\ActionRegistry::all());
    }
}
