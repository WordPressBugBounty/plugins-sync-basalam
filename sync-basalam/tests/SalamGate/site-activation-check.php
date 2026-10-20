<?php
/** Offline regression for site opt-out, paid-registration preservation and Woo UI gates. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ABSPATH', __DIR__ . '/wordpress/');
require_once __DIR__ . '/wordpress-compat.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use SyncBasalam\SalamGate\Admin\AccountStateRouter;
use SyncBasalam\SalamGate\Admin\Ajax\StatusActions;
use SyncBasalam\SalamGate\Admin\OrderBulkActions;
use SyncBasalam\SalamGate\Admin\OrderFeatureGate;
use SyncBasalam\SalamGate\Admin\OrderMetabox;
use SyncBasalam\SalamGate\Admin\OrderShippingStatus;
use SyncBasalam\SalamGate\ClientFactory;
use SyncBasalam\SalamGate\FeatureAvailability;
use SyncBasalam\SalamGate\Module;
use SyncBasalam\SalamGate\SalamGateClient;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\ConnectionCache;
use SyncBasalam\SalamGate\Services\SiteActivation;

$options = ['sync_basalam_settings' => ['token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh'],
    'sync_basalam_salam_gate_settings' => ['status_sync_enabled' => 1],
    'salam_gate_payment_example' => ['state' => 'paid', 'parcel_id' => 100]];
$requests = $writes = $scripts = $cleared = [];
$screen = (object) ['id' => 'woocommerce_page_wc-orders'];
$allowed = true;
$remoteStatus = 14;
$events = (object) ['schedule' => 'hourly'];
$checks = 0;
class ActivationJsonExit extends RuntimeException {
    public bool $success;
    public array $data;
    public int $status;
    public function __construct(bool $success, array $data, int $status = 200) {
        parent::__construct();
        $this->success = $success;
        $this->data = $data;
        $this->status = $status;
    }
}
function check($condition, string $message): void { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
function get_option($key, $default = false) { global $options; return $options[$key] ?? $default; }
function update_option($key, $value, $autoload = null) { global $options, $writes; $options[$key] = $value; $writes[] = $key; return true; }
function delete_option($key) { global $options; unset($options[$key]); }
function syncBasalamSettings() { return new class { public function getSettings($key) { return get_option('sync_basalam_settings', [])[$key] ?? null; } }; }
function current_user_can($cap) { global $allowed; return $allowed; }
function wp_unslash($value) { return $value; }
function sanitize_text_field($value) { return trim($value); }
function wp_verify_nonce($nonce, $action) { return $nonce === 'synthetic-nonce' && $action === 'basalam_pro_nonce'; }
function wp_create_nonce($action) { return 'synthetic-nonce'; }
function wp_send_json_success($data, $status = 200) { throw new ActivationJsonExit(true, $data, $status); }
function wp_send_json_error($data, $status = 200) { throw new ActivationJsonExit(false, $data, $status); }
function wp_doing_ajax() { return true; }
function admin_url($path = '') { return 'http://example.test/wp-admin/' . $path; }
function get_current_screen() { global $screen; return $screen; }
function wp_get_scheduled_event($hook) { global $events; return $events; }
function wp_clear_scheduled_hook($hook) { global $events, $cleared; $cleared[] = $hook; $events = false; }
function wp_schedule_event($time, $interval, $hook) { global $events; $events = (object) ['schedule' => $interval]; return true; }
function plugins_url($path, $file) { return 'http://example.test/plugins/sync-basalam/' . $path; }
function wp_enqueue_script($handle, ...$args) { global $scripts; $scripts[$handle] = $args; }
function wp_localize_script(...$args) {}
function wp_json_encode($value) { return json_encode($value); }
function is_wp_error($value) { return false; }
function wp_remote_retrieve_response_code($response) { return $response['response']['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function wp_remote_request($url, $args) {
    global $requests, $remoteStatus;
    $requests[] = [$url, $args['method']];
    if ($args['method'] !== 'GET' || !str_ends_with($url, '/v1/shops?per_page=30')) throw new RuntimeException('Unexpected HTTP or financial write');
    return ['response' => ['code' => 200], 'body' => json_encode(['data' => [
        ['id' => 91, 'identifier' => 'synthetic-postal-shop', 'status' => ['id' => $remoteStatus], 'user' => ['id' => 77]],
    ]])];
}
class ActivationClient extends SalamGateClient { public function token(): ?string { return syncBasalamSettings()->getSettings('token') ?: null; } }
$client = new ActivationClient();
ClientFactory::$resolver = static fn () => $client;
$columns = ['order_number' => 'Order', 'order_status' => 'Status', 'basalam_order' => 'Basalam', 'order_total' => 'Total'];

check(SiteActivation::isEnabled(), 'Existing installations keep their active feature by default');
check(AccountStateRouter::resolveForPage()['state'] === 'loading', 'Cache miss is unknown, not active');
check(!FeatureAvailability::isVisible(), 'Unknown cache must not expose order shipping');
check(OrderShippingStatus::columns($columns) === $columns, 'Preserve ordinary/Basalam columns without activation');
check(OrderBulkActions::registerAction(['original' => 'Original']) === ['original' => 'Original'], 'No bulk entry before activation');
OrderFeatureGate::enqueueProbe();
check(isset($scripts['woosalam-sg-order-availability']), 'Resolve unknown account asynchronously');
check($requests === [], 'Rendering and enqueueing never contact the provider');

foreach (['not_registered' => [], 'pending' => [['id' => 91, 'status_id' => 15]],
    'inactive' => [['id' => 91, 'status_id' => 16]], 'rejected' => [['id' => 91, 'status_id' => 17]],
    'active' => [['id' => 91, 'status_id' => 14]]] as $state => $shops) {
    ConnectionCache::set('shops', ['shops' => $shops], 600);
    check(AccountStateRouter::resolveForPage()['state'] === $state, 'Account state preserved: ' . $state);
    check(FeatureAvailability::isVisible() === ($state === 'active'), 'Only an active shop may expose shipping: ' . $state);
    if ($state !== 'active') {
        check(OrderShippingStatus::columns($columns) === $columns, 'Do not change Woo columns for ' . $state);
        ob_start(); OrderShippingStatus::render('salam_gate_shipping', 1); OrderMetabox::render(null); OrderBulkActions::renderBulkHost();
        check(ob_get_clean() === '', 'No shipping HTML leaks for ' . $state);
    }
}
check(isset(OrderShippingStatus::columns($columns)['salam_gate_action']), 'Active accounts get shipping actions');
check(isset(OrderBulkActions::registerAction([])[OrderBulkActions::ACTION]), 'Active accounts get bulk action');
$preserved = $options;
SiteActivation::setEnabled(false);
check(!SiteActivation::isEnabled(), 'Site opt-out persists');
check(AccountStateRouter::resolveCurrent()['state'] === 'disabled', 'Opt-out overrides provider active state');
check(AccountStateRouter::resolveForPage()['state'] === 'disabled', 'Page routing returns pre-activation experience');
check(FeatureAvailability::activeShop() === null, 'Domain feature also stops after opt-out');
check(OrderShippingStatus::columns($columns) === $columns, 'Opt-out removes shipping columns');
check(OrderBulkActions::handle('/orders', OrderBulkActions::ACTION, [1]) === '/orders', 'Stale bulk action does not launch');
check($events === false, 'Opt-out pauses scheduled shipping sync');
check(array_diff_key($options, [SiteActivation::OPTION_KEY => 1]) === $preserved, 'Credentials, receipts, caches and sync preferences remain intact');
check($requests === [], 'Disable never deactivates/deletes a remote shop or submits money');

foreach (['/v1/shops/register', '/v1/parcels/100/pay', '/v1/shops/91/deactivate', '/v1/parcels/100/cancel'] as $path) {
    try { $client->post($path); throw new RuntimeException('Mutation allowed while disabled'); }
    catch (SalamGateException $error) { check($error->getKind() === SalamGateException::KIND_FORBIDDEN && $error->isDefinitelyNotDispatched(), 'Reject mutation locally: ' . $path); }
}
check($requests === [], 'Disabled requests cannot reach provider');
$_REQUEST = ['action' => 'salam_gate_send_pay'];
try { Module::guardDisabledActions(); throw new RuntimeException('Stale AJAX allowed'); }
catch (ActivationJsonExit $result) { check(!$result->success && $result->status === 409, 'Stale AJAX blocked'); }

$before = $options;
$_POST = ['enabled' => '1', '_wpnonce' => 'wrong'];
try { StatusActions::siteActivation(); throw new RuntimeException('Nonce bypass'); }
catch (ActivationJsonExit $result) { check(!$result->success && $result->status === 403, 'Activation requires nonce'); }
$allowed = false;
$_POST['_wpnonce'] = 'synthetic-nonce';
try { StatusActions::siteActivation(); throw new RuntimeException('Capability bypass'); }
catch (ActivationJsonExit $result) { check(!$result->success && $result->status === 403, 'Activation requires administrator'); }
$allowed = true;
$_POST['enabled'] = ['1'];
try { StatusActions::siteActivation(); throw new RuntimeException('Invalid payload allowed'); }
catch (ActivationJsonExit $result) { check(!$result->success && $result->status === 422, 'Activation requires exact scalar intent'); }
check($before === $options && $requests === [], 'Invalid requests do not write or contact provider');

foreach ([15, 16, 17, 999] as $status) {
    $remoteStatus = $status;
    try { SiteActivation::setEnabled(true); throw new RuntimeException('Unverified activation allowed'); }
    catch (SalamGateException $error) { check(!SiteActivation::isEnabled(), 'No fake activation for provider status ' . $status); }
}
$remoteStatus = 14;
$_POST = ['enabled' => '1', '_wpnonce' => 'synthetic-nonce'];
try { StatusActions::siteActivation(); throw new RuntimeException('Expected JSON result'); }
catch (ActivationJsonExit $result) { check($result->success && $result->data['enabled'], 'One click re-enables an existing active postal account'); }
check(FeatureAvailability::isVisible(), 'Shipping UI returns after reactivation');
check($events !== false, 'Reactivation restores prior sync preference');
check(count($requests) === 5 && count(array_filter($requests, static fn ($request) => $request[1] !== 'GET')) === 0, 'Reactivation only performs verification GETs, never registration or payment');
check($options['sync_basalam_settings'] === $preserved['sync_basalam_settings'] && $options['salam_gate_payment_example'] === $preserved['salam_gate_payment_example'], 'Account and paid evidence survive the full disable/enable cycle');

delete_option(ConnectionCache::key('shops'));
$_POST = ['_wpnonce' => 'synthetic-nonce'];
try { OrderFeatureGate::availability(); throw new RuntimeException('Expected availability JSON'); }
catch (ActivationJsonExit $result) { check($result->success && $result->data === ['available' => true], 'Probe resolves a cache miss without leaking account data'); }
check(count($requests) === 6, 'Probe uses one shop GET and no mutation');
SiteActivation::setEnabled(false);
try { OrderFeatureGate::availability(); throw new RuntimeException('Expected availability JSON'); }
catch (ActivationJsonExit $result) { check($result->success && $result->data === ['available' => false], 'Probe respects site opt-out'); }
check(count($requests) === 6, 'Disabled probe never contacts provider');
$options['sync_basalam_salam_gate_settings']['status_sync_enabled'] = 0;
SiteActivation::setEnabled(true);
check($events === false, 'Reactivation never enables sync the user had disabled');

$options['sync_basalam_settings']['token'] = '';
check(AccountStateRouter::resolveForPage()['state'] === 'not_connected' && !FeatureAvailability::isVisible(), 'Disconnected accounts cannot inherit cached shipping UI');
echo "SalamGate site activation: {$checks} checks passed\n";
