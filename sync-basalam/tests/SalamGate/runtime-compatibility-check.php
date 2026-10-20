<?php

/** Run on PHP 7.4 and 8.x; use the real WordPress compat.php for its core polyfills. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ABSPATH', __DIR__ . '/wordpress/');
require_once __DIR__ . '/wordpress-compat.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use SyncBasalam\SalamGate\Admin\Ajax\BulkShippingActions;
use SyncBasalam\SalamGate\Admin\Ajax\ParcelActions;
use SyncBasalam\SalamGate\Admin\SettingsHandler;
use SyncBasalam\SalamGate\Contracts\CancellationEligibility;
use SyncBasalam\SalamGate\Contracts\LabelEligibility;
use SyncBasalam\SalamGate\Contracts\ParcelStatuses;
use SyncBasalam\SalamGate\Contracts\ParcelViewNormalizer;
use SyncBasalam\SalamGate\Contracts\PayMethods;
use SyncBasalam\SalamGate\Order\BasalamGoodsValue;
use SyncBasalam\SalamGate\Order\BasalamParcelOrder;
use SyncBasalam\SalamGate\Order\BasalamParcelOrderItem;
use SyncBasalam\SalamGate\Order\BasalamShippingPreflight;
use SyncBasalam\SalamGate\Order\NumericIdentity;
use SyncBasalam\SalamGate\Order\OrderContext;
use SyncBasalam\SalamGate\Order\OrderShippingPreference;
use SyncBasalam\SalamGate\Parcel\QuoteNormalizer;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\ParcelService;
use SyncBasalam\SalamGate\Services\PaymentRejection;
use SyncBasalam\SalamGate\Utils\Arrays;
use SyncBasalam\SalamGate\Utils\Units;

$checks = 0;
function runtimeCheck(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}
function runtimeThrows(callable $action, string $type, string $message): void {
    try { $action(); }
    catch (Throwable $error) { runtimeCheck($error instanceof $type, $message); return; }
    throw new RuntimeException($message);
}
function get_option($key, $default = false) { return $default; }
function update_option(...$args) { throw new RuntimeException('Unexpected persistent write'); }
function wp_remote_request(...$args) { throw new RuntimeException('Unexpected live request'); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function wp_unslash($value) { return $value; }
function home_url($path = '') { return ($GLOBALS['runtime_site'] ?? 'https://example.test') . $path; }
function get_current_blog_id() { return $GLOBALS['runtime_blog'] ?? 1; }
class WC_Order {
    private string $status;
    public function __construct(string $status = 'processing') { $this->status = $status; }
    public function get_status() { return $this->status; }
    public function get_shipping_methods() { return []; }
}

// Resolve every controller/service, including the classes not reached on a dashboard request.
$root = dirname(__DIR__, 2) . '/includes/SalamGate/';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() !== 'php') { continue; }
    // Historical include-only entry point forwards to the real Ajax controller.
    if ($file->getPathname() === $root . 'Admin/SendActions.php') {
        require_once $file->getPathname();
        continue;
    }
    $class = 'SyncBasalam\\SalamGate\\' . str_replace('/', '\\', substr($file->getPathname(), strlen($root), -4));
    runtimeCheck(class_exists($class), 'Autoload shipping class: ' . $class);
}

runtimeCheck(Arrays::isList([]) && Arrays::isList([NAN, 'a', null]), 'List validation accepts empty and sequential lists');
foreach ([[1 => 'a'], ['id' => 1], [0 => 'a', 2 => 'b'], [1 => 'b', 0 => 'a'], [-1 => 'a']] as $invalidList) {
    runtimeCheck(!Arrays::isList($invalidList), 'List validation rejects sparse, reordered and associative keys');
}
runtimeCheck(str_contains('پرداخت انجام شد', 'پرداخت') && str_starts_with('wc-cancelled', 'wc-')
    && str_ends_with('/v1/shops', '/shops'), 'WordPress string polyfills work on PHP 7.4');
runtimeCheck(NumericIdentity::forKey('woo-order:41') === 6766790404393758, 'Existing Woo shipment identity stays unchanged');
runtimeCheck(NumericIdentity::forKey('standalone:website:abc') === 8968035707434348, 'Existing standalone shipment identity stays unchanged');
$GLOBALS['runtime_blog'] = 2;
runtimeCheck(NumericIdentity::forKey('woo-order:41') === 7960653251098465, 'Shipment identity stays scoped to the blog');
$GLOBALS['runtime_blog'] = 1;
$GLOBALS['runtime_site'] = 'https://other.test';
runtimeCheck(NumericIdentity::forKey('woo-order:41') === 8310274403475223, 'Shipment identity stays scoped to the site');
runtimeCheck(NumericIdentity::forKey('') === null, 'Empty shipment key never creates an identity');
unset($GLOBALS['runtime_site'], $GLOBALS['runtime_blog']);
foreach ([0 => 0, 10 => 1, 11 => 1.1, 1064725 => 106472.5] as $rial => $toman) {
    runtimeCheck(Units::toToman($rial) === $toman, 'Exact rial/toman conversion: ' . $rial);
}
runtimeCheck(Units::parseFormAmount('۱۰۶٬۴۷۲') === 106472, 'Persian grouped integer input stays valid');
runtimeCheck(Units::parseFormAmount('۱۰۶٬۴۷۲٫۵') === 0, 'Unsupported fractional form input is not silently truncated');
runtimeCheck(Units::formatToman(106472.5) !== '', 'Decimal display renders');

$quote = ['hash_id' => 'synthetic-quote', 'service_type' => ['id' => 1], 'costs' => [
    'base_cost' => 900000, 'raw_cost' => 845250, 'total' => 1064725,
    'discount' => ['items' => []], 'additional' => ['total' => 219475, 'items' => []],
]];
$normalized = QuoteNormalizer::normalize(['parcel_id' => 71, 'quotes' => [$quote]])['quotes'][0];
runtimeCheck($normalized['fare_cost_toman'] === 84525, 'Selection shows discounted postage');
runtimeCheck($normalized['total_rial'] === 1064725 && $normalized['total_toman'] === 106472.5, 'Confirmation keeps authoritative final total');
$quote['costs']['discount']['items'][] = ['key' => 'coupon_discount', 'amount' => -10000];
runtimeCheck(QuoteNormalizer::normalize(['quotes' => [$quote]])['quotes'][0]['fare_cost_toman'] === 90000, 'Coupon keeps base-fare branch');
$quote['costs']['total'] = 'invalid';
runtimeCheck(QuoteNormalizer::normalize(['quotes' => [$quote]])['quotes'][0]['total_toman'] === null, 'Malformed total never becomes free postage');
runtimeCheck(ParcelViewNormalizer::fromListItem(['invoice' => ['amount' => 1064725]])['shipping_cost_toman'] === 106472.5, 'Parcel list preserves final fractional toman');
runtimeCheck(ParcelViewNormalizer::fromListItem([])['shipping_cost_toman'] === null, 'Missing invoice is unknown, not zero');

$goods = [['price' => 100000, 'quantity' => 2.0, 'financial_report' => ['report_items' => [
    ['id' => 7052, 'title' => 'قیمت محصول', 'amount' => 200000],
    ['id' => 7053, 'title' => 'تخفیف محصول غرفه', 'amount' => -15000],
]]]];
runtimeCheck(BasalamGoodsValue::fromItems($goods) === ['gross_rial' => 200000, 'vendor_discount_rial' => -15000, 'value_rial' => 185000], 'Basalam report discounts applied once per line');
$goods[0]['financial_report']['report_items'][0]['title'] = 'تخفیف محصول غرفه';
runtimeThrows(static function () use ($goods) { BasalamGoodsValue::fromItems($goods); }, SalamGateException::class, 'Conflicting financial row title/ID is rejected');

$woo = new WC_Order();
$native = (new ReflectionClass(BasalamParcelOrder::class))->newInstanceWithoutConstructor();
OrderContext::assertSupportedOrder($woo);
OrderContext::assertSupportedOrder($native);
runtimeCheck(OrderShippingPreference::forOrder($woo, 'website') === null, 'Woo order remains an accepted shipping input');
runtimeCheck(BasalamShippingPreflight::localStatusBlockReason($woo) === null, 'Ordinary order passes local status gate');
runtimeCheck(BasalamShippingPreflight::localStatusBlockReason(new WC_Order('cancelled')) !== null, 'Cancelled order stays blocked');
foreach ([
    static function () { OrderContext::assertSupportedOrder(new stdClass()); },
    static function () { BasalamShippingPreflight::check(new stdClass()); },
    static function () { BasalamShippingPreflight::fromSnapshots(new stdClass(), [], [], 1, 2, 3); },
    static function () { OrderShippingPreference::forOrder(new stdClass(), 'basalam'); },
    static function () { ParcelActions::applyPaySuccess(new stdClass(), [], null); },
] as $invalidOrder) {
    runtimeThrows($invalidOrder, TypeError::class, 'Replacing unions must still reject unrelated objects before side effects');
}
$purchasedItem = new class {
    public function get_id() { return 9; }
    public function get_quantity() { return 4; }
    public function get_name() { return 'Synthetic item'; }
};
$allocated = new BasalamParcelOrderItem($purchasedItem, 2);
runtimeCheck($allocated->get_quantity() === 2 && $purchasedItem->get_quantity() === 4, 'Native allocation keeps purchased quantity intact');
runtimeCheck($allocated->get_id() === 9 && $allocated->get_name() === 'Synthetic item', 'Allocation getters still delegate');
runtimeThrows(static function () use ($allocated) { $allocated->set_quantity(8); }, LogicException::class, 'Shipping allocation cannot rewrite an order');

$selectIds = new ReflectionMethod(BulkShippingActions::class, 'selectedIds');
$selectIds->setAccessible(true);
runtimeCheck($selectIds->invoke(null, [1, '2', 1]) === [1, 2], 'Bulk selection normalizes unique IDs');
foreach ([[], [1 => 1], ['id' => 1], ['1oops']] as $invalidIds) {
    runtimeThrows(static function () use ($selectIds, $invalidIds) { $selectIds->invoke(null, $invalidIds); }, SalamGateException::class, 'Malformed bulk IDs remain blocked');
}
runtimeCheck(PayMethods::isValidParcelMethodForMarket(19, 'basalam') && !PayMethods::isValidParcelMethodForMarket(20, 'basalam'), 'Basalam keeps original-order balance');
runtimeCheck(PayMethods::isValidParcelMethodForMarket(20, 'website') && PayMethods::isValidParcelMethodForMarket(21, 'website')
    && !PayMethods::isValidParcelMethodForMarket(19, 'website'), 'Non-Basalam keeps vendor/wallet payment methods');
$paid = ['id' => 71, 'market' => 'website', 'status_id' => ParcelStatuses::POSTAL_BARCODE,
    'invoice_status' => 2, 'invoice_id' => 81, 'tracking_code' => '111111111111111111111111'];
runtimeCheck(LabelEligibility::canPrint($paid), 'Paid receipt can print a single label');
runtimeCheck(!LabelEligibility::canPrint(array_replace($paid, ['invoice_status' => 1])), 'Unpaid receipt cannot print');
runtimeCheck(CancellationEligibility::canCancel($paid)
    && !CancellationEligibility::canCancel(array_replace($paid, ['market' => 'basalam'])), 'Cancellation keeps origin/payment guards');
$balance = new SalamGateException('Insufficient balance', 422,
    [['location' => 'vendor_balance', 'message' => 'تراز غرفه کافی نیست.']], SalamGateException::KIND_VALIDATION);
runtimeCheck(PaymentRejection::isConfirmedPreCharge($balance, 71, 20, $paid), 'Exact insufficient-funds rejection stays pre-charge');
runtimeCheck(!PaymentRejection::isConfirmedPreCharge(new SalamGateException('timeout', 0, [], SalamGateException::KIND_TRANSPORT), 71, 20, $paid), 'Timeout never becomes a safe new payment');
runtimeCheck(ParcelService::positiveId('71bad') === null, 'Malformed parcel identity is rejected');
runtimeCheck(is_array(SettingsHandler::sanitizeForSave([])), 'Default settings normalize without PHP 8 mixed type');

echo 'SalamGate runtime compatibility on PHP ' . PHP_VERSION . ': ' . $checks . " checks passed\n";
