<?php

namespace SyncBasalam\SalamGate\Admin\Ajax;

use SyncBasalam\SalamGate\ClientFactory;
use SyncBasalam\SalamGate\Admin\OrderMetabox;
use SyncBasalam\SalamGate\Admin\OrderListAction;
use SyncBasalam\SalamGate\Contracts\LabelEligibility;
use SyncBasalam\SalamGate\Contracts\ParcelStatuses;
use SyncBasalam\SalamGate\Contracts\ParcelViewNormalizer;
use SyncBasalam\SalamGate\Order\OrderContext;
use SyncBasalam\SalamGate\Order\BasalamOrderContext;
use SyncBasalam\SalamGate\Order\BasalamParcelOrder;
use SyncBasalam\SalamGate\Order\BasalamParcelSet;
use SyncBasalam\SalamGate\Order\BasalamShippingPreflight;
use SyncBasalam\SalamGate\Order\BasalamCheckoutPreflight;
use SyncBasalam\SalamGate\SalamGateClient;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\ConnectionCache;
use SyncBasalam\SalamGate\Services\ParcelService;
use SyncBasalam\SalamGate\Services\ParcelsListService;
use SyncBasalam\SalamGate\Utils\OptionLock;

defined('ABSPATH') || exit;

/**
 * Selection/identity boundary for sequential shipping with per-market funding.
 *
 * This is not a batch financial API. Every item is delegated to the existing
 * single-parcel controllers, retaining their creation claims, leases, reviewed
 * quote/amount checks, payment evidence and reconciliation rules. Opening the
 * selection never creates a parcel, refreshes a rate or makes a payment.
 */
final class BulkShippingActions
{
    public const MAX_BATCH = 20;
    public const SESSION_TTL = 1800;
    private const SESSION_PREFIX = 'salam_gate_bulk_shipping_';

    public static function bootstrap(): void
    {
        self::guard();
        try {
            $source = $_POST['source'] ?? null;
            if (!in_array($source, ['orders', 'parcels'], true)) {
                throw self::validation('مبدأ انتخاب سفارش‌ها معتبر نیست.');
            }
            $ids = self::selectedIds($_POST['ids'] ?? null);
            $connection = ConnectionCache::identityHash();
            $rows = self::rows($source, $ids);
            if (count($rows) > self::MAX_BATCH) {
                throw self::validation('این انتخاب بیش از ۲۰ بسته دارد؛ تعداد سفارش‌های انتخاب‌شده را کمتر کنید.');
            }
            ConnectionCache::assertCurrentIdentity($connection);
            $batch = bin2hex(random_bytes(16));
            $markets = [];
            $foreignIds = [];
            $items = [];
            $nativeIds = [];
            foreach ($rows as $row) {
                if ($row['market'] === 'basalam' && ($row['basalam_parcel_id'] ?? null) !== null) {
                    if (isset($nativeIds[$row['basalam_parcel_id']])) {
                        throw self::validation('یک بسته باسلام در بیش از یک سفارش انتخاب‌شده تکرار شده است؛ ارتباط سفارش‌ها را بررسی کنید.', 'batch_identity_changed');
                    }
                    $nativeIds[$row['basalam_parcel_id']] = true;
                }
                $markets[$row['id']] = $row['market'];
                $foreignIds[$row['id']] = $row['foreign_parcel_id'] ?? null;
                $items[$row['key']] = self::pin($row);
            }
            $active = array_values(array_map(static fn (array $row): int => $row['id'],
                array_filter($rows, static fn (array $row): bool => $row['eligible'])));
            // Deliberately retain no recipient/address/product data in the session.
            $record = ['source' => $source, 'ids' => $ids, 'active_ids' => $active, 'markets' => $markets, 'foreign_ids' => $foreignIds,
                'items' => $items, 'active_keys' => array_keys(array_filter($items, static fn (array $pin): bool => $pin['eligible'])),
                'user_id' => get_current_user_id(), 'connection_id' => $connection,
                'expires_at' => time() + self::SESSION_TTL];
            if (!set_transient(self::SESSION_PREFIX . $batch, $record, self::SESSION_TTL)) {
                throw self::validation('انتخاب گروهی ذخیره نشد؛ دوباره تلاش کنید.', 'batch_unavailable');
            }
            ConnectionCache::assertCurrentIdentity($connection);
            $eligible = count(array_filter($rows, static fn (array $row): bool => $row['eligible']));
            wp_send_json_success(['batch_id' => $batch, 'source' => $source, 'connection_id' => $connection,
                'user_id' => get_current_user_id(),
                'rows' => $rows, 'max_batch' => self::MAX_BATCH,
                'counts' => ['selected' => count($rows), 'eligible' => $eligible, 'blocked' => count($rows) - $eligible],
                'total_count' => count($ids), 'eligible_count' => $eligible]);
        } catch (SalamGateException $exception) { self::sendError($exception); }
    }

    /** A single explicit preparation, quote selection or payment within the saved selection. */
    public static function item(): void
    {
        self::guard();
        // Request values cannot disable the wrapper's stricter weight review.
        unset($_POST['_salam_gate_bulk_weight_required']);
        unset($_POST['_salam_gate_bulk_native_identity']);
        $lease = null;
        $lockKey = '';
        try {
            $batch = $_POST['batch_id'] ?? null;
            $record = self::session($batch);
            $id = ParcelService::positiveId($_POST['id'] ?? null);
            if ($id === null || !in_array($id, $record['ids'], true)) {
                throw self::validation('این سفارش در انتخاب گروهی شما نیست.', 'batch_item_invalid');
            }
            $operation = $_POST['operation'] ?? null;
            $allowed = $record['source'] === 'orders'
                ? ['prepare', 'upsert', 'quotes', 'select', 'pay'] : ['prepare', 'quotes', 'select', 'pay'];
            if (!in_array($operation, $allowed, true)) {
                throw self::validation('این عملیات در ارسال گروهی در دسترس نیست.');
            }
            if (isset($_POST['source']) && $_POST['source'] !== $record['source']) {
                throw self::validation('مبدأ انتخاب گروهی تغییر کرده است.', 'batch_item_invalid');
            }
            $itemKey = $_POST['item_key'] ?? null;
            if ($itemKey === null) {
                $matches = array_keys(array_filter($record['items'], static fn (array $pin): bool => $pin['id'] === $id));
                if (count($matches) !== 1) { throw self::validation('بسته موردنظر این سفارش را مشخص کنید.', 'batch_item_invalid'); }
                $itemKey = $matches[0];
            }
            if (!is_string($itemKey) || !isset($record['items'][$itemKey]) || $record['items'][$itemKey]['id'] !== $id) {
                throw self::validation('این بسته در انتخاب گروهی شما نیست.', 'batch_item_invalid');
            }
            $pin = $record['items'][$itemKey];

            if ($operation !== 'prepare') {
                $lockKey = self::SESSION_PREFIX . 'lease_' . $batch;
                $lease = OptionLock::acquire($lockKey, 600);
                if ($lease === null) { throw self::validation('یک عملیات گروهی در حال انجام است؛ منتظر نتیجه بمانید.', 'batch_busy'); }
                // wp_send_json exits rather than returning through finally in real WP.
                register_shutdown_function(static function () use ($lockKey, $lease): void {
                    OptionLock::release($lockKey, $lease);
                });
            }

            // Fresh source detection / owner-filtered list lookup on EVERY operation.
            // A browser market, cached table row or bootstrap response is not proof.
            $rows = self::savedRows($record, $itemKey, $operation === 'prepare');
            ConnectionCache::assertCurrentIdentity($record['connection_id']);
            $row = null;
            foreach ($rows as $candidate) {
                if ($candidate['key'] === $itemKey) { $row = $candidate; }
            }
            if ($row === null || $row['market'] !== $pin['market'] || self::pinIdentity($row) !== self::pinIdentity($pin)) {
                throw self::validation('ارتباط یا مبدأ این سفارش تغییر کرده است؛ انتخاب گروهی را دوباره باز کنید.', 'batch_identity_changed');
            }
            if (!in_array($itemKey, $record['active_keys'], true)) {
                wp_send_json_error(['message' => 'این سفارش هنگام انتخاب گروهی قابل ارسال نبود؛ برای بررسی تازه، انتخاب گروهی را دوباره باز کنید.',
                    'code' => 'batch_item_blocked', 'request_dispatched' => false, 'item_id' => $id]);
            }
            if ($operation !== 'prepare') {
                // After an ambiguous item, never continue charging another row.
                // Initially excluded rows stay excluded and do not halt eligible
                // rows. Recovery is the existing individual "check status" flow,
                // not an automatic batch retry or silently expanded selection.
                foreach ($rows as $candidate) {
                    if (!in_array($candidate['key'], $record['active_keys'], true)) { continue; }
                    $saved = $record['items'][$candidate['key']];
                    if ($candidate['market'] !== $saved['market'] || self::pinIdentity($candidate) !== self::pinIdentity($saved)
                        || ($candidate['foreign_parcel_id'] ?? null) !== ($saved['foreign_parcel_id'] ?? null)) {
                        throw self::validation('ارتباط سفارش با پیش‌نویس افزونه دیگر تغییر کرده است؛ وضعیت را بررسی و انتخاب گروهی را دوباره باز کنید.', 'batch_identity_changed');
                    }
                    if (in_array($candidate['payment_state'], ['pending', 'unknown'], true)
                        || in_array($candidate['creation_state'] ?? '', ['pending', 'unknown'], true)
                        || ($candidate['edit_pending'] ?? false)) {
                        wp_send_json_error(['message' => 'نتیجه یکی از مرسوله‌ها در حال بررسی است؛ ابتدا وضعیت همان مرسوله را بررسی کنید. پرداخت دیگری انجام نشد.',
                            'code' => 'bulk_paused', 'item_id' => $candidate['id'], 'request_dispatched' => false]);
                    }
                }
            }
            if (!$row['eligible']) {
                wp_send_json_error(['message' => $row['message'], 'code' => $row['payment_state'] === 'paid' ? 'already_paid' : 'batch_item_blocked',
                    'payment_state' => $row['payment_state'], 'request_dispatched' => false, 'item_id' => $id]);
            }
            if (($row['foreign_parcel_id'] ?? null) !== ($pin['foreign_parcel_id'] ?? null)) {
                throw self::validation('ارتباط پیش‌نویس سفارش تغییر کرده است؛ انتخاب گروهی را دوباره باز کنید.', 'batch_identity_changed');
            }
            self::assertPostedIdentity($record['source'], $row);
            ConnectionCache::assertCurrentIdentity($record['connection_id']);

            if ($record['source'] === 'orders') {
                $_POST['order_id'] = $id;
                $_POST['market'] = $row['market'];
                if ($row['market'] === 'basalam') {
                    $_POST['basalam_parcel_id'] = $row['basalam_parcel_id'];
                    if ($operation === 'pay') { $_POST['method'] = 19; unset($_POST['pay_method']); }
                } else { unset($_POST['basalam_parcel_id']); }
                $handler = ['prepare' => 'prepareParcel', 'upsert' => 'upsertParcel', 'quotes' => 'getQuotes',
                    'select' => 'selectQuote', 'pay' => 'payParcel'][$operation];
                if ($operation === 'prepare') {
                    ParcelActions::prepareParcel($row['market'] === 'website' && ($_POST['refresh_order_receiver'] ?? null) === '1');
                } else { ParcelActions::$handler(); }
            } else {
                $_POST['parcel_id'] = $id;
                $_POST['market'] = $row['market'];
                // Native drafts retain the single checkout's exact-source preflight.
                unset($_POST['basalam_parcel_id']);
                if ($row['market'] === 'basalam') {
                    $_POST['_salam_gate_bulk_native_identity'] = $row['native_pin'] + ['salam_gate_parcel_id' => $id];
                }
                if ($row['market'] === 'basalam' && $operation === 'pay') { $_POST['method'] = 19; unset($_POST['pay_method']); }
                if ($operation === 'pay') { $_POST['_salam_gate_bulk_weight_required'] = '1'; }
                if ($operation === 'prepare') {
                    // Detail GET only; upstream /quotes GET can persist a rate.
                    $detail = (new ParcelService())->getDetail($id);
                    if (ParcelService::positiveId($detail['id'] ?? null) !== $id) {
                        throw self::validation('اطلاعات مرسوله با انتخاب شما مطابقت ندارد.', 'batch_identity_changed');
                    }
                    ConnectionCache::assertCurrentIdentity($record['connection_id']);
                    wp_send_json_success(['detail' => $detail, 'parcel' => $row['parcel'], 'row' => $row]);
                }
                $handler = ['quotes' => 'checkout', 'select' => 'checkoutSelect', 'pay' => 'checkoutPay'][$operation];
                ParcelsListActions::$handler();
            }
        } catch (SalamGateException $exception) { self::sendError($exception); }
        finally { if ($lease !== null) { OptionLock::release($lockKey, $lease); } }
    }

    private static function selectedIds($raw): array
    {
        if (!is_array($raw) || $raw === [] || count($raw) > self::MAX_BATCH || !array_is_list($raw)) {
            throw self::validation('بین ۱ تا ۲۰ سفارش را برای ارسال گروهی انتخاب کنید.');
        }
        $ids = [];
        foreach (wp_unslash($raw) as $value) {
            $id = ParcelService::positiveId($value);
            if ($id === null || $id > 9007199254740991) { throw self::validation('شناسه یکی از سفارش‌های انتخاب‌شده معتبر نیست.'); }
            $ids[] = $id;
        }
        return array_values(array_unique($ids));
    }

    private static function session($batch): array
    {
        if (!is_string($batch) || !preg_match('/^[a-f0-9]{32}$/D', $batch)) {
            throw self::validation('انتخاب گروهی معتبر نیست؛ دوباره سفارش‌ها را انتخاب کنید.', 'batch_expired');
        }
        $record = get_transient(self::SESSION_PREFIX . $batch);
        if (!is_array($record) || ($record['expires_at'] ?? 0) <= time()
            || ($record['user_id'] ?? null) !== get_current_user_id()
            || !in_array($record['source'] ?? '', ['orders', 'parcels'], true)
            || !is_array($record['markets'] ?? null)
            || !is_array($record['foreign_ids'] ?? null)
            || !is_array($record['items'] ?? null) || $record['items'] === [] || count($record['items']) > self::MAX_BATCH
            || !is_array($record['active_keys'] ?? null) || !array_is_list($record['active_keys'])
            || !is_array($record['active_ids'] ?? null) || !array_is_list($record['active_ids'])
            || ($record['connection_id'] ?? '') !== ConnectionCache::identityHash()) {
            throw self::validation('انتخاب گروهی منقضی شده یا حساب متصل تغییر کرده است؛ دوباره انتخاب کنید.', 'batch_expired');
        }
        $record['ids'] = self::selectedIds($record['ids'] ?? null);
        foreach ($record['active_ids'] as $id) {
            if (!is_int($id) || !in_array($id, $record['ids'], true)) {
                throw self::validation('انتخاب گروهی معتبر نیست؛ دوباره سفارش‌ها را انتخاب کنید.', 'batch_expired');
            }
        }
        foreach ($record['items'] as $key => $pin) {
            if (!is_array($pin) || !is_string($key) || ($pin['key'] ?? null) !== $key
                || !in_array($pin['id'] ?? null, $record['ids'], true) || !is_bool($pin['eligible'] ?? null)
                || !is_string($pin['market'] ?? null)
                || !in_array($pin['market'], array_merge([''], ParcelsListService::ALL_MARKETS), true)) {
                throw self::validation('انتخاب گروهی معتبر نیست؛ دوباره انتخاب کنید.', 'batch_expired');
            }
            $native = $pin['basalam_parcel_id'] ?? null;
            $invoice = $pin['basalam_order_id'] ?? null;
            $identity = $pin['native_pin'] ?? null;
            if ($identity !== null && (!is_array($identity) || !is_int($native) || $native < 1
                || !is_int($invoice) || $invoice < 1 || ($identity['parcel_id'] ?? null) !== $native
                || ($identity['invoice_id'] ?? null) !== $invoice || !is_int($identity['vendor_id'] ?? null) || $identity['vendor_id'] < 1
                || !is_string($identity['allocation_hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $identity['allocation_hash']))) {
                throw self::validation('ارتباط بسته انتخاب‌شده معتبر نیست؛ دوباره انتخاب کنید.', 'batch_expired');
            }
            $expectedKey = $record['source'] . ':' . $pin['id'];
            if ($record['source'] === 'orders' && $pin['market'] === 'basalam' && $native !== null) { $expectedKey .= ':basalam:' . $native; }
            if ($key !== $expectedKey || ($pin['market'] === 'basalam' && $pin['eligible'] && $identity === null)
                || ($pin['market'] !== 'basalam' && ($native !== null || $invoice !== null || $identity !== null))) {
                throw self::validation('هویت بسته انتخاب‌شده معتبر نیست؛ دوباره انتخاب کنید.', 'batch_expired');
            }
        }
        if (count(array_unique($record['active_keys'])) !== count($record['active_keys'])) {
            throw self::validation('بسته‌های انتخاب‌شده تکراری‌اند؛ دوباره انتخاب کنید.', 'batch_expired');
        }
        foreach ($record['active_keys'] as $key) {
            if (!is_string($key) || !isset($record['items'][$key]) || !$record['items'][$key]['eligible']) {
                throw self::validation('بسته انتخاب‌شده معتبر نیست؛ دوباره انتخاب کنید.', 'batch_expired');
            }
        }
        ConnectionCache::assertCurrentIdentity($record['connection_id']);
        return $record;
    }

    private static function rows(string $source, array $ids, ?int $nativeTarget = null): array
    {
        if ($source === 'orders') {
            // One owner-filtered snapshot per chunk, not two remote reads for
            // every order in the group. No quote/registration side effects.
            $foreignReviews = self::foreignReviews($ids);
            $rows = [];
            foreach ($ids as $id) {
                $order = wc_get_order($id);
                if ($order instanceof \WC_Order && OrderContext::marketFor($id) === 'basalam') {
                    array_push($rows, ...self::nativeOrderRows($order));
                } else { $rows[] = self::orderRow($id, $foreignReviews[$id] ?? null); }
            }
            return $rows;
        }
        $response = ClientFactory::make()->get(SalamGateClient::PATH_PARCELS, [
            'ids' => $ids, 'market_name' => ParcelsListService::ALL_MARKETS, 'per_page' => 30,
        ]);
        $body = $response['body'] ?? null;
        if (!is_array($body) || !is_array($body['data'] ?? null) || !array_is_list($body['data'])) {
            throw self::validation('فهرست مرسوله‌های انتخاب‌شده دریافت نشد؛ دوباره تلاش کنید.', 'batch_identity_changed');
        }
        $found = [];
        foreach ($body['data'] as $raw) {
            $id = is_array($raw) ? ParcelService::positiveId($raw['id'] ?? null) : null;
            $market = is_array($raw) ? ($raw['market']['value'] ?? null) : null;
            if ($id === null || !in_array($id, $ids, true) || isset($found[$id]) || !LabelEligibility::validListShape($raw)
                || !is_int($raw['status']['id']) || !ParcelStatuses::isValid($raw['status']['id'])
                || !in_array($market, ParcelsListService::ALL_MARKETS, true)) {
                throw self::validation('هویت یا وضعیت یکی از مرسوله‌ها تأیید نشد؛ فهرست را دوباره دریافت کنید.', 'batch_identity_changed');
            }
            $parcel = ParcelsListService::normalizeItem($raw);
            $row = self::emptyRow('parcels', $id);
            $row = array_replace($row, ['market' => $market, 'parcel_id' => $id, 'label' => 'مرسوله #' . $id,
                'receiver_name' => $parcel['receiver_name'] ?? '', 'contents' => $parcel['contents'] ?? '',
                'payment_state' => $parcel['payment_state'], 'eligible' => $parcel['can_resume_payment'],
                'message' => $parcel['payment_message'] ?: ($parcel['payment_note'] ?? ''),
                'parcel' => $parcel]);
            if ($market === 'basalam') {
                $row['basalam_parcel_id'] = ParcelService::positiveId($parcel['external_id'] ?? null);
                if ($nativeTarget === null || $nativeTarget === $id) {
                    try {
                        self::nativeDependencies();
                        $native = BasalamCheckoutPreflight::check($parcel);
                        $row['basalam_order_id'] = $native['basalam_order_id'];
                        $row['native_pin'] = self::nativePin($native['preflight']['identity'], $native['preflight']['order_data']['items']);
                    } catch (SalamGateException $exception) {
                        $row['eligible'] = false;
                        $row['message'] = $exception->firstMessage();
                    }
                }
            }
            $edit = get_option('salam_gate_parcel_edit_' . $id, null);
            $row['edit_pending'] = is_array($edit) && !in_array($edit['state'] ?? '', ['selection_required', 'replaced'], true);
            $found[$id] = $row;
        }
        return array_map(static function (int $id) use ($found): array {
            return $found[$id] ?? self::emptyRow('parcels', $id);
        }, $ids);
    }

    private static function orderRow(int $id, ?array $foreignReview = null): array
    {
        $row = self::emptyRow('orders', $id);
        $order = wc_get_order($id);
        if (!$order instanceof \WC_Order || (int) $order->get_id() !== $id || $order->get_type() !== 'shop_order') { return $row; }
        $market = OrderContext::marketFor($id);
        $row['market'] = $market;
        $row['label'] = 'سفارش #' . $order->get_order_number();
        $row['receiver_name'] = trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name());
        $titles = [];
        foreach ($order->get_items() as $line) { $titles[] = $line->get_name(); }
        $row['contents'] = implode('، ', array_slice($titles, 0, 3));
        $parcelId = ParcelService::positiveId($order->get_meta('_salam_gate_parcel_id'));
        $row['parcel_id'] = $parcelId;
        $row['foreign_parcel_id'] = ParcelService::positiveId($order->get_meta('_salamgate_parcel_id'));
        if ($market !== 'website') {
            $row['message'] = 'بسته اصلی این سفارش باید دوباره بررسی شود؛ انتخاب گروهی را دوباره باز کنید.';
            return $row;
        }
        if (in_array($order->get_status(), ['cancelled', 'refunded', 'failed', 'trash'], true)) {
            $row['message'] = 'این سفارش لغو، مرجوع یا ناموفق شده و قابل ارسال نیست.';
            return $row;
        }
        if (!in_array($order->get_status(), OrderMetabox::WEBSITE_ACTIONABLE_STATUSES, true)) {
            $row['message'] = 'این سفارش هنوز آماده ارسال نیست؛ وضعیت سفارش را بررسی کنید.';
            return $row;
        }
        $savedMarket = $order->get_meta('_salam_gate_market');
        if (!in_array($savedMarket, ['', 'website'], true)) {
            $row['message'] = 'ارتباط مرسوله ذخیره‌شده با سفارش تأیید نشد.';
            return $row;
        }
        $outcome = $parcelId !== null ? get_option(ParcelService::paymentOutcomeKey($parcelId), null) : null;
        $state = $outcome !== null ? (is_array($outcome) ? ($outcome['state'] ?? 'unknown') : 'unknown') : '';
        if ($outcome !== null && !in_array($state, ['paid', 'pending', 'unknown'], true)) { $state = 'unknown'; }
        $storedExternal = $order->get_meta('_salam_gate_external_id');
        $external = ParcelService::positiveId($storedExternal !== '' ? $storedExternal : get_option('salam_gate_website_order_identity_' . $id, null));
        if ($storedExternal !== '' && $external === null) {
            $row['message'] = 'ارتباط مرسوله ذخیره‌شده با سفارش تأیید نشد.';
            return $row;
        }
        if ($external === null && $parcelId !== null && $storedExternal === '') { $external = $id; }
        $states = [$state];
        foreach (['salam_gate_website_payment_order_' . $id, 'salam_gate_website_payment_external_' . ($external ?? 0)] as $key) {
            $payment = get_option($key, null);
            if ($payment !== null) { $states[] = is_array($payment) ? ($payment['state'] ?? 'unknown') : 'unknown'; }
        }
        $status = (int) $order->get_meta('_salam_gate_status');
        if ($order->get_meta('_salam_gate_paid_at') !== '' || ParcelService::positiveId($order->get_meta('_salam_gate_invoice_id')) !== null
            || ParcelStatuses::hasReceipt($status) || $order->get_meta('_salam_gate_tracking_code') !== '' || in_array('paid', $states, true)) {
            $row['payment_state'] = 'paid';
            $row['message'] = 'هزینه ارسال این سفارش قبلاً پرداخت شده است.';
            return $row;
        }
        if ($order->get_meta('_salam_gate_pay_unknown') !== '' || in_array('unknown', $states, true) || in_array('pending', $states, true)) {
            $row['payment_state'] = in_array('pending', $states, true) ? 'pending' : 'unknown';
            $row['message'] = 'پرداخت قبلی در حال بررسی است؛ ابتدا وضعیت همان مرسوله را بررسی کنید.';
            return $row;
        }
        $claim = $external !== null ? get_option('salam_gate_create_order_' . hash('sha256', 'website:' . $external), null) : null;
        $row['creation_state'] = is_array($claim) ? ($claim['state'] ?? 'unknown') : ($claim === null ? '' : 'unknown');
        $edit = $parcelId !== null ? get_option('salam_gate_parcel_edit_' . $parcelId, null) : null;
        $row['edit_pending'] = is_array($edit) && !in_array($edit['state'] ?? '', ['selection_required', 'replaced'], true);
        if (in_array($row['creation_state'], ['pending', 'unknown'], true) || $row['edit_pending']) {
            $row['message'] = 'ثبت یا ویرایش قبلی در حال بررسی است؛ ابتدا وضعیت مرسوله را بررسی کنید.';
            return $row;
        }
        // Foreign metadata alone does not mean the WooSalam draft was paid.
        // Resume only the existing WooSalam parcel after BOTH exact owner-
        // filtered drafts are verified unpaid. Never adopt/erase the other link.
        if (self::hasForeignEvidence($order)) {
            if ($foreignReview === null || !($foreignReview['allowed'] ?? false)) {
                $row['payment_state'] = $foreignReview['payment_state'] ?? 'blocked';
                $row['message'] = $foreignReview['message'] ?? 'این سفارش در افزونه سلام‌گیت دیگری مرسوله دارد؛ از همان مسیر بررسی کنید.';
                return $row;
            }
            $row['notice'] = 'پیش‌نویس افزونه رسمی هنوز پرداخت نشده است؛ این ارسال فقط مرسوله موجود ووسلام را ادامه می‌دهد. برای همین سفارش از هر دو افزونه پرداخت نکنید.';
        }
        // Reuse the established read-only row guard: stale native scopes, deleted
        // Basalam mappings, duplicate metadata, conflicting website identities,
        // replaced drafts and invalid saved status must not acquire a new bulk path.
        $action = OrderListAction::forOrder($order);
        if (!in_array($action['mode'], ['edit', 'payment'], true)) {
            $row['message'] = $action['description'];
            return $row;
        }
        $row['payment_state'] = 'unpaid';
        $row['eligible'] = true;
        $row['message'] = '';
        return $row;
    }

    private static function nativeDependencies(): void
    {
        require_once dirname(__DIR__, 2) . '/Order/BasalamParcelSet.php';
        require_once dirname(__DIR__, 2) . '/Order/BasalamParcelOrder.php';
        require_once dirname(__DIR__, 2) . '/Order/BasalamShippingPreflight.php';
        require_once dirname(__DIR__, 2) . '/Order/BasalamCheckoutPreflight.php';
    }

    /** Discover actual children, never turn a parent into one invented parcel. */
    private static function nativeOrderRows(\WC_Order $order): array
    {
        self::nativeDependencies();
        $fallback = self::emptyRow('orders', (int) $order->get_id());
        $fallback['market'] = 'basalam';
        $fallback['label'] = 'سفارش #' . $order->get_order_number();
        $reason = BasalamShippingPreflight::localStatusBlockReason($order);
        if ($reason !== null) { $fallback['message'] = $reason; return [$fallback]; }
        try {
            $ids = []; $cursor = null; $seen = [];
            for ($page = 0; $page < 10; ++$page) {
                $result = BasalamParcelSet::discover($order, $cursor);
                foreach ($result['parcels'] as $child) {
                    $native = ParcelService::positiveId($child['basalam_parcel_id'] ?? null);
                    if ($native === null) { throw self::validation('هویت یکی از بسته‌های سفارش تأیید نشد.'); }
                    $ids[$native] = $native;
                    if (count($ids) > self::MAX_BATCH) { throw self::validation('این سفارش بیش از ۲۰ بسته دارد؛ بسته‌ها را از صفحه سفارش جداگانه ارسال کنید.'); }
                }
                $cursor = $result['next_cursor'] ?? null;
                if ($cursor === null) { break; }
                if (!is_string($cursor) || isset($seen[$cursor])) { throw self::validation('فهرست کامل بسته‌های سفارش دریافت نشد؛ آن را از صفحه سفارش بررسی کنید.'); }
                $seen[$cursor] = true;
            }
            if ($cursor !== null || $ids === []) { throw self::validation('فهرست کامل بسته‌های سفارش دریافت نشد؛ آن را از صفحه سفارش بررسی کنید.'); }
            ksort($ids);
            $rows = []; $snapshots = []; $parent = null;
            foreach ($ids as $native) {
                $snapshot = BasalamParcelSet::load($order, $native);
                $parent ??= $snapshot['order'];
                $snapshots[] = $snapshot['parcel'];
                $rows[] = self::nativeOrderRow($order, $native, $snapshot);
            }
            BasalamParcelSet::assertAllocations($parent, $snapshots);
            return $rows;
        } catch (SalamGateException $exception) {
            $fallback['message'] = $exception->firstMessage();
            return [$fallback];
        }
    }

    /** A pure allocation fence: financial_report totals are NOT an available balance. */
    private static function nativePin(array $identity, array $items): array
    {
        $allocation = [];
        foreach ($items as $item) {
            $external = ParcelService::positiveId($item['external_id'] ?? $item['item_id'] ?? null);
            $quantity = ParcelService::positiveId($item['quantity'] ?? null);
            if ($external === null || $quantity === null || isset($allocation[$external])) {
                throw self::validation('اقلام بسته باسلام قابل تأیید نیست.', 'batch_identity_changed');
            }
            $allocation[$external] = $quantity;
        }
        ksort($allocation);
        return ['invoice_id' => $identity['invoice_id'], 'vendor_id' => $identity['vendor_id'],
            'parcel_id' => $identity['parcel_id'], 'allocation_hash' => hash('sha256', serialize($allocation))];
    }

    private static function nativeOrderRow(\WC_Order $root, int $native, ?array $snapshot = null, bool $fresh = true): array
    {
        self::nativeDependencies();
        $id = (int) $root->get_id();
        $row = self::emptyRow('orders', $id);
        $row['key'] = $row['item_key'] = 'orders:' . $id . ':basalam:' . $native;
        $row['market'] = 'basalam'; $row['order_id'] = $id;
        $row['basalam_parcel_id'] = $native;
        $row['basalam_order_id'] = BasalamOrderContext::getInvoiceId($id);
        $row['label'] = 'سفارش #' . $root->get_order_number() . ' · بسته #' . $native;
        $row['receiver_name'] = trim($root->get_shipping_first_name() . ' ' . $root->get_shipping_last_name());
        try {
            if ($fresh) {
                $snapshot ??= BasalamParcelSet::load($root, $native);
                $data = BasalamShippingPreflight::fromSnapshots(null, $snapshot['order'], $snapshot['parcel'],
                    $snapshot['vendor_id'], $snapshot['invoice_id'], $native, [], false, true);
                $row['native_pin'] = self::nativePin($data['identity'], $data['order_data']['items']);
                $row['contents'] = implode('، ', array_slice(array_column($data['order_data']['items'], 'title'), 0, 3));
                // Financial/original-item validation above is still performed for
                // cancelled children, but they can never enter a mutation handler.
                $reason = $data['shipping_blocked_reason'];
            } else { $reason = null; }
            $localReason = BasalamShippingPreflight::localStatusBlockReason($root);
            $scope = BasalamParcelOrder::stored($root, $native);
            if ($scope === null) {
                $mapping = $root->get_meta('_salam_gate_basalam_mapping');
                if (is_array($mapping) && $mapping === ['order_id' => $id, 'invoice_id' => $row['basalam_order_id'],
                    'vendor_id' => (int) syncBasalamSettings()->getSettings('vendor_id'), 'parcel_id' => $native]) { $scope = $root; }
                else {
                    foreach ($root->get_meta_data() as $meta) {
                        if (str_starts_with((string) ($meta->get_data()['key'] ?? ''), '_salam_gate_bp_' . $native . '_')) {
                            throw self::validation('ارتباط ذخیره‌شده این بسته تأیید نشد.', 'batch_identity_changed');
                        }
                    }
                    if ($root->get_meta('_salam_gate_parcel_id') !== '' && !is_array($mapping)) {
                        throw self::validation('ارتباط ارسال قبلی سفارش مشخص نیست؛ ابتدا آن را از صفحه سفارش بررسی کنید.', 'batch_identity_changed');
                    }
                }
            }
            if ($scope !== null) {
                $row['parcel_id'] = ParcelService::positiveId($scope->get_meta('_salam_gate_parcel_id'));
                $savedMarket = $scope->get_meta('_salam_gate_market');
                $savedNative = $scope->get_meta('_salam_gate_external_id');
                if (!in_array($savedMarket, ['', 'basalam'], true) || ($savedNative !== '' && ParcelService::positiveId($savedNative) !== $native)) {
                    throw self::validation('ارتباط رسید با بسته اصلی باسلام تغییر کرده است.', 'batch_identity_changed');
                }
                $payment = $row['parcel_id'] !== null ? get_option(ParcelService::paymentOutcomeKey($row['parcel_id']), null) : null;
                if ($scope->get_meta('_salam_gate_paid_at') !== '' || ParcelService::positiveId($scope->get_meta('_salam_gate_invoice_id')) !== null
                    || ParcelStatuses::hasReceipt((int) $scope->get_meta('_salam_gate_status')) || $scope->get_meta('_salam_gate_tracking_code') !== ''
                    || is_array($payment) && ($payment['state'] ?? '') === 'paid') {
                    $row['payment_state'] = 'paid'; $row['message'] = 'هزینه ارسال این بسته قبلاً پرداخت شده است.'; return $row;
                }
                if ($scope->get_meta('_salam_gate_pay_unknown') !== '' || $payment !== null) {
                    $row['payment_state'] = is_array($payment) && ($payment['state'] ?? '') === 'pending' ? 'pending' : 'unknown';
                    $row['message'] = 'پرداخت قبلی این بسته در حال بررسی است؛ ابتدا وضعیت آن را بررسی کنید.'; return $row;
                }
            }
            $claim = get_option('salam_gate_create_order_' . hash('sha256', 'basalam:' . $native), null);
            $row['creation_state'] = $claim === null ? '' : (is_array($claim) ? ($claim['state'] ?? 'unknown') : 'unknown');
            $edit = $row['parcel_id'] !== null ? get_option('salam_gate_parcel_edit_' . $row['parcel_id'], null) : null;
            $row['edit_pending'] = $edit !== null && !(is_array($edit) && ($edit['state'] ?? '') === 'selection_required');
            $row['eligible'] = $reason === null && $localReason === null && !in_array($row['creation_state'], ['pending', 'unknown'], true) && !$row['edit_pending'];
            $row['payment_state'] = $row['eligible'] ? 'unpaid' : 'blocked';
            $row['message'] = $localReason ?? $reason ?? ($row['eligible'] ? '' : 'ثبت یا ویرایش قبلی این بسته در حال بررسی است.');
            return $row;
        } catch (SalamGateException $exception) { $row['message'] = $exception->firstMessage(); return $row; }
    }

    /** Recheck the target upstream, not every sibling's rates, on method changes. */
    private static function savedRows(array $record, string $target, bool $readOnly = false): array
    {
        if ($readOnly) {
            // Preparation never writes a parcel or pays. Re-read its exact pin
            // instead of re-reading every sibling N times during initial review.
            // Mutations still inspect the complete saved group below.
            $record['ids'] = [$record['items'][$target]['id']];
            $record['items'] = [$target => $record['items'][$target]];
        }
        if ($record['source'] === 'parcels') {
            $rows = self::rows('parcels', $record['ids'], $record['items'][$target]['id']);
            foreach ($rows as &$row) {
                if ($row['key'] !== $target && $row['market'] === 'basalam') {
                    $pin = $record['items'][$row['key']] ?? [];
                    $row['basalam_order_id'] = $pin['basalam_order_id'] ?? null;
                    if ($row['basalam_parcel_id'] === ($pin['basalam_parcel_id'] ?? null)) { $row['native_pin'] = $pin['native_pin'] ?? null; }
                }
            }
            unset($row);
            return $rows;
        }
        $websiteIds = [];
        foreach ($record['items'] as $pin) { if ($pin['market'] !== 'basalam') { $websiteIds[] = $pin['id']; } }
        $foreign = self::foreignReviews(array_values(array_unique($websiteIds)));
        $rows = [];
        foreach ($record['items'] as $key => $pin) {
            $root = wc_get_order($pin['id']);
            if (!$root instanceof \WC_Order || OrderContext::marketFor($pin['id']) !== $pin['market']) {
                $row = self::emptyRow('orders', $pin['id']); $row['key'] = $row['item_key'] = $key;
            } elseif ($pin['market'] === 'basalam' && ($pin['basalam_parcel_id'] ?? null) !== null) {
                $row = self::nativeOrderRow($root, $pin['basalam_parcel_id'], null, $key === $target);
                if ($key !== $target) {
                    $row['native_pin'] = $pin['native_pin'];
                    if (BasalamOrderContext::getInvoiceId($pin['id']) !== $pin['basalam_order_id']
                        || (int) syncBasalamSettings()->getSettings('vendor_id') !== $pin['native_pin']['vendor_id']) { $row['native_pin'] = null; }
                }
            } else { $row = self::orderRow($pin['id'], $foreign[$pin['id']] ?? null); }
            $rows[] = $row;
        }
        return $rows;
    }

    private static function pin(array $row): array
    {
        return array_intersect_key($row, array_flip(['key', 'id', 'market', 'eligible', 'foreign_parcel_id',
            'basalam_parcel_id', 'basalam_order_id', 'native_pin']));
    }

    private static function pinIdentity(array $row): array
    {
        return [$row['market'] ?? '', $row['basalam_parcel_id'] ?? null, $row['basalam_order_id'] ?? null, $row['native_pin'] ?? null];
    }

    private static function hasForeignEvidence(\WC_Order $order): bool
    {
        foreach (['_salamgate_parcel_id', '_salamgate_tracking_code', '_salamgate_status'] as $key) {
            if (!in_array($order->get_meta($key), ['', null, false, 0, '0'], true)) { return true; }
        }
        return false;
    }

    /** Read-only coexistence review; an unpaid reference draft is not a new financial identity. */
    private static function foreignReviews(array $orderIds): array
    {
        $pairs = [];
        $lookup = [];
        foreach ($orderIds as $orderId) {
            $order = wc_get_order($orderId);
            if (!$order instanceof \WC_Order || !self::hasForeignEvidence($order)
                || OrderContext::marketFor($orderId) !== 'website'
                || !in_array($order->get_status(), OrderMetabox::WEBSITE_ACTIONABLE_STATUSES, true)) { continue; }
            $proId = ParcelService::positiveId($order->get_meta('_salam_gate_parcel_id'));
            $foreignId = ParcelService::positiveId($order->get_meta('_salamgate_parcel_id'));
            $foreignStatus = $order->get_meta('_salamgate_status');
            $foreignTracking = $order->get_meta('_salamgate_tracking_code');
            if ($proId === null || $foreignId === null
                || !in_array($foreignTracking, ['', null, false], true)
                || !in_array($foreignStatus, ['', null, false, 0, '0', 7, '7'], true)) { continue; }
            $pairs[$orderId] = [$proId, $foreignId];
            $lookup[$proId] = $proId;
            $lookup[$foreignId] = $foreignId;
        }
        if ($pairs === []) { return []; }
        $views = [];
        $connection = ConnectionCache::identityHash();
        try {
            foreach (array_chunk(array_values($lookup), 30) as $chunk) {
                $response = ClientFactory::make()->get(SalamGateClient::PATH_PARCELS,
                    ['ids' => $chunk, 'market_name' => ['website'], 'per_page' => 30]);
                ConnectionCache::assertCurrentIdentity($connection);
                $body = $response['body'] ?? null;
                if (!is_array($body) || !is_array($body['data'] ?? null) || !array_is_list($body['data'])) {
                    throw self::validation('وضعیت پیش‌نویس‌های سفارش تأیید نشد.', 'batch_identity_changed');
                }
                foreach ($body['data'] as $raw) {
                    $id = is_array($raw) ? ParcelService::positiveId($raw['id'] ?? null) : null;
                    if ($id === null || !in_array($id, $chunk, true) || isset($views[$id])
                        || !LabelEligibility::validListShape($raw) || ($raw['market']['value'] ?? null) !== 'website'
                        || !is_int($raw['status']['id'] ?? null) || !ParcelStatuses::isValid($raw['status']['id'])) {
                        throw self::validation('هویت یا وضعیت پیش‌نویس تأیید نشد.', 'batch_identity_changed');
                    }
                    $views[$id] = ParcelViewNormalizer::fromListItem($raw);
                }
            }
        } catch (SalamGateException $exception) {
            return array_fill_keys(array_keys($pairs), ['allowed' => false, 'payment_state' => 'unknown',
                'message' => 'وضعیت پیش‌نویس افزونه رسمی دریافت نشد؛ دوباره فهرست را باز کنید. ثبت یا پرداختی انجام نشد.']);
        }
        $reviews = [];
        foreach ($pairs as $orderId => $pair) {
            $review = ['allowed' => true];
            foreach (array_unique($pair) as $parcelId) {
                $view = $views[$parcelId] ?? null;
                $local = get_option(ParcelService::paymentOutcomeKey($parcelId), null);
                $edit = get_option('salam_gate_parcel_edit_' . $parcelId, null);
                $editing = $edit !== null && !(is_array($edit) && ($edit['state'] ?? '') === 'selection_required');
                if ($view === null || $local !== null || $editing
                    || $view['status_id'] !== ParcelStatuses::NEW || !in_array($view['invoice_status'], [null, 3, 5], true)
                    || $view['tracking_code'] !== null) {
                    $paid = $view !== null && ($view['invoice_status'] === 2 || ParcelStatuses::hasReceipt($view['status_id'])
                        || $view['tracking_code'] !== null) || is_array($local) && ($local['state'] ?? '') === 'paid';
                    $review = ['allowed' => false, 'payment_state' => $paid ? 'paid' : 'unknown',
                        'message' => $paid ? 'یکی از مرسوله‌های این سفارش قبلاً پرداخت یا ارسال شده است؛ پرداخت دوباره انجام نمی‌شود.'
                            : 'وضعیت یکی از پیش‌نویس‌های این سفارش تأیید نشد؛ ابتدا وضعیت آن را بررسی کنید. پرداخت دیگری انجام نشد.'];
                    break;
                }
            }
            $reviews[$orderId] = $review;
        }
        return $reviews;
    }

    private static function emptyRow(string $source, int $id): array
    {
        return ['id' => $id, 'key' => $source . ':' . $id, 'item_key' => $source . ':' . $id,
            'order_id' => $source === 'orders' ? $id : null, 'basalam_parcel_id' => null, 'basalam_order_id' => null, 'native_pin' => null,
            'label' => ($source === 'orders' ? 'سفارش #' : 'مرسوله #') . $id,
            'market' => '', 'receiver_name' => '', 'contents' => '', 'parcel_id' => null, 'eligible' => false,
            'payment_state' => 'blocked', 'creation_state' => '', 'edit_pending' => false,
            'message' => $source === 'orders' ? 'سفارش یافت نشد.' : 'مرسوله برای حساب متصل یافت نشد.'];
    }

    private static function assertPostedIdentity(string $source, array $row): void
    {
        if (array_key_exists('basalam_parcel_id', $_POST)
            && ($row['market'] !== 'basalam' || ParcelService::positiveId($_POST['basalam_parcel_id']) !== $row['basalam_parcel_id'])) {
            throw self::validation('بسته اصلی باسلام با انتخاب شما مطابقت ندارد.', 'batch_identity_changed');
        }
        if (array_key_exists('basalam_order_id', $_POST)
            && ($row['market'] !== 'basalam' || ParcelService::positiveId($_POST['basalam_order_id']) !== $row['basalam_order_id'])) {
            throw self::validation('سفارش اصلی باسلام با انتخاب شما مطابقت ندارد.', 'batch_identity_changed');
        }
        foreach (['market', 'original_market'] as $key) {
            if (isset($_POST[$key]) && $_POST[$key] !== $row['market']) {
                throw self::validation('مبدأ سفارش با اطلاعات تأییدشده مطابقت ندارد.', 'batch_identity_changed');
            }
        }
        foreach (['order_id' => $source === 'orders' ? $row['id'] : null,
            'parcel_id' => $source === 'orders' ? $row['parcel_id'] : $row['id']] as $key => $expected) {
            if (isset($_POST[$key]) && ParcelService::positiveId($_POST[$key]) !== $expected) {
                throw self::validation('شناسه سفارش یا مرسوله تغییر کرده است؛ دوباره اطلاعات را باز کنید.', 'batch_identity_changed');
            }
        }
    }

    private static function guard(): void
    {
        $nonce = $_POST['_wpnonce'] ?? null;
        if (!current_user_can('manage_woocommerce') || !is_string($nonce)
            || !wp_verify_nonce(wp_unslash($nonce), 'basalam_pro_nonce')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز!', 'request_dispatched' => false], 403);
        }
    }

    private static function validation(string $message, string $code = 'validation'): SalamGateException
    {
        return new SalamGateException($message, 422, [['location' => 'bulk.' . $code, 'message' => $code]], SalamGateException::KIND_VALIDATION);
    }

    private static function sendError(SalamGateException $exception): void
    {
        $errors = $exception->getErrors();
        $bulkValidation = str_starts_with($errors[0]['location'] ?? '', 'bulk.');
        $code = $bulkValidation ? substr($errors[0]['location'], 5) : 'batch_unavailable';
        wp_send_json_error(['message' => $bulkValidation ? $exception->getMessage() : $exception->firstMessage(), 'code' => $code,
            'errors' => $errors, 'kind' => $exception->getKind(), 'request_dispatched' => false]);
    }
}
