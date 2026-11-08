<?php
namespace SyncBasalam\SalamGate\Admin\Ajax;

defined('ABSPATH') || exit;

/** Every endpoint declares access, site/account readiness and side effects. */
final class ActionRegistry
{
    public static function all(): array
    {
        return [
            'salam_gate_site_activation' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\StatusActions::class, 'siteActivation'], ['manage_options'], true, 'control', 'mutation', false),
            'salam_gate_order_availability' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\OrderFeatureGate::class, 'availability'], ['manage_woocommerce'], true, 'control', 'read', false),
            'salam_gate_save_sync_settings' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\SyncActions::class, 'save'], ['manage_options'], true, 'control', 'mutation', true),
            'salam_gate_sync_now' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\SyncActions::class, 'run'], ['manage_options'], true, 'active', 'mutation', true),
            'salam_gate_activation_form' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\OnboardingActions::class, 'form'], ['manage_options'], true, 'registration', 'read', true),
            'salam_gate_onboarding_bootstrap' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\OnboardingActions::class, 'bootstrap'], ['manage_options'], true, 'bootstrap', 'read', true),
            'salam_gate_post_offices_references' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\OnboardingActions::class, 'postOfficesReferences'], ['manage_options'], true, 'active', 'read', true),
            'salam_gate_provider_locations' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\OnboardingActions::class, 'providerLocations'], ['manage_options'], true, 'references', 'read', true),
            'salam_gate_provider_nodes' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\OnboardingActions::class, 'providerNodes'], ['manage_options'], true, 'references', 'read', true),
            'salam_gate_register_shop' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\OnboardingActions::class, 'register'], ['manage_options'], true, 'registration', 'mutation', true),
            'salam_gate_stats' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\DashboardActions::class, 'stats'], ['manage_options'], true, 'active', 'read', true),
            'salam_gate_settings_data' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\DashboardActions::class, 'settingsData'], ['manage_options'], true, 'active', 'read', true),
            'salam_gate_update_address' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\DashboardActions::class, 'updateAddress'], ['manage_options'], true, 'active', 'mutation', true),
            'salam_gate_update_shop' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\DashboardActions::class, 'updateShop'], ['manage_options'], true, 'active', 'mutation', true),
            'salam_gate_calculate' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\OnboardingActions::class, 'calculate'], ['manage_options'], true, 'active', 'read', true),
            'salam_gate_send_bootstrap' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\SendActions::class, 'bootstrap'], ['manage_woocommerce'], true, 'active', 'read', true),
            'salam_gate_basalam_orders' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\BasalamOrdersActions::class, 'listOrders'], ['manage_options', 'manage_woocommerce'], true, 'active', 'read', true),
            'salam_gate_basalam_order_select' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\BasalamOrdersActions::class, 'selectOrder'], ['manage_options', 'manage_woocommerce'], true, 'active', 'read', true),
            'salam_gate_website_orders' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\WebsiteOrdersActions::class, 'listOrders'], ['manage_options', 'manage_woocommerce'], true, 'active', 'read', true),
            'salam_gate_website_order_select' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\WebsiteOrdersActions::class, 'selectOrder'], ['manage_options', 'manage_woocommerce'], true, 'active', 'read', true),
            'salam_gate_order_preview' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\OrderPreviewActions::class, 'preview'], ['manage_options', 'manage_woocommerce'], true, 'active', 'read', true),
            'salam_gate_send_creation_status' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\SendActions::class, 'creationStatus'], ['manage_woocommerce'], true, 'active', 'read', true),
            'salam_gate_search_products' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\ProductSearchActions::class, 'search'], ['manage_woocommerce'], true, 'active', 'read', true),
            'salam_gate_payment_balance' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\PaymentBalanceActions::class, 'balance'], ['manage_woocommerce'], true, 'active', 'read', true),
            'salam_gate_send_upsert' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\SendActions::class, 'upsert'], ['manage_woocommerce'], true, 'active', 'mutation', true),
            'salam_gate_send_select_quote' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\SendActions::class, 'selectQuote'], ['manage_woocommerce'], true, 'active', 'quote', true),
            'salam_gate_send_quotes' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\SendActions::class, 'quotes'], ['manage_woocommerce'], true, 'active', 'quote', true),
            'salam_gate_send_pay' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\SendActions::class, 'pay'], ['manage_woocommerce'], true, 'active', 'mutation', true),
            'salam_gate_refresh_shops' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\StatusActions::class, 'refreshShops'], ['manage_options'], true, 'control', 'read', true),
            'salam_gate_shop_activate' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\StatusActions::class, 'activateShop'], ['manage_options'], true, 'activate', 'mutation', true),
            'salam_gate_shop_deactivate' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\StatusActions::class, 'deactivateShop'], ['manage_options'], true, 'active', 'mutation', true),
            'salam_gate_flush_references' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\StatusActions::class, 'flushReferences'], ['manage_options'], true, 'control', 'mutation', true),
            'salam_gate_bulk_bootstrap' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\BulkShippingActions::class, 'bootstrap'], ['manage_woocommerce'], true, 'active', 'read', true),
            'salam_gate_bulk_item' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\BulkShippingActions::class, 'item'], ['manage_woocommerce'], true, 'active', 'mutation', true),
            'salam_gate_parcel_references' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\ParcelActions::class, 'references'], ['manage_woocommerce'], true, 'active', 'read', true),
            'salam_gate_prepare_parcel' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\ParcelActions::class, 'prepareParcel'], ['manage_woocommerce'], true, 'active', 'read', true),
            'salam_gate_order_parcels' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\ParcelActions::class, 'nativeParcels'], ['manage_woocommerce'], true, 'active', 'read', true),
            'salam_gate_upsert_parcel' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\ParcelActions::class, 'upsertParcel'], ['manage_woocommerce'], true, 'active', 'mutation', true),
            'salam_gate_get_quotes' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\ParcelActions::class, 'getQuotes'], ['manage_woocommerce'], true, 'active', 'quote', true),
            'salam_gate_select_quote' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\ParcelActions::class, 'selectQuote'], ['manage_woocommerce'], true, 'active', 'quote', true),
            'salam_gate_pay_parcel' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\ParcelActions::class, 'payParcel'], ['manage_woocommerce'], true, 'active', 'mutation', true),
            'salam_gate_get_track' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\ParcelActions::class, 'getTrack'], ['manage_woocommerce'], true, 'active', 'read', true),
            'salam_gate_get_label' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\ParcelActions::class, 'getLabel'], ['manage_woocommerce'], true, 'active', 'mutation', true),
            'salam_gate_label_status' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\ParcelActions::class, 'labelStatus'], ['manage_woocommerce'], true, 'active', 'read', true),
            'salam_gate_refresh_metabox' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\ParcelActions::class, 'refreshMetabox'], ['manage_woocommerce'], true, 'active', 'read', true),
            'salam_gate_parcels_list' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\ParcelsListActions::class, 'parcelsList'], ['manage_woocommerce'], true, 'active', 'read', true),
            'salam_gate_label_preview' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\ParcelsListActions::class, 'labelPreview'], ['manage_woocommerce'], true, 'active', 'read', true),
            'salam_gate_parcel_checkout' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\ParcelsListActions::class, 'checkout'], ['manage_woocommerce'], true, 'active', 'quote', true),
            'salam_gate_parcel_checkout_select' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\ParcelsListActions::class, 'checkoutSelect'], ['manage_woocommerce'], true, 'active', 'quote', true),
            'salam_gate_parcel_checkout_pay' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\ParcelsListActions::class, 'checkoutPay'], ['manage_woocommerce'], true, 'active', 'mutation', true),
            'salam_gate_parcel_detail' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\ParcelsListActions::class, 'parcelDetail'], ['manage_woocommerce'], true, 'active', 'read', true),
            'salam_gate_parcel_cancel' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\ParcelsListActions::class, 'cancelParcel'], ['manage_woocommerce'], true, 'active', 'mutation', true),
            'salam_gate_bulk_label' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\ParcelsListActions::class, 'bulkLabel'], ['manage_woocommerce'], true, 'active', 'mutation', true),
            'salam_gate_bulk_label_by_date' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\ParcelsListActions::class, 'bulkLabelByDate'], ['manage_woocommerce'], true, 'active', 'mutation', true),
            'salam_gate_label_poll' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\ParcelsListActions::class, 'labelPoll'], ['manage_woocommerce'], true, 'active', 'read', true),
            'salam_gate_account_state' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\StatusActions::class, 'accountState'], ['manage_options'], true, 'independent', 'read', false),
            'salam_gate_tracking' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\Ajax\TrackingActions::class, 'lookup'], ['manage_options', 'manage_woocommerce'], false, 'independent', 'read', false),
            'salam_gate_basalam_order_state' => new ActionDefinition([\SyncBasalam\SalamGate\Admin\OrderMetabox::class, 'sourceState'], ['manage_woocommerce'], true, 'independent', 'read', false),
        ];
    }

    public static function get(string $action): ?ActionDefinition
    {
        return self::all()[$action] ?? null;
    }
}
