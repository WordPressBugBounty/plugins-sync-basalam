<?php

namespace SyncBasalam\SalamGate\Services;

use SyncBasalam\SalamGate\Admin\OrderMetabox;
use SyncBasalam\SalamGate\Admin\OrderShippingStatus;
use SyncBasalam\SalamGate\Contracts\ParcelStatuses;
use SyncBasalam\SalamGate\Order\OrderContext;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Utils\Digits;

defined('ABSPATH') || exit;

/** Local selection only: list/select never imports, creates, changes an order or charges. */
final class WebsiteOrdersService
{
    private const PAGE_SIZE = 20;
    private $query;
    private $orderReader;
    private $marketReader;

    /** Internal dependencies, never populated from request input. */
    public function __construct(?callable $query = null, ?callable $orderReader = null, ?callable $marketReader = null)
    {
        $this->query = $query ?? [self::class, 'queryOrderIds'];
        $this->orderReader = $orderReader ?? static fn (int $id) => function_exists('wc_get_order') ? wc_get_order($id) : null;
        $this->marketReader = $marketReader ?? static fn (int $id): string => OrderContext::marketFor($id);
    }

    /** Keyset pages remain stable when newer orders arrive; both datastores use IDs-only reads. */
    public function listOrders(?string $cursor = null, string $search = ''): array
    {
        $search = trim(Digits::toEnglish($search));
        if ($search !== '' && (!preg_match('/^.{1,120}$/usD', $search) || preg_match('/[\x00-\x1f\x7f]/', $search))) {
            throw self::error('شماره سفارش، نام گیرنده یا محصول را حداکثر در ۱۲۰ نویسه وارد کنید.');
        }
        $exactId = null;
        $number = ltrim($search, '#');
        if ($number !== '' && preg_match('/^[0-9]+$/D', $number)) {
            $exactId = self::positiveId($number);
            if ($exactId === null) { throw self::error('شماره معتبر سفارش ووکامرس را وارد کنید.'); }
        }
        $beforeId = null;
        $searchKey = substr(hash('sha256', $search), 0, 16);
        if ($cursor !== null && $cursor !== '') {
            if (strlen($cursor) > 80 || !preg_match('/^website:([1-9][0-9]*):([a-f0-9]{16})$/D', $cursor, $match)
                || ($beforeId = self::positiveId($match[1])) === null || $match[2] !== $searchKey || $exactId !== null) {
                throw self::error('صفحه بعد سفارش‌ها معتبر نیست؛ فهرست را دوباره باز کنید.');
            }
        }
        $ids = ($this->query)($beforeId, $exactId, $search, self::PAGE_SIZE + 1);
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > self::PAGE_SIZE + 1) {
            throw self::error('دریافت سفارش‌های ووکامرس انجام نشد؛ دوباره تلاش کنید.');
        }
        $seen = [];
        $previous = $beforeId ?? PHP_INT_MAX;
        foreach ($ids as &$id) {
            $id = self::positiveId($id);
            if ($id === null || isset($seen[$id]) || $id >= $previous || ($exactId !== null && $id !== $exactId)) {
                throw self::error('پاسخ فهرست سفارش‌های ووکامرس معتبر نیست؛ فهرست را تازه‌سازی کنید.');
            }
            $seen[$id] = true;
            $previous = $id;
        }
        unset($id);
        $hasMore = count($ids) > self::PAGE_SIZE;
        $ids = array_slice($ids, 0, self::PAGE_SIZE);
        $ready = [];
        $blocked = [];
        foreach ($ids as $id) {
            $order = ($this->orderReader)($id);
            if (!$this->isWebsiteOrder($order) || (int) $order->get_id() !== $id) { continue; }
            $reason = self::blockReason($order);
            $products = self::products($order);
            $titles = array_values(array_filter(array_column($products, 'title')));
            $shipping = OrderShippingStatus::forOrder($order);
            $action = \SyncBasalam\SalamGate\Admin\OrderListAction::forOrder($order);
            if ($action['awaiting_payment'] && !in_array($shipping['tone'], ['sent', 'delivered', 'returned'], true)) {
                $shipping = ['label' => 'در انتظار پرداخت', 'tone' => 'waiting'];
            }
            $row = [
                'order_id' => $id, 'order_number' => self::text($order->get_order_number()),
                'local_order_id' => $id, 'order_url' => $order->get_edit_order_url(),
                'title' => $titles !== [] ? implode('، ', array_slice($titles, 0, 3)) : 'سفارش ووکامرس #' . $id,
                'products' => $products,
                'status_title' => self::statusTitle($order->get_status()),
                'recipient_name' => self::recipientName($order),
                'items_count' => count($order->get_items()),
                'eligible' => $reason === null, 'blocked_reason' => $reason,
                'shipping_title' => $shipping['label'], 'shipping_tone' => $shipping['tone'],
            ];
            if ($reason === null) { $ready[] = $row; } else { $blocked[] = $row; }
        }
        return ['orders' => array_merge($ready, $blocked),
            'next_cursor' => $hasMore && $ids !== [] ? 'website:' . end($ids) . ':' . $searchKey : null];
    }

    /** Reload authoritative source/status; the existing order modal performs shipping preflight. */
    public function selectOrder(int $orderId): array
    {
        if ($orderId < 1) { throw self::error('سفارش ووکامرس را دوباره از فهرست انتخاب کنید.'); }
        $order = ($this->orderReader)($orderId);
        if (!$this->isWebsiteOrder($order) || (int) $order->get_id() !== $orderId) {
            throw self::error('این سفارش ووکامرس در دسترس نیست؛ سفارش‌های باسلام را از مبدأ باسلام انتخاب کنید.');
        }
        $reason = self::blockReason($order);
        if ($reason !== null) { throw self::error($reason); }
        return ['order_id' => $orderId, 'order_url' => $order->get_edit_order_url(), 'market' => OrderContext::MARKET_WEBSITE];
    }

    private function isWebsiteOrder($order): bool
    {
        if (!$order instanceof \WC_Order || $order->get_type() !== 'shop_order'
            || in_array($order->get_status(), ['trash', 'checkout-draft'], true)
            || ($this->marketReader)((int) $order->get_id()) !== OrderContext::MARKET_WEBSITE
            || !in_array($order->get_meta('_salam_gate_market'), ['', 'website'], true)
            || $order->get_meta('_salam_gate_basalam_mapping') !== '') { return false; }
        foreach ($order->get_meta_data() as $meta) {
            $key = (string) ($meta->get_data()['key'] ?? '');
            if (preg_match('/^_salam_gate_(bp_|native_)/', $key)) { return false; }
        }
        return true;
    }

    private static function blockReason(\WC_Order $order): ?string
    {
        if (!OrderMetabox::isActionableStatus(OrderContext::MARKET_WEBSITE, $order->get_status())) {
            return 'این سفارش در وضعیت قابل ارسال نیست؛ ابتدا وضعیت سفارش ووکامرس را بررسی کنید.';
        }
        $parcelId = self::positiveId($order->get_meta('_salam_gate_parcel_id'));
        $status = self::positiveId($order->get_meta('_salam_gate_status')) ?? 0;
        if ($parcelId !== null && ParcelStatuses::hasReceipt($status)) {
            return 'هزینه ارسال این سفارش قبلاً پرداخت شده است؛ وضعیت و برچسب آن را در صفحه سفارش بررسی کنید.';
        }
        if ($parcelId !== null && ParcelStatuses::isVoided($status)) {
            return 'مرسوله این سفارش ابطال شده است؛ پیش از ارسال مجدد، وضعیت آن را در صفحه سفارش بررسی کنید.';
        }
        $externalId = self::positiveId($order->get_meta('_salam_gate_external_id'))
            ?? self::positiveId(get_option('salam_gate_website_order_identity_' . $order->get_id(), null));
        $paymentKeys = ['salam_gate_website_payment_order_' . $order->get_id()];
        if ($externalId !== null) { $paymentKeys[] = 'salam_gate_website_payment_external_' . $externalId; }
        if ($parcelId !== null) { $paymentKeys[] = ParcelService::paymentOutcomeKey($parcelId); }
        $evidence = self::hasMetaValue($order->get_meta('_salam_gate_pay_unknown')) || self::hasMetaValue($order->get_meta('_salam_gate_paid_at'))
            || self::positiveId($order->get_meta('_salam_gate_invoice_id')) !== null;
        foreach ($paymentKeys as $key) { $evidence = get_option($key, null) !== null || $evidence; }
        return $evidence ? 'پرداخت هزینه ارسال این سفارش در حال بررسی است؛ وضعیت پرداخت قبلی را در صفحه سفارش بررسی کنید و دوباره پرداخت نکنید.' : null;
    }

    private static function products(\WC_Order $order): array
    {
        $products = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $imageId = $product ? (int) $product->get_image_id() : 0;
            $image = $imageId > 0 ? wp_get_attachment_image_url($imageId, 'thumbnail') : false;
            $editId = $product && $product->is_type('variation') ? (int) $product->get_parent_id()
                : ($product ? (int) $product->get_id() : 0);
            $editUrl = $editId > 0 && current_user_can('edit_post', $editId)
                ? get_edit_post_link($editId, 'raw') : null;
            $products[] = ['product_id' => self::positiveId($item->get_variation_id()) ?? self::positiveId($item->get_product_id()),
                'title' => self::text($item->get_name()), 'image_url' => is_string($image) ? esc_url_raw($image) : '',
                'edit_url' => is_string($editUrl) ? esc_url_raw($editUrl) : ''];
            if (count($products) >= 6) { break; }
        }
        return $products;
    }

    /** Display labels remain Persian even when WooCommerce's admin language is English. */
    public static function statusTitle(string $status): string
    {
        $labels = [
            'pending' => 'در انتظار پرداخت', 'processing' => 'در حال انجام',
            'on-hold' => 'در انتظار بررسی', 'completed' => 'تکمیل‌شده',
            'cancelled' => 'لغوشده', 'refunded' => 'مستردشده', 'failed' => 'ناموفق',
        ];
        return $labels[$status] ?? (function_exists('wc_get_order_status_name')
            ? self::text(wc_get_order_status_name($status)) : self::text($status));
    }

    private static function recipientName(\WC_Order $order): string
    {
        return self::text($order->get_formatted_shipping_full_name()) ?: self::text($order->get_formatted_billing_full_name());
    }

    /** All table/column identifiers come from Woo's active datastore, never browser input. */
    private static function queryOrderIds(?int $beforeId, ?int $exactId, string $search, int $limit): array
    {
        if (!function_exists('wc_get_order_statuses')) { throw self::error('ووکامرس برای دریافت سفارش‌ها باید فعال باشد.'); }
        global $wpdb;
        $hpos = class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        $orders = $hpos ? $wpdb->prefix . 'wc_orders' : $wpdb->posts;
        $meta = $hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
        $id = $hpos ? 'id' : 'ID';
        $metaId = $hpos ? 'order_id' : 'post_id';
        $type = $hpos ? 'type' : 'post_type';
        $status = $hpos ? 'status' : 'post_status';
        $statuses = array_values(array_filter(array_keys(wc_get_order_statuses()), static fn ($value): bool => $value !== 'wc-checkout-draft'));
        if ($statuses === []) { return []; }
        $statusSlots = implode(',', array_fill(0, count($statuses), '%s'));
        $payments = $wpdb->prefix . 'sync_basalam_payments';
        $where = "o.{$type} = 'shop_order' AND o.{$status} IN ({$statusSlots})
            AND NOT EXISTS (SELECT 1 FROM `{$payments}` p WHERE p.order_id = o.{$id} AND p.invoice_id > 0)
            AND NOT EXISTS (SELECT 1 FROM `{$meta}` m WHERE m.{$metaId} = o.{$id} AND
                ((m.meta_key = '_salam_gate_market' AND m.meta_value NOT IN ('', 'website'))
                 OR m.meta_key = '_salam_gate_basalam_mapping' OR m.meta_key REGEXP '^_salam_gate_(bp_|native_)'))";
        $values = $statuses;
        if ($beforeId !== null) { $where .= " AND o.{$id} < %d"; $values[] = $beforeId; }
        if ($exactId !== null) { $where .= " AND o.{$id} = %d"; $values[] = $exactId; }
        elseif ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $items = $wpdb->prefix . 'woocommerce_order_items';
            $searchClause = "EXISTS (SELECT 1 FROM `{$items}` i WHERE i.order_id = o.{$id}
                AND i.order_item_type = 'line_item' AND i.order_item_name LIKE %s)";
            $values[] = $like;
            if ($hpos) {
                $addresses = $wpdb->prefix . 'wc_order_addresses';
                $searchClause .= " OR EXISTS (SELECT 1 FROM `{$addresses}` a WHERE a.order_id = o.{$id}
                    AND a.address_type IN ('shipping','billing') AND CONCAT_WS(' ',a.first_name,a.last_name) LIKE %s)";
            } else {
                $searchClause .= " OR EXISTS (SELECT 1 FROM `{$meta}` a WHERE a.{$metaId} = o.{$id}
                    AND a.meta_key IN ('_shipping_first_name','_shipping_last_name','_shipping_address_index',
                        '_billing_first_name','_billing_last_name','_billing_address_index') AND a.meta_value LIKE %s)";
            }
            $values[] = $like;
            $where .= ' AND (' . $searchClause . ')';
        }
        $values[] = $limit;
        // IDs-only SQL is necessary for bounded recipient/product searches across HPOS and CPT.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $ids = $wpdb->get_col($wpdb->prepare("SELECT o.{$id} FROM `{$orders}` o WHERE {$where} ORDER BY o.{$id} DESC LIMIT %d", $values));
        if (!is_array($ids) || ($wpdb->last_error ?? '') !== '') {
            throw self::error('دریافت سفارش‌های ووکامرس انجام نشد؛ افزونه پایه ووسلام را بررسی کنید و دوباره تلاش کنید.');
        }
        return array_values($ids);
    }

    public static function positiveId($value): ?int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value))) { return null; }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }

    private static function text($value): string
    {
        return is_string($value) || is_int($value) ? trim(wp_strip_all_tags((string) $value)) : '';
    }

    private static function hasMetaValue($value): bool
    {
        return !in_array($value, ['', null, false, 0, '0'], true);
    }

    private static function error(string $message): SalamGateException
    {
        return new SalamGateException($message, 422, [], SalamGateException::KIND_VALIDATION);
    }
}
