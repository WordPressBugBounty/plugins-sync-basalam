<?php

/** Offline module wiring check; run with PHP 8.2+ to exercise its gate. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

defined('ABSPATH') || define('ABSPATH', __DIR__ . '/wordpress/');
$wp_version = in_array('--old-wordpress', $argv ?? [], true) ? '6.4' : '6.5';
if (!in_array('--without-woocommerce', $argv ?? [], true)) {
    define('WC_VERSION', in_array('--old-woocommerce', $argv ?? [], true) ? '9.9.5' : '10.0.0');
}

$GLOBALS['sg_registration'] = ['hooks' => [], 'scripts' => [], 'styles' => [], 'localized' => [],
    'writes' => [], 'requests' => [], 'uuid' => 0, 'screen' => null, 'clear_events' => []];
$GLOBALS['sg_registration_options'] = ['sync_basalam_settings' => [
    'vendor_id' => 101, 'hamsalam_business_id' => 202, 'token' => 'fake-test-access-token',
    'refresh_token' => 'fake-test-refresh-token', 'salam_gate_draft_connection_id' => 'test-connection',
]];

function sgCallbackId($callback): string
{
    return is_array($callback) ? (string) $callback[0] . '::' . $callback[1] : (string) $callback;
}
function add_action($hook, $callback, $priority = 10, $acceptedArgs = 1)
{
    $GLOBALS['sg_registration']['hooks'][$hook][$priority][sgCallbackId($callback)] = [$callback, $acceptedArgs];
    return true;
}
function add_filter($hook, $callback, $priority = 10, $acceptedArgs = 1)
{
    return add_action($hook, $callback, $priority, $acceptedArgs);
}
function has_action($hook, $callback = false)
{
    $priorities = $GLOBALS['sg_registration']['hooks'][$hook] ?? [];
    foreach ($priorities as $priority => $entries) {
        if ($callback === false || isset($entries[sgCallbackId($callback)])) return $priority;
    }
    return false;
}
function apply_filters($hook, $value, ...$args)
{
    $priorities = $GLOBALS['sg_registration']['hooks'][$hook] ?? [];
    ksort($priorities);
    foreach ($priorities as $entries) {
        foreach ($entries as [$callback, $acceptedArgs]) {
            $value = $callback(...array_slice(array_merge([$value], $args), 0, $acceptedArgs));
        }
    }
    return $value;
}
function get_option($name, $default = false) { return $GLOBALS['sg_registration_options'][$name] ?? $default; }
function update_option(...$args) { throw new RuntimeException('Unexpected option write'); }
function add_option(...$args) { throw new RuntimeException('Unexpected option creation'); }
function delete_option(...$args) { throw new RuntimeException('Unexpected option deletion'); }
function wp_remote_request(...$args) { throw new RuntimeException('Unexpected HTTP request'); }
function wp_remote_get(...$args) { throw new RuntimeException('Unexpected HTTP GET'); }
function wp_remote_post(...$args) { throw new RuntimeException('Unexpected HTTP POST'); }
function wp_enqueue_style($handle, $url = '', $deps = [], $version = false, $media = 'all')
{
    $GLOBALS['sg_registration']['styles'][$handle] = compact('url', 'deps', 'version');
}
function wp_enqueue_script($handle, $url = '', $deps = [], $version = false, $footer = false)
{
    $GLOBALS['sg_registration']['scripts'][$handle] = compact('url', 'deps', 'version', 'footer');
}
function wp_localize_script($handle, $name, $data)
{
    $GLOBALS['sg_registration']['localized'][$handle][$name] = $data;
}
function plugins_url($relative = '', $pluginFile = '') { return 'https://example.test/wp-content/plugins/sync-basalam/' . ltrim($relative, '/'); }
function get_current_screen() { return $GLOBALS['sg_registration']['screen']; }
function current_user_can($capability) { return true; }
function get_current_user_id() { return 44; }
function get_site_url() { return 'https://example.test'; }
function wp_salt($scheme = '') { return 'fake-site-salt'; }
function wp_create_nonce($action = -1) { return 'test-nonce:' . $action; }
function wp_generate_uuid4() { return 'new-connection-' . ++$GLOBALS['sg_registration']['uuid']; }
function wp_unslash($value) { return $value; }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)); }
function esc_html($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function wp_clear_scheduled_hook($hook) { $GLOBALS['sg_registration']['clear_events'][] = $hook; }
function syncBasalamContainer() { throw new RuntimeException('Offline OAuth configuration fallback'); }

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use SyncBasalam\SalamGate\Module;
use SyncBasalam\SalamGate\Auth\OAuthScopes;

$checks = 0;
function sgAssert($condition, string $description): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($description);
    $checks++;
}
function sgReset(): void
{
    foreach (['registered', 'ownsShipping'] as $name) {
        $property = new ReflectionProperty(Module::class, $name);
        $property->setAccessible(true);
        $property->setValue(null, false);
    }
    foreach (['hooks', 'scripts', 'styles', 'localized', 'clear_events'] as $key) $GLOBALS['sg_registration'][$key] = [];
    $GLOBALS['sg_registration']['screen'] = null;
    $_GET = [];
}
function sgEntries(string $hook): array
{
    $entries = [];
    foreach ($GLOBALS['sg_registration']['hooks'][$hook] ?? [] as $priority) $entries = array_merge($entries, array_values($priority));
    return $entries;
}
function sgAssetsReset(): void
{
    foreach (['scripts', 'styles', 'localized'] as $key) $GLOBALS['sg_registration'][$key] = [];
}

$originalOptions = $GLOBALS['sg_registration_options'];
Module::deactivate();
sgAssert($GLOBALS['sg_registration']['clear_events'] === ['salam_gate_sync_parcel_statuses'], 'Deactivation before registration must clear an orphan shared event');
sgAssert(!class_exists('SyncBasalam\\SalamGate\\Services\\StatusSyncScheduler', false), 'Early deactivation must not load the scheduler domain');
sgReset();
Module::register();
if (!Module::runtimeSupported()) {
    sgAssert(!Module::ownsShipping(), 'Unsupported runtime must not become shipping owner');
    sgAssert(count(sgEntries('admin_notices')) === 1, 'Unsupported runtime needs a scoped notice');
    sgAssert(!class_exists('SyncBasalam\\SalamGate\\Admin\\AccountStateRouter', false), 'Unsupported runtime must not load domain classes');
    $_GET['page'] = 'sync_basalam';
    ob_start(); Module::runtimeNotice(); $notice = ob_get_clean();
    sgAssert(strpos($notice, '۶۴ بیتی') !== false, 'Runtime notice must explain the requirement');
    $_GET['page'] = 'unrelated';
    ob_start(); Module::runtimeNotice(); $notice = ob_get_clean();
    sgAssert($notice === '', 'Runtime notice must stay off unrelated screens');
    Module::deactivate();
    sgAssert($GLOBALS['sg_registration']['clear_events'] === ['salam_gate_sync_parcel_statuses'], 'Unsupported runtime must remove an orphan cron on explicit deactivation');
    sgAssert(!class_exists('SyncBasalam\\SalamGate\\Services\\StatusSyncScheduler', false), 'Unsupported-runtime deactivation must not load scheduler domain');
    sgAssert($GLOBALS['sg_registration_options'] === $originalOptions, 'Deactivation must preserve settings and financial evidence');
    echo "SalamGate unsupported-runtime registration: {$checks} checks passed\n";
    exit(0);
}

sgAssert(Module::ownsShipping(), 'Base alone must own shipping');
sgAssert($GLOBALS['sg_registration_options'] === $originalOptions, 'Registering must not change credentials/settings');
$hooksBefore = $GLOBALS['sg_registration']['hooks'];
Module::register();
sgAssert($hooksBefore === $GLOBALS['sg_registration']['hooks'], 'Registration must be idempotent');

$actions = [
    'account_state', 'tracking', 'basalam_order_state', 'save_sync_settings', 'sync_now', 'activation_form',
    'onboarding_bootstrap', 'post_offices_references', 'provider_locations', 'provider_nodes', 'register_shop',
    'stats', 'settings_data', 'update_address', 'update_shop', 'calculate', 'send_bootstrap', 'basalam_orders',
    'basalam_order_select', 'website_orders', 'website_order_select', 'order_preview', 'send_creation_status',
    'search_products', 'payment_balance', 'send_upsert', 'send_select_quote', 'send_quotes', 'send_pay',
    'refresh_shops', 'shop_activate', 'shop_deactivate', 'flush_references', 'bulk_bootstrap', 'bulk_item',
    'prepare_parcel', 'order_parcels', 'upsert_parcel', 'get_quotes', 'select_quote', 'pay_parcel', 'get_track',
    'get_label', 'label_status', 'refresh_metabox', 'parcels_list', 'label_preview', 'parcel_checkout',
    'parcel_checkout_select', 'parcel_checkout_pay', 'parcel_detail', 'parcel_cancel', 'bulk_label', 'bulk_label_by_date', 'label_poll',
];
foreach ($actions as $action) {
    $hook = 'wp_ajax_salam_gate_' . $action;
    $entries = sgEntries($hook);
    sgAssert(count($entries) === 1, 'Expected exactly one callback: ' . $hook);
    sgAssert(is_callable($entries[0][0]), 'Registered callback must resolve: ' . $hook);
    sgAssert(sgEntries('wp_ajax_nopriv_salam_gate_' . $action) === [], 'No anonymous shipping callback: ' . $action);
}
$actualActions = array_filter(array_keys($GLOBALS['sg_registration']['hooks']), static function ($hook) {
    return strpos($hook, 'wp_ajax_salam_gate_') === 0;
});
sgAssert(count($actualActions) === count($actions), 'Action inventory must match the Pro feature');
foreach (['sync_basalam_oauth_data_url', 'sync_basalam_oauth_default_client_id', 'sync_basalam_oauth_default_redirect_uri',
    'sync_basalam_oauth_non_vendor_data'] as $hook) sgAssert(sgEntries($hook) === [], 'Do not override ordinary OAuth: ' . $hook);
$scopes = 'vendor.product.read vendor.salamgate.read customer.profile.read';
$extended = OAuthScopes::includeRequired($scopes);
sgAssert($extended === OAuthScopes::includeRequired($extended), 'Scopes must be deduplicated');
sgAssert(strpos($extended, 'vendor.product.read') !== false && strpos($extended, 'customer.profile.read') !== false, 'Existing consent must remain');
sgAssert(strpos($extended, '.admin') === false, 'Never request admin SalamGate scopes');
$urls = (new SyncBasalam\Admin\Settings\OAuthManager())->getOAuthUrls();
parse_str((string) parse_url($urls['url_req_token'], PHP_URL_QUERY), $oauthQuery);
sgAssert(($oauthQuery['client_id'] ?? '') === '779', 'Ordinary fallback client must stay 779');
sgAssert($urls['redirect_uri'] === SyncBasalam\Config\Endpoints::HAMSALAM_OAUTH_TOKEN, 'Ordinary callback must stay unchanged');
sgAssert(strpos($oauthQuery['scope'], 'vendor.salamgate.write') !== false, 'Ordinary OAuth URL must include shipping grants');
sgAssert($GLOBALS['sg_registration_options'] === $originalOptions, 'Building consent must not change saved settings');
$extra = apply_filters('sync_basalam_oauth_save_extra_data', ['other' => 'preserved']);
sgAssert($extra['other'] === 'preserved' && $extra['salam_gate_draft_connection_id'] === 'new-connection-1', 'Reconnect rotates only the returned draft scope');
sgAssert($GLOBALS['sg_registration_options'] === $originalOptions, 'Draft filter must leave persistence to OAuth callback');

Module::enqueueAssets('index.php');
sgAssert(array_keys($GLOBALS['sg_registration']['scripts']) === ['woosalam-send-draft'], 'Unrelated admin pages need only draft cleanup');
sgAssert($GLOBALS['sg_registration']['styles'] === [], 'Shipping CSS must not affect unrelated admin pages');
$draft = $GLOBALS['sg_registration']['localized']['woosalam-send-draft']['woosalamSendDraftConfig'];
sgAssert(preg_match('/^[a-f0-9]{64}$/D', $draft['scope']) === 1, 'Browser draft identity must be a digest');
sgAssert(strpos(json_encode($draft), 'fake-test-access-token') === false, 'No access token in browser configuration');
$oldDraftScope = $draft['scope'];
$GLOBALS['sg_registration_options']['sync_basalam_settings']['token'] = 'different-access-token';
sgAssetsReset(); Module::enqueueAssets('index.php');
sgAssert($GLOBALS['sg_registration']['localized']['woosalam-send-draft']['woosalamSendDraftConfig']['scope'] === $oldDraftScope, 'Access renewal must not rotate draft scope');
$GLOBALS['sg_registration_options'] = $originalOptions;

foreach (['salam_gate', 'salam_gate_parcels', 'salam_gate_status'] as $page) {
    sgAssetsReset(); $_GET['page'] = $page; Module::enqueueAssets('admin_page_' . $page);
    sgAssert(isset($GLOBALS['sg_registration']['scripts']['woosalam-salam-gate-script']), 'Shipping controller required for ' . $page);
    sgAssert(isset($GLOBALS['sg_registration']['styles']['woosalam-salam-gate-style']), 'Shipping style required for ' . $page);
    $controller = $GLOBALS['sg_registration']['scripts']['woosalam-salam-gate-script'];
    sgAssert(strpos($controller['url'], '/assets/salam-gate/js/index.js') !== false, 'Controller URL must use the base asset root');
    sgAssert($controller['deps'] === ['jquery', 'woosalam-send-draft', 'woosalam-feedback'], 'Controller dependencies must be preserved');
    $config = $GLOBALS['sg_registration']['localized']['woosalam-salam-gate-script']['basalam_ajax'];
    sgAssert($config['nonce'] === 'test-nonce:basalam_pro_nonce' && $config['ajaxurl'] === admin_url('admin-ajax.php'), 'Shared AJAX/nonce contract must be unchanged');
}
foreach (['shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders'] as $screenId) {
    sgAssetsReset(); $_GET = []; $GLOBALS['sg_registration']['screen'] = (object) ['id' => $screenId];
    Module::enqueueAssets();
    sgAssert(isset($GLOBALS['sg_registration']['scripts']['woosalam-salam-gate-script']), 'Order controller required for ' . $screenId);
}
$GLOBALS['sg_registration']['screen'] = null;
foreach (['sync_basalam', 'sync_basalam_help'] as $page) {
    sgAssetsReset(); $_GET = ['page' => $page]; Module::enqueueAssets();
    sgAssert(isset($GLOBALS['sg_registration']['styles']['woosalam-connection-gate']), 'Connection/help styles required for ' . $page);
    sgAssert(!isset($GLOBALS['sg_registration']['scripts']['woosalam-salam-gate-script']), 'Do not load shipping controller on ' . $page);
}
sgAssert($GLOBALS['sg_registration_options'] === $originalOptions, 'Asset loading must not write saved settings');
Module::deactivate();
sgAssert($GLOBALS['sg_registration']['clear_events'] === ['salam_gate_sync_parcel_statuses'], 'Base owner deactivation clears the shared event once');

class LegacyProBootstrapForRegistrationCheck {}
class_alias(LegacyProBootstrapForRegistrationCheck::class, 'SyncBasalam\\AddonPro\\ProVersionConfigure');
sgReset(); Module::register();
sgAssert(Module::ownsShipping(), 'Loaded Pro class without actual shipping hooks must not suppress base');
sgReset();
add_action('sync_basalam_after_register_menus', ['SyncBasalam\\AddonPro\\SalamGate\\Admin\\Menus', 'register'], 11);
$legacyHooks = $GLOBALS['sg_registration']['hooks'];
Module::register();
sgAssert(!Module::ownsShipping(), 'Legacy Pro must remain sole owner');
sgAssert($GLOBALS['sg_registration']['hooks'] === $legacyHooks, 'Base must not register any shipping hook with legacy Pro');
Module::enqueueAssets(); Module::deactivate();
sgAssert($GLOBALS['sg_registration']['scripts'] === [] && $GLOBALS['sg_registration']['styles'] === [], 'Base must not enqueue assets with legacy Pro');
sgAssert($GLOBALS['sg_registration']['clear_events'] === [], 'Base must not clear Pro-owned cron');
sgReset();
add_action('wp_ajax_salam_gate_account_state', ['SyncBasalam\\AddonPro\\SalamGate\\Admin\\Ajax\\StatusActions', 'accountState']);
Module::register();
sgAssert(!Module::ownsShipping(), 'Legacy account-state callback also proves shipping ownership');
sgReset(); Module::register();
sgAssert(Module::ownsShipping() && count(sgEntries('init')) === 1, 'After Pro deactivation, base schedules normal reconciliation at init');

echo "SalamGate module registration: {$checks} checks passed\n";
