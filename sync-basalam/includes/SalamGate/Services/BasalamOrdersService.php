<?php

namespace SyncBasalam\SalamGate\Services;

use SyncBasalam\SalamGate\Utils\Arrays;
use SyncBasalam\SalamGate\FeatureAvailability;
use SyncBasalam\SalamGate\Contracts\ParcelStatuses;
use SyncBasalam\SalamGate\Order\BasalamOrderContext;
use SyncBasalam\SalamGate\Order\BasalamOrderProducts;
use SyncBasalam\SalamGate\Order\BasalamParcelReader;
use SyncBasalam\SalamGate\Order\BasalamParcelOrder;
use SyncBasalam\SalamGate\Order\BasalamShippingPreflight;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Utils\Digits;

defined('ABSPATH') || exit;

require_once __DIR__ . '/../Order/BasalamShippingPreflight.php';
require_once __DIR__ . '/../Order/BasalamOrderProducts.php';

/** Real vendor orders only; selection never creates, charges, or posts a remote parcel. */
final class BasalamOrdersService
{
    private $importer;
    private $profileReader;
    private $activeShopReader;

    /** Internal dependency injection, never populated from browser input. */
    public function __construct(?callable $importer = null, ?callable $profileReader = null, ?callable $activeShopReader = null)
    {
        $this->importer = $importer ?? static function (int $invoiceId): array {
            if (!class_exists(\SyncBasalam\Services\Orders\OrderManager::class)) {
                throw self::error('برای دریافت این سفارش، افزونه پایه ووسلام باید فعال باشد.');
            }
            return \SyncBasalam\Services\Orders\OrderManager::createOrderWoo(['invoice_id' => $invoiceId]);
        };
        $this->profileReader = $profileReader ?? static fn (): array => (new AccountService())->profile();
        $this->activeShopReader = $activeShopReader ?? static fn (): ?int => FeatureAvailability::activeShopId();
    }

    /** Lightweight authenticated list; definitive eligibility is always checked on selection. */
    public function listOrders(?string $cursor = null, ?int $exactOrderId = null, int $days = 90, string $search = ''): array
    {
        if ($days < 1 || $days > 90 || ($cursor !== null
            && (strlen($cursor) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $cursor)))) {
            throw self::error('درخواست فهرست سفارش‌های باسلام معتبر نیست.');
        }
        if ($search !== '' && (!preg_match('/^.{1,120}$/usD', $search) || preg_match('/[\x00-\x1f\x7f]/', $search))) {
            throw self::error('نام گیرنده یا محصول را حداکثر در ۱۲۰ نویسه وارد کنید.');
        }
        $search = self::searchText($search);
        if ($search !== '' && $exactOrderId !== null) {
            throw self::error('شماره سفارش و جست‌وجوی نام را جداگانه وارد کنید.');
        }
        $vendorId = self::connectedVendorId();
        $identity = ConnectionCache::identityHash();
        $searchedPages = 0;
        if ($exactOrderId !== null) {
            if ($exactOrderId < 1) { throw self::error('شناسه سفارش باسلام معتبر نیست.'); }
            $detail = BasalamParcelReader::order($vendorId, $exactOrderId);
            if (self::positiveId($detail['id'] ?? null) !== $exactOrderId
                || self::positiveId($detail['parcel_detail']['parcel_id'] ?? null) === null
                || (isset($detail['vendor']['id']) && self::positiveId($detail['vendor']['id']) !== $vendorId)) {
                throw self::error('این سفارش در غرفه متصل تأیید نشد؛ شناسه سفارش را بررسی کنید.');
            }
            $row = $detail;
            $row['id'] = $detail['parcel_detail']['parcel_id'];
            $row['order'] = ['id' => $exactOrderId, 'customer' => $detail['customer_data'] ?? []];
            $row['vendor'] = ['id' => $vendorId];
            $rows = [$row];
            $nextCursor = null;
        } else {
            // The authenticated vendor-parcels contract has no recipient/product
            // title filter. Inspect at most two bounded pages, then let the user
            // explicitly continue via the returned cursor; never scan all orders.
            $seenCursors = [$cursor ?? ''];
            do {
                ConnectionCache::assertCurrentIdentity($identity);
                self::assertVendorUnchanged($vendorId);
                $response = BasalamParcelReader::orders($days, $cursor);
                ++$searchedPages;
                if (!is_array($response['data'] ?? null) || !Arrays::isList($response['data'])
                    || count($response['data']) > 100) {
                    throw self::error('پاسخ فهرست سفارش‌های باسلام معتبر نیست؛ دوباره تلاش کنید.');
                }
                $rows = array_values(array_filter($response['data'], static function ($row) use ($search, $vendorId): bool {
                    return is_array($row) && self::rowVendorId($row) === $vendorId
                        && ($search === '' || self::matchesSearch($row, $search));
                }));
                $nextCursor = $response['next_cursor'] ?? null;
                if ($nextCursor === '') { $nextCursor = null; }
                if ($nextCursor !== null && (!is_string($nextCursor) || strlen($nextCursor) > 2048
                    || preg_match('/[\x00-\x1f\x7f]/', $nextCursor) || in_array($nextCursor, $seenCursors, true))) {
                    throw self::error('اطلاعات صفحه بعد سفارش‌های باسلام معتبر نیست؛ فهرست را تازه‌سازی کنید.');
                }
                $cursor = $nextCursor;
                if ($nextCursor !== null) { $seenCursors[] = $nextCursor; }
            } while ($search !== '' && $rows === [] && $nextCursor !== null && $searchedPages < 2);
        }
        ConnectionCache::assertCurrentIdentity($identity);
        self::assertVendorUnchanged($vendorId);
        $readyOrders = [];
        $blockedOrders = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row) || self::rowVendorId($row) !== $vendorId) { continue; }
            $invoiceId = self::positiveId($row['order']['id'] ?? null);
            $parcelId = self::positiveId($row['id'] ?? null);
            if ($invoiceId === null || $parcelId === null || isset($seen[$invoiceId . ':' . $parcelId])) { continue; }
            $seen[$invoiceId . ':' . $parcelId] = true;
            $status = self::positiveId($row['status']['id'] ?? null);
            $blocked = self::statusReason($status);
            if (!empty($row['is_send_date']) || !empty($row['is_delivered']) || !empty($row['post_receipt'])) {
                $blocked = 'این سفارش قبلاً ارسال شده است؛ ثبت یا پرداخت مجدد انجام ندهید.';
            }
            if (array_key_exists('is_confirmed', $row) && $row['is_confirmed'] !== true) {
                $blocked = 'ابتدا سفارش را در باسلام تأیید کنید؛ سپس دوباره فهرست را باز کنید.';
            }
            $local = $this->mappedOrder($invoiceId, false);
            if ($local['blocked_reason'] !== null) { $blocked = $local['blocked_reason']; }
            $customer = is_array($row['order']['customer'] ?? null) ? $row['order']['customer'] : [];
            $name = self::text($customer['recipient']['name'] ?? null);
            $titles = self::productTitles($row);
            $shipping = self::shippingState($row, $local['order']);
            $order = [
                'local_order_id' => $local['order'] ? (int) $local['order']->get_id() : null,
                'order_url' => $local['order'] ? $local['order']->get_edit_order_url() : '',
                'shipping_title' => $shipping['label'], 'shipping_tone' => $shipping['tone'],
                'order_id' => $invoiceId, 'parcel_id' => $parcelId,
                'title' => $titles !== [] ? implode('، ', array_slice($titles, 0, 3)) : 'سفارش باسلام #' . $invoiceId,
                'products' => self::products($row, $local['order']),
                'status_title' => self::text($row['status']['title'] ?? null),
                'recipient_name' => $name,
                'items_count' => is_array($row['items'] ?? null) ? count($row['items']) : 0,
                'requires_import' => $local['order'] === null && $local['blocked_reason'] === null,
                'eligible' => $blocked === null, 'blocked_reason' => $blocked,
            ];
            // Keep the API order within each group and its opaque page cursor;
            // a list read never imports an order or changes its shipping state.
            if ($blocked === null) { $readyOrders[] = $order; }
            else { $blockedOrders[] = $order; }
        }
        return ['orders' => array_merge($readyOrders, $blockedOrders), 'next_cursor' => $nextCursor === '' ? null : $nextCursor,
            'searched_pages' => $searchedPages];
    }

    /** Explicitly bridge a genuine order to the already-guarded Woo shipping workflow. */
    public function selectOrder(int $invoiceId, int $selectedParcelId, bool $importConfirmed = false): array
    {
        if ($invoiceId < 1 || $selectedParcelId < 1) { throw self::error('شناسه سفارش یا مرسوله باسلام معتبر نیست.'); }
        $vendorId = self::connectedVendorId();
        if (($this->activeShopReader)() === null) {
            throw self::error('ابتدا سلام‌گیت غرفه را فعال کنید؛ سپس سفارش را برای ارسال انتخاب کنید.');
        }
        $identity = ConnectionCache::identityHash();
        $profile = ($this->profileReader)();
        $ownerId = self::positiveId($profile['user']['id'] ?? null);
        if ($ownerId === null || ($profile['registered'] ?? false) !== true) {
            throw self::error('حساب متصل سلام‌گیت تأیید نشد؛ اتصال غرفه را بررسی کنید.');
        }
        $detail = BasalamParcelReader::order($vendorId, $invoiceId);
        $parcel = BasalamParcelReader::parcel($selectedParcelId);
        $nativeScope = self::positiveId($detail['parcel_detail']['parcel_id'] ?? null) !== $selectedParcelId
            || !is_array($detail['items'] ?? null) || !is_array($parcel['items'] ?? null)
            || count($detail['items']) !== count($parcel['items']);
        foreach (is_array($parcel['items'] ?? null) ? $parcel['items'] : [] as $item) {
            foreach (is_array($detail['items'] ?? null) ? $detail['items'] : [] as $original) {
                if (($item['id'] ?? null) === ($original['id'] ?? null)
                    && ($item['quantity'] ?? null) != ($original['quantity'] ?? null)) { $nativeScope = true; }
            }
        }
        if (self::positiveId($parcel['vendor']['owner']['id'] ?? null) !== $ownerId) {
            throw self::error('این مرسوله متعلق به حساب باسلام متصل نیست؛ ارسال انجام نشد.');
        }
        // Shared authoritative financial/items/status/recipient checks, before any local import.
        BasalamShippingPreflight::fromSnapshots(null, $detail, $parcel, $vendorId, $invoiceId, $selectedParcelId, [], true, $nativeScope);
        ConnectionCache::assertCurrentIdentity($identity);
        self::assertVendorUnchanged($vendorId);
        $local = $this->mappedOrder($invoiceId, true);
        $order = $local['order'];
        $imported = false;
        if ($order === null) {
            if (!$importConfirmed) {
                return ['code' => 'import_confirmation_required', 'requires_import' => true,
                    'basalam_order_id' => $invoiceId, 'basalam_parcel_id' => $selectedParcelId,
                    'message' => 'این سفارش هنوز در ووسلام نیست. با ادامه، یک‌بار به سفارش‌های ووکامرس اضافه می‌شود و قوانین معمول موجودی و اعلان‌های سفارش اجرا می‌شوند. هنوز مرسوله یا پرداختی در باسلام ثبت نمی‌شود.'];
            }
            ConnectionCache::assertCurrentIdentity($identity);
            self::assertVendorUnchanged($vendorId);
            $result = ($this->importer)($invoiceId);
            if (!is_array($result) || ($result['success'] ?? false) !== true) {
                throw self::error('افزودن سفارش باسلام به ووسلام انجام نشد؛ از بخش سفارش‌های ووسلام، دریافت سفارش‌ها را بررسی کنید.');
            }
            $localId = self::positiveId($result['order_id'] ?? null);
            if ($localId === null) {
                return ['code' => 'import_in_progress', 'message' => 'دریافت این سفارش در حال انجام است؛ کمی بعد دوباره همین سفارش را انتخاب کنید.'];
            }
            $local = $this->mappedOrder($invoiceId, true);
            $order = $local['order'];
            if (!$order instanceof \WC_Order || (int) $order->get_id() !== $localId) {
                throw self::error('ارتباط سفارش دریافت‌شده با باسلام تأیید نشد؛ ثبت یا پرداخت انجام ندهید و سفارش‌های ووسلام را بررسی کنید.');
            }
            $imported = true;
        }
        ConnectionCache::assertCurrentIdentity($identity);
        self::assertVendorUnchanged($vendorId);
        // A stale local item set or an old parcel association cannot bypass the existing order guards.
        // Native selections always carry an explicit parcel identity. Existing
        // one-parcel orders retain their legacy repair path until first scoped send.
        require_once __DIR__ . '/../Order/BasalamParcelOrder.php';
        if ($nativeScope || BasalamParcelOrder::hasScopes($order)) {
            $scope = BasalamParcelOrder::open($order, $selectedParcelId);
            BasalamShippingPreflight::check($scope);
            return ['order_id' => (int) $order->get_id(), 'basalam_order_id' => $invoiceId,
                'basalam_parcel_id' => $selectedParcelId, 'order_url' => $order->get_edit_order_url(), 'imported' => $imported];
        }
        BasalamShippingPreflight::fromSnapshots($order, $detail, $parcel, $vendorId, $invoiceId, $selectedParcelId);
        $known = BasalamOrderContext::getParcelId((int) $order->get_id());
        $savedExternal = $order->get_meta('_salam_gate_external_id');
        $existingParcel = self::positiveId($order->get_meta('_salam_gate_parcel_id'));
        if (($known !== null && $known !== $selectedParcelId)
            || ($existingParcel !== null && $savedExternal !== '' && self::positiveId($savedExternal) !== $selectedParcelId)) {
            throw self::error('مرسوله قبلی این سفارش با مرسوله اصلی باسلام مطابقت ندارد؛ ثبت یا پرداخت مجدد انجام ندهید.');
        }
        BasalamOrderProducts::repairFromSnapshots($order, $detail, $parcel, $vendorId, $invoiceId, $selectedParcelId);
        $orderId = (int) $order->get_id();
        return ['order_id' => $orderId, 'basalam_order_id' => $invoiceId,
            'basalam_parcel_id' => $selectedParcelId, 'order_url' => $order->get_edit_order_url(), 'imported' => $imported];
    }

    private function mappedOrder(int $invoiceId, bool $strict): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'sync_basalam_payments';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Known base-plugin table; no browser-controlled identifier.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT order_id FROM {$table} WHERE invoice_id = %d LIMIT 2", $invoiceId), ARRAY_A);
        $reason = null;
        $order = null;
        if (!is_array($rows)) {
            $reason = 'ارتباط سفارش‌های ووسلام در دسترس نیست؛ ابتدا افزونه پایه را بررسی کنید.';
        } elseif (count($rows) > 1) {
            $reason = 'برای این سفارش چند ارتباط محلی وجود دارد؛ ابتدا ارتباط سفارش را بررسی کنید.';
        } elseif (count($rows) === 1) {
            $id = self::positiveId($rows[0]['order_id'] ?? null);
            $order = $id !== null && function_exists('wc_get_order') ? wc_get_order($id) : null;
            if (!$order instanceof \WC_Order || BasalamOrderContext::getInvoiceId($id) !== $invoiceId
                || !BasalamOrderContext::isBasalamOrder($id)) {
                $order = null;
                $reason = 'سفارش متناظر در ووسلام حذف شده یا ارتباطش نامعتبر است؛ ابتدا دریافت سفارش‌ها را بررسی کنید.';
            } else {
                $reason = BasalamShippingPreflight::localStatusBlockReason($order);
            }
        }
        if ($reason !== null && $strict) { throw self::error($reason); }
        return ['order' => $order, 'blocked_reason' => $reason];
    }

    /** Per native parcel, using only this list snapshot and verified local metadata. */
    private static function shippingState(array $row, ?\WC_Order $order): array
    {
        if (!empty($row['is_delivered'])) { return ['label' => 'تحویل شده', 'tone' => 'delivered']; }
        if (!empty($row['is_send_date']) || in_array(self::positiveId($row['status']['id'] ?? null), [3238, 3195], true)) {
            return ['label' => 'ارسال شده', 'tone' => 'sent'];
        }
        if (in_array(self::positiveId($row['status']['id'] ?? null), [3067, 3233], true)) {
            return ['label' => 'ارسال لغو شده', 'tone' => 'cancelled'];
        }
        $nativeId = self::positiveId($row['id'] ?? null);
        $scope = $order && $nativeId ? BasalamParcelOrder::stored($order, $nativeId) : null;
        if ($scope) {
            $parcelId = self::positiveId($scope->get_meta('_salam_gate_parcel_id'));
            $status = self::positiveId($scope->get_meta('_salam_gate_status'));
            // A sibling's receipt cannot supply this row's shipment status.
            $linked = $parcelId !== null
                && self::positiveId($order->get_meta('_salam_gate_native_link_' . $parcelId)) === $nativeId
                && self::positiveId($order->get_meta('_salam_gate_native_origin_' . $nativeId)) === $parcelId;
            if ($linked && in_array($status, [ParcelStatuses::SHIPPED, ParcelStatuses::DELIVERED,
                ParcelStatuses::HANDED_TO_POSTMAN, ParcelStatuses::RETURNED_TO_SENDER], true)) {
                return ['label' => 'ارسال شده', 'tone' => 'sent'];
            }
            if ($linked && $status === ParcelStatuses::NEW && !$scope->get_meta('_salam_gate_pay_unknown')) {
                return ['label' => 'در انتظار پرداخت', 'tone' => 'waiting'];
            }
        }
        // A receipt/barcode alone never proves handover to the post office.
        return ['label' => 'ارسال نشده', 'tone' => 'pending'];
    }

    private static function rowVendorId(array $row): ?int
    {
        return self::positiveId($row['vendor']['id'] ?? null)
            ?? self::positiveId($row['order']['vendor']['id'] ?? null);
    }

    private static function connectedVendorId(): int
    {
        $id = function_exists('syncBasalamSettings') ? self::positiveId(syncBasalamSettings()->getSettings('vendor_id')) : null;
        if ($id === null) { throw self::error('ابتدا غرفه باسلام را به ووسلام متصل کنید.'); }
        return $id;
    }

    private static function assertVendorUnchanged(int $vendorId): void
    {
        if (self::connectedVendorId() !== $vendorId) { throw self::error('غرفه متصل هنگام دریافت سفارش تغییر کرد؛ دوباره فهرست را باز کنید.'); }
    }

    private static function statusReason(?int $status): ?string
    {
        if ($status === 3237) { return null; }
        if ($status === 3739) { return 'ابتدا سفارش را در باسلام تأیید کنید؛ سپس دوباره فهرست را باز کنید.'; }
        if (in_array($status, [3067, 3233], true)) { return 'این سفارش لغو یا رد شده و قابل ارسال نیست.'; }
        if (in_array($status, [3238, 3195], true)) { return 'این سفارش قبلاً ارسال شده است؛ ثبت یا پرداخت مجدد انجام ندهید.'; }
        return 'وضعیت سفارش قابل ارسال تأیید نشده است؛ آن را در پنل باسلام بررسی کنید.';
    }

    public static function positiveId($value): ?int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value))) { return null; }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }

    private static function text($value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private static function productTitles(array $row): array
    {
        $titles = [];
        foreach (is_array($row['items'] ?? null) ? $row['items'] : [] as $item) {
            if (!is_array($item)) { continue; }
            $title = self::productTitle($item);
            if ($title !== '') { $titles[] = $title; }
        }
        return array_values(array_unique($titles));
    }

    /** Only original product identity/presentation from the authenticated order. */
    private static function products(array $row, ?\WC_Order $localOrder = null): array
    {
        $products = [];
        $editLinks = [];
        if ($localOrder !== null) {
            foreach ($localOrder->get_items() as $line) {
                $remoteItemId = self::positiveId($localOrder->get_meta('_sync_basalam_item_id_' . $line->get_id()));
                $product = $line->get_product();
                if ($remoteItemId === null || !$product) { continue; }
                $editId = $product->is_type('variation') ? (int) $product->get_parent_id() : (int) $product->get_id();
                $url = $editId > 0 && current_user_can('edit_post', $editId) ? get_edit_post_link($editId, 'raw') : null;
                if (is_string($url)) { $editLinks[$remoteItemId] = esc_url_raw($url); }
            }
        }
        foreach (is_array($row['items'] ?? null) ? $row['items'] : [] as $item) {
            if (!is_array($item)) { continue; }
            $product = is_array($item['product'] ?? null) ? $item['product'] : [];
            $products[] = [
                'product_id' => self::positiveId($product['id'] ?? null),
                'title' => self::productTitle($item),
                'image_url' => self::productImage($product),
                // A Basalam catalog ID is never treated as a WordPress post ID.
                'edit_url' => $editLinks[self::positiveId($item['id'] ?? null) ?? 0] ?? '',
            ];
        }
        return $products;
    }

    private static function productTitle(array $item): string
    {
        $product = is_array($item['product'] ?? null) ? $item['product'] : [];
        foreach ([$item['title'] ?? null, $product['title'] ?? null, $product['name'] ?? null] as $candidate) {
            $title = self::text($candidate);
            if ($title !== '') { return $title; }
        }
        return '';
    }

    private static function productImage(array $product): string
    {
        // vendor-parcels and original order details provide photos[].resized;
        // older product snapshots use a single photo with size keys.
        $photos = is_array($product['photos'] ?? null) ? $product['photos'] : [];
        if (isset($product['photo'])) { $photos[] = $product['photo']; }
        foreach ($photos as $photo) {
            $candidates = is_array($photo)
                ? [$photo['resized']['sm'] ?? null, $photo['resized']['md'] ?? null,
                    $photo['sm'] ?? null, $photo['md'] ?? null, $photo['original'] ?? null]
                : [$photo];
            foreach ($candidates as $candidate) {
                $url = self::imageUrl($candidate);
                if ($url !== '') { return $url; }
            }
        }
        return '';
    }

    private static function imageUrl($value): string
    {
        $url = self::text($value);
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f<>"\'`\\\\]/', $url)
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return '';
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }
        return $url;
    }

    private static function matchesSearch(array $row, string $search): bool
    {
        // The recipient is distinct from the person who bought the order.
        $recipient = self::text($row['order']['customer']['recipient']['name'] ?? null);
        foreach (array_merge([$recipient], self::productTitles($row)) as $value) {
            if (str_contains(self::searchText($value), $search)) { return true; }
        }
        return false;
    }

    private static function searchText(string $value): string
    {
        $value = Digits::toEnglish($value);
        $value = strtr($value, ['ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک']);
        $value = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $value) ?? '';
        $value = preg_replace('/[\s\x{200C}]+/u', ' ', $value) ?? '';
        $value = trim($value);
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private static function error(string $message): SalamGateException
    {
        return new SalamGateException($message, 422, [], SalamGateException::KIND_VALIDATION);
    }
}
