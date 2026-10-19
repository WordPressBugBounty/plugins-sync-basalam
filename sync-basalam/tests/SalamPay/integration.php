<?php
/** Run only against the local Postman simulation and a disposable WP fixture. */
if (PHP_SAPI !== 'cli') { exit; }
define('DISABLE_WP_CRON', true);
define('SYNC_BASALAM_SALAMPAY_TEST_API_URL', getenv('SALAMPAY_TEST_API_URL') ?: 'http://host.docker.internal:4900/salam-pay');
require getenv('WORDPRESS_BOOTSTRAP') ?: '/var/www/html/wp-load.php';

use SyncBasalam\SalamPay\{ApiClient, Callback, Module, Money, OrderLock, PaymentService, RefundService};

if (wp_get_environment_type() !== 'local') { throw new RuntimeException('Local WordPress required.'); }
add_filter('pre_wp_mail', '__return_false');
$mockHost = parse_url(SYNC_BASALAM_SALAMPAY_TEST_API_URL, PHP_URL_HOST);
add_filter('pre_http_request', static function ($pre, $args, $url) use ($mockHost) {
    return parse_url($url, PHP_URL_HOST) === $mockHost ? $pre : new WP_Error('test_external_blocked', 'External HTTP blocked.');
}, 999, 3);
$runner = syncBasalamContainer()->get(\SyncBasalam\JobsRunner::class);
remove_action('shutdown', [$runner, 'maybeDispatchAsyncRequest'], 2);
remove_action('sync_basalam_job_created', [$runner, 'maybeDispatchAsyncRequest'], 10);
$oldSettings = get_option('woocommerce_salampay_settings', null);
$oldManageStock = get_option('woocommerce_manage_stock');
$settings = ['enabled' => 'yes', 'client_secret' => 'local-salam-pay-fixture', 'success_order_status' => 'auto', 'failed_order_status' => 'failed'];
$orders = [];
$product = null;
$assertions = 0;
$check = static function ($expected, $actual, string $message) use (&$assertions): void {
    if ($expected !== $actual) { throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); }
    $assertions++;
};
$control = static function (array $body): array {
    $r = wp_remote_post(SYNC_BASALAM_SALAMPAY_TEST_API_URL . '/__control', ['headers' => ['Content-Type' => 'application/json'], 'body' => wp_json_encode($body)]);
    if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200) { throw new RuntimeException('Simulation is not reachable.'); }
    return json_decode(wp_remote_retrieve_body($r), true);
};
$state = static function (): array {
    return json_decode(wp_remote_retrieve_body(wp_remote_get(SYNC_BASALAM_SALAMPAY_TEST_API_URL . '/__state')), true);
};
$enum = static function (int $value, string $name): array { return compact('value', 'name') + ['description' => $name]; };
$callback = static function ($order, string $method = 'POST', array $overrides = []) {
    $r = new WP_REST_Request($method, '/salampay/v1/callback');
    $r->set_query_params(array_merge(['order_id' => $order->get_id(), 'key' => $order->get_order_key(), 'status' => 3], $overrides));
    return rest_do_request($r);
};
try {
    update_option('woocommerce_salampay_settings', $settings, false);
    update_option('woocommerce_manage_stock', 'yes');
    $control(['reset' => true]);
    $check(true, Module::ownsPayments(), 'Internal module owns payments');
    $gateway = WC()->payment_gateways()->payment_gateways()['salampay'];
    $check(\SyncBasalam\SalamPay\Gateway::class, get_class($gateway), 'One internal gateway');
    $registry = \Automattic\WooCommerce\Blocks\Package::container()->get(\Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry::class);
    $check(true, $registry->is_registered('salampay'), 'Real Blocks registry has gateway');
    $blocks = new \SyncBasalam\SalamPay\BlocksSupport();
    $blocks->initialize();
    $check(false, array_key_exists('client_secret', $blocks->get_payment_method_data()), 'No merchant secret in Blocks data');
    $check(10000, Money::rial(1000, 'IRT'), 'Toman converts to Rial');
    $check(1000, Money::rial(1000, 'IRR'), 'Rial remains Rial');
    $check(20000, Money::rial(2, 'IRHT'), 'Thousand Toman converts to Rial');
    $check(false, Money::supports('USD'), 'Unsupported currency hidden');
    try { Money::rial(-1, 'IRT'); throw new RuntimeException('Negative money accepted'); } catch (InvalidArgumentException $e) { $assertions++; }

    $product = new WC_Product_Simple();
    $product->set_name('نمونه آزمون سلام‌پی');
    $product->set_status('publish');
    $product->set_regular_price('1000');
    $product->set_manage_stock(true);
    $product->set_stock_quantity(50);
    $product->save();
    $makeOrder = static function (string $currency = 'IRT') use (&$orders, $product) {
        $o = wc_create_order(['status' => 'pending']);
        $orders[] = $o->get_id();
        $o->set_currency($currency);
        $o->set_payment_method('salampay');
        $o->set_billing_phone('+۹۸۹۱۲۱۲۳۴۵۶۷');
        $o->add_product($product, 2);
        $o->calculate_totals(false);
        $o->save();
        return $o;
    };
    $order = $makeOrder();
    $payload = PaymentService::payload($order);
    $check('09121234567', $payload['mobile'], 'Persian international mobile normalized');
    $check(20000, $payload['amount'], 'Order total converted using order currency');
    $start = $gateway->process_payment($order->get_id());
    $check('success', $start['result'], 'Gateway opens real-HTTP session');
    $check(true, strpos($start['redirect'], 'https://salampay.basalam.com/payment-interface/') === 0, 'Production checkout host');
    $again = $gateway->process_payment($order->get_id());
    $check($start['redirect'], $again['redirect'], 'Repeated checkout reuses same session');
    $check(1, count(array_filter($state()['calls'], static fn($c) => $c['method'] === 'POST' && $c['path'] === '/public-api/v1/sessions')), 'No duplicate session POST');
    $check(403, $callback($order, 'POST', ['key' => 'wrong'])->get_status(), 'Forged key rejected');
    $check(403, $callback($order, 'POST', ['key' => ['bad']])->get_status(), 'Array key rejected without PHP error');
    $check('pending', $callback($order)->get_data()['status'], 'Callback status alone cannot pay order');
    $control(['mode' => 'html']);
    $check(503, $callback($order)->get_status(), 'HTML upstream failure remains unresolved');
    $check(false, (bool) wc_get_order($order->get_id())->get_date_paid(), 'Network failure never pays order');
    $control(['mode' => '', 'reference_id' => $order->get_id(), 'payment' => ['amount' => 1, 'status' => $enum(3, 'SUCCEEDED')]]);
    $check(409, $callback($order)->get_status(), 'Wrong amount rejected');
    $control(['reference_id' => $order->get_id(), 'payment' => ['amount' => 20000]]);
    $stockBefore = wc_get_product($product->get_id())->get_stock_quantity();
    $check('paid', $callback($order)->get_data()['status'], 'Verified payment settles order');
    $check($stockBefore - 2, wc_get_product($product->get_id())->get_stock_quantity(), 'Payment reduces inventory once');
    $paid = wc_get_order($order->get_id());
    $check((string) ($order->get_id() + 200000), $paid->get_transaction_id(), 'Transaction ID stored');
    $paid->update_status('completed');
    $check(302, $callback($paid, 'GET')->get_status(), 'Browser callback redirects');
    $check('completed', wc_get_order($order->get_id())->get_status(), 'Duplicate callback preserves later admin status');
    $check($stockBefore - 2, wc_get_product($product->get_id())->get_stock_quantity(), 'Replay cannot reduce inventory twice');
    $lease = OrderLock::acquire($order->get_id());
    $check(409, $callback($order)->get_status(), 'Concurrent financial operation blocked');
    OrderLock::release($order->get_id(), $lease);

    $items = $paid->get_items(); $item = reset($items);
    $refund = wc_create_refund(['order_id' => $order->get_id(), 'amount' => 1000, 'reason' => 'partial fixture', 'refund_payment' => true, 'restock_items' => true,
        'line_items' => [$item->get_id() => ['qty' => 1, 'refund_total' => 1000, 'refund_tax' => []]]]);
    $check(true, is_wp_error($refund), 'Queued refund not falsely finalized by WC');
    $check(true, strpos($refund->get_error_message(), 'در انتظار تأیید') !== false, 'Admin sees pending result through WC error wrapper');
    $paid = wc_get_order($order->get_id());
    $check(0.0, (float) $paid->get_total_refunded(), 'No WC refund before provider success');
    $records = $paid->get_meta('_salampay_refunds'); $ref = array_key_first($records);
    $check(10000, $records[$ref]['amount'], 'Partial refund converts to Rial');
    $check(true, is_wp_error(RefundService::request($order->get_id(), 1000, 'duplicate')), 'Second pending refund blocked');
    RefundService::reconcile($order->get_id());
    $check(0.0, (float) wc_get_order($order->get_id())->get_total_refunded(), 'Provider pending stays pending');
    $control(['reference_id' => $ref, 'refund' => ['final_status' => $enum(3, 'SUCCEEDED'), 'amount' => 2]]);
    RefundService::reconcile($order->get_id());
    $check(0.0, (float) wc_get_order($order->get_id())->get_total_refunded(), 'Mismatched refund cannot settle');
    $control(['reference_id' => $ref, 'refund' => ['amount' => 10000]]);
    RefundService::reconcile($order->get_id());
    $paid = wc_get_order($order->get_id());
    $check(1000.0, (float) $paid->get_total_refunded(), 'Confirmed partial refund persisted');
    $check($stockBefore - 1, wc_get_product($product->get_id())->get_stock_quantity(), 'Confirmed refund restocks selected item');
    $localRefund = $paid->get_refunds()[0];
    $check(true, $localRefund->get_refunded_payment(), 'WC refund marked as provider-paid');
    $check(1, count($localRefund->get_items()), 'Selected refund item preserved');
    RefundService::reconcile($order->get_id());
    $check(1, count(wc_get_order($order->get_id())->get_refunds()), 'Settlement replay cannot duplicate WC refund');
    $check(true, is_wp_error(RefundService::request($order->get_id(), 1001)), 'Cumulative over-refund rejected');
    $control(['mode' => 'refund500']);
    $second = RefundService::request($order->get_id(), 1000, 'remaining');
    $check('unknown', $second['status'], 'Lost refund response saved as unknown');
    $control(['mode' => '', 'reference_id' => $second['reference'], 'refund' => ['final_status' => $enum(3, 'SUCCEEDED')]]);
    RefundService::reconcile($order->get_id());
    $check(2000.0, (float) wc_get_order($order->get_id())->get_total_refunded(), 'Second partial refund reconciles after lost response');
    $check('refunded', wc_get_order($order->get_id())->get_status(), 'Fully refunded status only after both settlements');
    $callback($order);
    $check('refunded', wc_get_order($order->get_id())->get_status(), 'Callback cannot reopen refunded order');

    $unknown = $makeOrder();
    $control(['mode' => 'session500']);
    $check('failure', $gateway->process_payment($unknown->get_id())['result'], 'Lost session response does not claim success');
    $check('unknown', wc_get_order($unknown->get_id())->get_meta('_salampay_session_state'), 'Ambiguous create persisted');
    $callsBefore = count($state()['calls']);
    $gateway->process_payment($unknown->get_id());
    $check($callsBefore, count($state()['calls']), 'Ambiguous creation is never repeated');
    $control(['mode' => '', 'reference_id' => $unknown->get_id(), 'payment' => ['status' => $enum(3, 'SUCCEEDED')]]);
    PaymentService::reconcile($unknown->get_id());
    $check(true, (bool) wc_get_order($unknown->get_id())->get_date_paid(), 'Background inquiry recovers lost create response');

    $cancelled = $makeOrder(); $gateway->process_payment($cancelled->get_id());
    $control(['reference_id' => $cancelled->get_id(), 'payment' => ['status' => $enum(3, 'SUCCEEDED')]]);
    PaymentService::verify($cancelled->get_id());
    update_option('woocommerce_salampay_settings', $settings + ['auto_refund_cancelled' => 'yes'], false);
    wc_get_order($cancelled->get_id())->update_status('cancelled');
    $cancelRecords = wc_get_order($cancelled->get_id())->get_meta('_salampay_refunds');
    $check(true, is_array($cancelRecords) && count($cancelRecords) === 1, 'Paid cancellation submits remaining refund despite is_paid false');
    update_option('woocommerce_salampay_settings', $settings, false);

    $edited = $makeOrder(); $gateway->process_payment($edited->get_id());
    $control(['reference_id' => $edited->get_id(), 'payment' => ['status' => $enum(3, 'SUCCEEDED')]]);
    $edited = wc_get_order($edited->get_id()); $edited->set_total(9999); $edited->save();
    $check(409, $callback($edited)->get_status(), 'Order edits after session creation require review');

    $legacy = $makeOrder();
    $legacy->update_meta_data('_salampay_reference_id', $legacy->get_id()); $legacy->save();
    $control(['seed_payment' => ['id' => $legacy->get_id() + 200000, 'reference_id' => $legacy->get_id(), 'amount' => 20000, 'status' => 3]]);
    $check('paid', $callback($legacy)->get_data()['status'], 'Old standalone callback and scalar success supported');

    // A standalone manual refund may already have created a WC refund despite
    // the provider still processing it. Handover must never create a second one.
    $legacy = wc_get_order($legacy->get_id());
    $legacyRefund = wc_create_refund(['order_id' => $legacy->get_id(), 'amount' => 1000, 'refund_payment' => false]);
    $legacy->update_meta_data('_salampay_refund_reference_id', $legacy->get_id());
    $legacy->update_meta_data('_salampay_refund_amount', 10000);
    $legacy->update_meta_data('_salampay_refund_status', 'pending');
    $legacy->save();
    $control(['seed_refund' => ['reference_id' => $legacy->get_id(), 'amount' => 10000,
        'pay_id' => $legacy->get_id() + 200000, 'pay' => ['reference_id' => $legacy->get_id()], 'final_status' => $enum(3, 'SUCCEEDED')]]);
    RefundService::reconcile($legacy->get_id());
    $check(1, count(wc_get_order($legacy->get_id())->get_refunds()), 'Legacy refund handover reuses existing WC record');
    $check('succeeded', wc_get_order($legacy->get_id())->get_meta('_salampay_refunds')[$legacy->get_id()]['status'], 'Legacy async intent reconciled');

    $rejected = $makeOrder();
    $control(['mode' => 'reject']);
    $check('failure', $gateway->process_payment($rejected->get_id())['result'], 'Definitive session rejection is a failure');
    $check('rejected', wc_get_order($rejected->get_id())->get_meta('_salampay_session_state'), 'Definitive rejection distinguished from unknown result');
    $control(['mode' => '']);
    $check('success', $gateway->process_payment($rejected->get_id())['result'], 'Rejected session can be retried after correction');
    $control(['reference_id' => $rejected->get_id(), 'payment' => ['status' => $enum(8, 'EXPIRED')]]);
    $check('failed', $callback($rejected)->get_data()['status'], 'Known terminal failure handled');
    $check(false, (bool) wc_get_order($rejected->get_id())->get_date_paid(), 'Failure never becomes paid');

    $pending = $makeOrder(); $gateway->process_payment($pending->get_id());
    $control(['reference_id' => $pending->get_id(), 'payment' => ['status' => ['value' => 999, 'name' => 'FUTURE_STATUS']]]);
    $check('pending', $callback($pending)->get_data()['status'], 'Future provider status preserves pending order');
    $check('pending', wc_get_order($pending->get_id())->get_status(), 'Unknown status does not fail order');
    wp_clear_scheduled_hook('sync_basalam_salampay_reconcile_payment', [$pending->get_id()]);
    PaymentService::resume(wc_get_order($pending->get_id()));
    $check(true, (bool) wp_next_scheduled('sync_basalam_salampay_reconcile_payment', [$pending->get_id()]), 'Interrupted inquiry resumes after reactivation');

    // Reconciliation cannot refund more than the original paid snapshot even
    // when an administrator later increases the order total.
    $paidChanged = $makeOrder(); $gateway->process_payment($paidChanged->get_id());
    $control(['reference_id' => $paidChanged->get_id(), 'payment' => ['status' => $enum(3, 'SUCCEEDED')]]);
    PaymentService::verify($paidChanged->get_id());
    $paidChanged = wc_get_order($paidChanged->get_id()); $paidChanged->set_total(3000); $paidChanged->save();
    $check(true, is_wp_error(RefundService::request($paidChanged->get_id(), 3000)), 'Original paid amount caps refunds after order edits');

    $closed = $makeOrder(); $gateway->process_payment($closed->get_id());
    $closed = wc_get_order($closed->get_id()); $closed->update_status('cancelled');
    $control(['reference_id' => $closed->get_id(), 'payment' => ['status' => $enum(3, 'SUCCEEDED')]]);
    $check(409, $callback($closed)->get_status(), 'Late success for closed unpaid order requires review');
    $check('cancelled', wc_get_order($closed->get_id())->get_status(), 'Late success cannot silently reopen closed order');

    $complex = $makeOrder('IRR');
    $items = $complex->get_items(); $item = reset($items); $item->set_quantity(3); $item->set_subtotal(10001); $item->set_total(9000); $item->save();
    $complex->set_discount_total(1001); $complex->set_total(9500); $complex->save();
    $payload = PaymentService::payload(wc_get_order($complex->get_id()));
    $basketTotal = array_sum(array_map(static fn($i) => $i['quantity'] * $i['unit_price'], $payload['items'])) - $payload['discount'];
    $check(9500, $basketTotal, 'Discount, shipping and fractional unit prices exactly match amount');
    $complex->set_currency('USD'); $complex->save();
    $check(true, is_wp_error(PaymentService::payload($complex)), 'Foreign currency never treated as Rial');
    echo 'PASS: ' . $assertions . " assertions; real WooCommerce + HPOS + HTTP Postman simulation.\n";
} finally {
    foreach ($orders as $id) {
        wp_clear_scheduled_hook('sync_basalam_salampay_reconcile_payment', [$id]);
        wp_clear_scheduled_hook('sync_basalam_salampay_reconcile_refund', [$id]);
        $o = wc_get_order($id);
        if ($o) { foreach ($o->get_refunds() as $r) { $r->delete(true); } $o->delete(true); }
    }
    if ($product) { $product->delete(true); }
    $oldSettings === null ? delete_option('woocommerce_salampay_settings') : update_option('woocommerce_salampay_settings', $oldSettings, false);
    update_option('woocommerce_manage_stock', $oldManageStock);
}
