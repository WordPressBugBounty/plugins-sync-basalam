<?php

namespace SyncBasalam\SalamGate\Order;

use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\ConnectionCache;

defined('ABSPATH') || exit;

require_once __DIR__ . '/BasalamOrderContext.php';
require_once __DIR__ . '/BasalamOrderState.php';

/** Authenticated native parcel discovery; never imports, allocates, creates or pays. */
final class BasalamParcelSet
{
    private const MAX_PAGE_ROWS = 30;

    /** The parent and parcel are unchanged API snapshots; allocations only describe native membership. */
    public static function load(\WC_Order $rootOrder, int $nativeId): array
    {
        $context = self::context($rootOrder);
        if ($nativeId < 1) { throw self::error('شناسه مرسوله باسلام معتبر نیست.'); }
        $parent = BasalamParcelReader::order($context['vendor_id'], $context['invoice_id']);
        self::fence($rootOrder, $context);
        BasalamOrderState::remember($rootOrder, $parent, $context['vendor_id'], $context['invoice_id']);
        $items = self::parentItems($rootOrder, $parent, $context);
        $parcel = BasalamParcelReader::parcel($nativeId);
        self::fence($rootOrder, $context);
        $allocations = self::validateParcel($parent, $items, $parcel, $nativeId, $context);
        self::fence($rootOrder, $context);
        if (self::parentItems($rootOrder, $parent, $context) !== $items) {
            throw self::error('اقلام سفارش هنگام دریافت مرسوله تغییر کرد؛ دوباره بررسی کنید.');
        }
        return ['order' => $parent, 'parcel' => $parcel, 'allocations' => $allocations,
            'invoice_id' => $context['invoice_id'], 'vendor_id' => $context['vendor_id'], 'parcel_id' => $nativeId];
    }

    /** One bounded page; exhaustion refers to the 90-day list window, not all historical parcels. */
    public static function discover(\WC_Order $rootOrder, ?string $cursor = null): array
    {
        self::cursor($cursor);
        $context = self::context($rootOrder);
        $parent = BasalamParcelReader::order($context['vendor_id'], $context['invoice_id']);
        self::fence($rootOrder, $context);
        $sourceState = BasalamOrderState::remember($rootOrder, $parent, $context['vendor_id'], $context['invoice_id']);
        $items = self::parentItems($rootOrder, $parent, $context);
        $primary = self::id($parent['parcel_detail']['parcel_id'] ?? null);
        if ($primary === null) { throw self::error('شناسه مرسوله اصلی سفارش باسلام مشخص نیست.'); }
        $response = BasalamParcelReader::orders(90, $cursor);
        self::fence($rootOrder, $context);
        if (!is_array($response['data'] ?? null) || !array_is_list($response['data'])
            || count($response['data']) > self::MAX_PAGE_ROWS) {
            throw self::error('پاسخ فهرست مرسوله‌های باسلام معتبر نیست.');
        }
        $next = $response['next_cursor'] ?? null;
        if ($next === '') { $next = null; }
        self::cursor($next);
        if ($next !== null && $next === $cursor) { throw self::error('صفحه بعد مرسوله‌های باسلام معتبر نیست.'); }
        $firstPage = $cursor === null || $cursor === '';
        $known = $firstPage ? self::knownNativeIds($rootOrder, $context) : [];
        $candidates = $firstPage ? [$primary => null] : [];
        foreach ($known as $id => $allocation) { $candidates[$id] = null; }
        $seen = [];
        foreach ($response['data'] as $row) {
            if (!is_array($row)) { throw self::error('اطلاعات یکی از مرسوله‌های باسلام معتبر نیست.'); }
            $id = self::id($row['id'] ?? null);
            $invoice = self::id($row['order']['id'] ?? null);
            $vendor = self::id($row['vendor']['id'] ?? $row['order']['vendor']['id'] ?? null);
            if ($id === $primary && ($invoice !== $context['invoice_id'] || $vendor !== $context['vendor_id'])) {
                throw self::error('مرسوله اصلی در فهرست باسلام با سفارش یا غرفه مطابقت ندارد.');
            }
            if ($invoice !== $context['invoice_id'] || $vendor !== $context['vendor_id']) { continue; }
            if ($id === null || isset($seen[$id])) { throw self::error('شناسه تکراری یا نامعتبر مرسوله باسلام دریافت شد.'); }
            if (isset($row['vendor']['id'], $row['order']['vendor']['id'])
                && self::id($row['order']['vendor']['id']) !== $vendor) {
                throw self::error('اطلاعات غرفه مرسوله باسلام متناقض است.');
            }
            $seen[$id] = true;
            $candidates[$id] = $row;
        }
        if (count($candidates) > self::MAX_PAGE_ROWS) { throw self::error('تعداد مرسوله‌های این صفحه بیشتر از حد قابل بررسی است.'); }
        $snapshots = [];
        $rows = [];
        foreach ($candidates as $id => $listed) {
            self::fence($rootOrder, $context);
            $parcel = BasalamParcelReader::parcel($id);
            self::fence($rootOrder, $context);
            $allocations = self::validateParcel($parent, $items, $parcel, $id, $context);
            if (isset($known[$id]) && $known[$id] !== [] && $known[$id] !== $allocations) {
                throw self::error('اقلام مرسوله ذخیره‌شده با اطلاعات فعلی باسلام مطابقت ندارد؛ ابتدا ارتباط قبلی را بررسی کنید.');
            }
            if ($listed !== null && array_key_exists('items', $listed)) {
                self::assertListedItems($listed['items'], $parcel['items']);
            }
            $snapshots[] = $parcel;
            $titles = [];
            foreach ($parcel['items'] as $item) {
                $title = self::text($item['title'] ?? null)
                    ?: self::text($item['product']['title'] ?? $item['product']['name'] ?? null);
                if ($title !== '') { $titles[] = $title; }
            }
            $reason = self::blockedReason($rootOrder, $parent, $parcel);
            $rows[] = ['basalam_parcel_id' => $id, 'titles' => array_values(array_unique($titles)),
                'items_count' => count($allocations), 'quantity' => array_sum(array_column($allocations, 'quantity')),
                'status_title' => self::text($parcel['status']['title'] ?? null),
                'eligible' => $reason === null, 'blocked_reason' => $reason];
        }
        self::assertAllocations($parent, $snapshots);
        self::fence($rootOrder, $context);
        if (self::parentItems($rootOrder, $parent, $context) !== $items) {
            throw self::error('اقلام سفارش هنگام دریافت مرسوله‌ها تغییر کرد؛ دوباره بررسی کنید.');
        }
        return ['parcels' => $rows, 'next_cursor' => $next, 'window_days' => 90, 'list_exhausted' => $next === null,
            'source_state' => $sourceState];
    }

    /** Exact current parent/vendor identities only; no adapter constructor, migration or metadata write. */
    private static function knownNativeIds(\WC_Order $order, array $context): array
    {
        if (!method_exists($order, 'get_meta_data')) { return []; }
        $known = [];
        foreach ($order->get_meta_data() as $meta) {
            if (!is_object($meta) || !method_exists($meta, 'get_data')) { continue; }
            $data = $meta->get_data();
            if (!is_array($data) || !preg_match('/^_salam_gate_bp_([1-9][0-9]*)_identity$/D', (string) ($data['key'] ?? ''), $match)) {
                continue;
            }
            $id = self::id($match[1]);
            if ($id === null) { continue; }
            $identity = $data['value'] ?? $order->get_meta($data['key']);
            if ($identity !== ['order_id' => (int) $order->get_id(), 'invoice_id' => $context['invoice_id'],
                'vendor_id' => $context['vendor_id'], 'parcel_id' => $id]) { continue; }
            if (array_key_exists($id, $known)) { throw self::error('ارتباط مرسوله باسلام در سفارش تکراری است.'); }
            $allocation = $order->get_meta('_salam_gate_bp_' . $id . '_allocations');
            if ($allocation === '' || $allocation === null) { $allocation = []; }
            if (!is_array($allocation)) { throw self::error('اقلام ذخیره‌شده مرسوله باسلام معتبر نیست.'); }
            $known[$id] = $allocation;
            if (count($known) > self::MAX_PAGE_ROWS) { throw self::error('تعداد مرسوله‌های ذخیره‌شده بیشتر از حد قابل بررسی است.'); }
        }
        ksort($known);
        return $known;
    }

    /** Verify known native children together without inventing allocation or requiring full historical coverage. */
    public static function assertAllocations(array $rawParent, array $childSnapshots): void
    {
        $parent = self::index($rawParent['items'] ?? null);
        $totals = [];
        $seen = [];
        foreach ($childSnapshots as $child) {
            $id = is_array($child) ? self::id($child['id'] ?? null) : null;
            if ($id === null || isset($seen[$id])) { throw self::error('مرسوله تکراری یا نامعتبر در ارتباط سفارش وجود دارد.'); }
            $seen[$id] = true;
            foreach (self::index($child['items'] ?? null) as $external => $item) {
                $limit = isset($parent[$external]) ? self::quantity($parent[$external]['quantity'] ?? null) : null;
                $quantity = self::quantity($item['quantity'] ?? null);
                $used = $totals[$external] ?? 0;
                if ($limit === null || $quantity === null || $quantity > $limit - $used) {
                    throw self::error('جمع تعداد یک قلم در مرسوله‌های باسلام بیشتر از تعداد سفارش است.');
                }
                $totals[$external] = $used + $quantity;
            }
        }
    }

    private static function context(\WC_Order $order): array
    {
        $identity = ConnectionCache::identityHash();
        $invoice = BasalamOrderContext::getInvoiceId((int) $order->get_id());
        $vendor = function_exists('syncBasalamSettings') ? self::id(syncBasalamSettings()->getSettings('vendor_id')) : null;
        if ((int) $order->get_id() < 1 || $invoice === null || $vendor === null) {
            throw self::error('ارتباط سفارش با غرفه باسلام مشخص نیست.');
        }
        $context = ['invoice_id' => $invoice, 'vendor_id' => $vendor, 'connection_hash' => $identity];
        self::fence($order, $context);
        return $context;
    }

    private static function fence(\WC_Order $order, array $context): void
    {
        ConnectionCache::assertCurrentIdentity($context['connection_hash']);
        $vendor = function_exists('syncBasalamSettings') ? self::id(syncBasalamSettings()->getSettings('vendor_id')) : null;
        if ($vendor !== $context['vendor_id'] || BasalamOrderContext::getInvoiceId((int) $order->get_id()) !== $context['invoice_id']) {
            throw self::error('ارتباط سفارش یا غرفه هنگام دریافت مرسوله‌ها تغییر کرد؛ دوباره بررسی کنید.');
        }
    }

    private static function parentItems(\WC_Order $order, array $parent, array $context): array
    {
        if (self::id($parent['id'] ?? null) !== $context['invoice_id']
            || (isset($parent['vendor']['id']) && self::id($parent['vendor']['id']) !== $context['vendor_id'])) {
            throw self::error('سفارش اصلی با غرفه و سفارش ووکامرس مطابقت ندارد.');
        }
        $parentItems = self::index($parent['items'] ?? null);
        $local = [];
        foreach ($order->get_items() as $item) {
            $external = self::id($order->get_meta('_sync_basalam_item_id_' . $item->get_id()));
            $quantity = self::quantity($item->get_quantity());
            $localId = self::id($item->get_id());
            if ($external === null || $quantity === null || $localId === null || isset($local[$external])) {
                throw self::error('ارتباط یا تعداد اقلام ووکامرس با باسلام معتبر نیست.');
            }
            $local[$external] = ['item_id' => $localId, 'quantity' => $quantity];
        }
        if (count($local) !== count($parentItems) || array_diff_key($parentItems, $local) !== []) {
            throw self::error('همه اقلام سفارش باسلام در سفارش ووکامرس موجود نیستند؛ ابتدا سفارش را همگام‌سازی کنید.');
        }
        foreach ($parentItems as $id => $item) {
            $quantity = self::quantity($item['quantity'] ?? null);
            if ($quantity === null || $quantity !== $local[$id]['quantity']) {
                throw self::error('تعداد اقلام سفارش ووکامرس با سفارش اصلی باسلام مطابقت ندارد.');
            }
            self::unitPrice($item, $quantity);
        }
        return $local;
    }

    private static function validateParcel(array $parent, array $local, array $parcel, int $id, array $context): array
    {
        $primary = self::id($parent['parcel_detail']['parcel_id'] ?? null);
        $hasOrder = is_array($parcel['order'] ?? null) && array_key_exists('id', $parcel['order']);
        if (self::id($parcel['id'] ?? null) !== $id || self::id($parcel['vendor']['id'] ?? null) !== $context['vendor_id']
            || (($hasOrder || $id !== $primary) && self::id($parcel['order']['id'] ?? null) !== $context['invoice_id'])
            || (array_key_exists('order', $parcel) && !is_array($parcel['order']))) {
            throw self::error('مرسوله باسلام با سفارش اصلی یا غرفه متصل مطابقت ندارد.');
        }
        $original = self::index($parent['items'] ?? null);
        $allocations = [];
        foreach (self::index($parcel['items'] ?? null) as $external => $item) {
            $quantity = self::quantity($item['quantity'] ?? null);
            if (!isset($original[$external], $local[$external]) || $quantity === null || $quantity > $local[$external]['quantity']) {
                throw self::error('قلم یا تعداد مرسوله باسلام با سفارش اصلی مطابقت ندارد.');
            }
            $source = $original[$external];
            $product = self::id($source['product']['id'] ?? null);
            if ($product === null || self::id($item['product']['id'] ?? null) !== $product
                || self::variation($item['variation'] ?? null) !== self::variation($source['variation'] ?? null)
                || self::id($item['price'] ?? null) !== self::unitPrice($source, $local[$external]['quantity'])
                || self::weight($item['net_weight'] ?? null) !== self::weight($source['net_weight'] ?? null)
                || self::weight($item['weight'] ?? null) !== self::weight($source['weight'] ?? null)) {
                throw self::error('محصول، تنوع، قیمت یا وزن مرسوله با قلم اصلی باسلام مطابقت ندارد.');
            }
            $allocations[$external] = ['item_id' => $local[$external]['item_id'], 'quantity' => $quantity];
        }
        return $allocations;
    }

    private static function assertListedItems($listed, array $fresh): void
    {
        $listed = self::index($listed);
        $fresh = self::index($fresh);
        if (count($listed) !== count($fresh) || array_diff_key($listed, $fresh) !== []) {
            throw self::error('اقلام مرسوله هنگام دریافت فهرست تغییر کرد؛ دوباره بررسی کنید.');
        }
        foreach ($listed as $id => $item) {
            if (self::quantity($item['quantity'] ?? null) !== self::quantity($fresh[$id]['quantity'] ?? null)) {
                throw self::error('تعداد اقلام مرسوله هنگام دریافت فهرست تغییر کرد؛ دوباره بررسی کنید.');
            }
        }
    }

    private static function blockedReason(\WC_Order $order, array $parent, array $parcel): ?string
    {
        if (method_exists($order, 'get_status') && in_array($order->get_status(),
            ['trash', 'refunded', 'cancelled', 'failed', 'bslm-rejected'], true)) {
            return 'سفارش ووکامرس لغو، مسترد یا ناموفق است؛ ابتدا سفارش را بررسی کنید.';
        }
        return BasalamOrderState::blockedReason($parent, $parcel, true);
    }

    private static function index($items): array
    {
        if (!is_array($items) || !array_is_list($items) || $items === []) { throw self::error('اقلام باسلام دریافت نشد یا معتبر نیست.'); }
        $indexed = [];
        foreach ($items as $item) {
            $id = is_array($item) ? self::id($item['id'] ?? null) : null;
            if ($id === null || isset($indexed[$id])) { throw self::error('شناسه اقلام باسلام تکراری یا نامعتبر است.'); }
            $indexed[$id] = $item;
        }
        return $indexed;
    }

    private static function unitPrice(array $item, int $quantity): int
    {
        $price = self::id($item['price'] ?? null);
        if ($price === null || $price > intdiv(PHP_INT_MAX, $quantity)) { throw self::error('قیمت اصلی قلم باسلام معتبر نیست.'); }
        $verified = false;
        $reports = $item['financial_report']['report_items'] ?? [];
        if (!is_array($reports)) { throw self::error('گزارش قیمت اصلی باسلام معتبر نیست.'); }
        foreach ($reports as $report) {
            if (!is_array($report)) { throw self::error('گزارش قیمت اصلی باسلام معتبر نیست.'); }
            if (self::text($report['title'] ?? null) !== 'قیمت محصول') { continue; }
            $total = self::id($report['amount'] ?? null);
            if ($verified || $total === null || $total % $quantity !== 0 || intdiv($total, $quantity) !== $price) {
                throw self::error('قیمت قلم باسلام با گزارش اصلی سفارش مطابقت ندارد.');
            }
            $verified = true;
        }
        if ($quantity > 1 && !$verified) { throw self::error('قیمت قلم چندتعدادی باسلام قابل تأیید نیست.'); }
        return $price;
    }

    private static function variation($value): ?int
    {
        if ($value === null) { return null; }
        $id = is_array($value) ? self::id($value['id'] ?? null) : null;
        if ($id === null) { throw self::error('تنوع قلم باسلام معتبر نیست.'); }
        return $id;
    }

    private static function weight($value): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') { return null; }
        $weight = self::id($value);
        if ($weight === null) { throw self::error('وزن قلم باسلام معتبر نیست.'); }
        return $weight;
    }

    private static function quantity($value): ?int
    {
        if (is_float($value) && is_finite($value) && floor($value) === $value && $value > 0 && $value < PHP_INT_MAX) {
            $value = (int) $value;
        }
        return self::id($value);
    }

    private static function id($value): ?int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value))) { return null; }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }

    private static function cursor($cursor): void
    {
        if ($cursor !== null && (!is_string($cursor) || strlen($cursor) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $cursor))) {
            throw self::error('اطلاعات صفحه مرسوله‌های باسلام معتبر نیست.');
        }
    }

    private static function text($value): string { return is_string($value) ? trim($value) : ''; }

    private static function error(string $message): SalamGateException
    {
        return new SalamGateException($message, 422, [], SalamGateException::KIND_VALIDATION);
    }
}
