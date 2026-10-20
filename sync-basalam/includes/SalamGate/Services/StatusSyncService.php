<?php

namespace SyncBasalam\SalamGate\Services;

use SyncBasalam\SalamGate\ClientFactory;
use SyncBasalam\SalamGate\Contracts\ParcelStatuses;
use SyncBasalam\SalamGate\Contracts\ParcelViewNormalizer;
use SyncBasalam\SalamGate\Order\BasalamParcelOrder;
use SyncBasalam\SalamGate\Order\OrderContext;
use SyncBasalam\SalamGate\SalamGateClient;
use SyncBasalam\SalamGate\Utils\OptionLock;

defined('ABSPATH') || exit;

require_once __DIR__ . '/../Order/BasalamParcelOrder.php';

/** Read-only remote reconciliation; never creates, selects a quote or pays. */
final class StatusSyncService
{
    public const LOCK_KEY = 'salam_gate_sync_lock';
    public const CURSOR_KEY = 'salam_gate_sync_cursor';
    public const LAST_SYNC_KEY = 'salam_gate_last_sync';

    /**
     * IDs-only queries against the authoritative datastore; all writes use WC CRUD.
     * Keyset pagination rotates through the queue, including orders without status meta.
     */
    public function findSyncableOrderIds(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $cursor = max(0, (int) get_option(self::CURSOR_KEY, 0));
        $ids = $this->queryOrderIds($cursor, $limit, false);
        if (count($ids) < $limit && $cursor > 0) {
            $ids = array_merge($ids, $this->queryOrderIds($cursor, $limit - count($ids), true));
        }

        return $ids;
    }

    /** Fill missing sidebar metadata without replacing a newer known status. */
    public function syncMissingOrder(int $orderId, ?int $nativeId = null): bool
    {
        $root = $this->freshOrder($orderId);
        if (!$root) return false;
        $updated = false;
        foreach ($this->scopesFor($root) as $scope) {
            if ($nativeId !== null && $scope['native_id'] !== $nativeId) continue;
            $updated = $this->syncMissingScope($scope['order'], $scope['native_id']) || $updated;
        }
        return $updated;
    }

    private function syncMissingScope($order, ?int $nativeId): bool
    {
        if (ParcelStatuses::isValid(ParcelService::positiveId($order->get_meta('_salam_gate_status')) ?? 0)) return false;
        $orderId = (int) $order->get_id();
        $expected = $this->identityFor($order);
        if ($expected === null) return false;
        $attemptKey = 'salam_gate_missing_status_' . $orderId . ($nativeId === null ? '' : '_native_' . $nativeId);
        if (get_transient($attemptKey) === $expected['parcel_id']) return false;
        $lockKey = 'salam_gate_quote_workflow_' . $expected['parcel_id'];
        $lease = OptionLock::acquire($lockKey);
        if ($lease === null) return false;

        try {
            $client = ClientFactory::make();
            if ($client->token() === null) return false;
            $snapshot = $this->localSnapshot($order, $expected['parcel_id']);
            // An unavailable service must not receive another read on each sidebar render.
            set_transient($attemptKey, $expected['parcel_id'], 60);
            $response = $client->get(SalamGateClient::PATH_PARCELS, [
                'ids' => [$expected['parcel_id']], 'market_name' => [$expected['market']], 'per_page' => 1,
            ]);
            $data = $response['body']['data'] ?? null;
            if (!is_array($data)) return false;
            $items = $this->indexItems($data);
            $item = $items[$expected['parcel_id']] ?? null;
            $freshOrder = $this->freshScopedOrder($orderId, $nativeId);
            if (!$freshOrder || $this->identityFor($freshOrder) !== $expected || !is_array($item)
                || !$this->matches($item, $expected)
                || ParcelStatuses::isValid(ParcelService::positiveId($freshOrder->get_meta('_salam_gate_status')) ?? 0)
                || $this->localSnapshot($freshOrder, $expected['parcel_id']) !== $snapshot) {
                return false;
            }
            $renewed = OptionLock::renew($lockKey, $lease);
            if ($renewed === null) return false;
            $lease = $renewed;
            $this->apply($freshOrder, $item, $expected['market'], static function () use ($lockKey, &$lease): bool {
                $renewed = OptionLock::renew($lockKey, $lease);
                if ($renewed === null) return false;
                $lease = $renewed;
                return true;
            });
            delete_transient($attemptKey);
            return true;
        } catch (\Throwable $exception) {
            $this->logFailure('دریافت وضعیت اولیه مرسوله انجام نشد.', ['order_id' => $orderId]);
            return false;
        } finally {
            OptionLock::release($lockKey, $lease);
        }
    }

    /** @return array{checked:int,updated:int,failed:int,skipped:string} */
    public function syncBatch(int $limit = 50): array
    {
        $stats = ['checked' => 0, 'updated' => 0, 'failed' => 0, 'skipped' => ''];
        if (!function_exists('wc_get_order')) {
            $stats['skipped'] = 'woocommerce_unavailable';
            return $stats;
        }
        $lease = OptionLock::acquire(self::LOCK_KEY);
        if ($lease === null) {
            $stats['skipped'] = 'locked';
            return $stats;
        }

        try {
            $client = ClientFactory::make();
            if ($client->token() === null) {
                $stats['skipped'] = 'no_token';
                update_option(self::LAST_SYNC_KEY, ['ran_at' => time()] + $stats, false);
                return $stats;
            }
            $ids = $this->findSyncableOrderIds($limit);
            $ordersByParcel = [];
            foreach ($ids as $orderId) {
                try {
                    $order = $this->freshOrder($orderId);
                    if (!$order) {
                        ++$stats['failed'];
                        continue;
                    }
                    $scopes = $this->scopesFor($order);
                    if ($scopes === []) ++$stats['failed'];
                    foreach ($scopes as $scope) {
                        $view = $scope['order'];
                        if (ParcelStatuses::isFinal((int) $view->get_meta('_salam_gate_status'))) continue;
                        $identity = $this->identityFor($view);
                        if ($identity === null) {
                            ++$stats['failed'];
                            continue;
                        }
                        $ordersByParcel[$identity['parcel_id']][] = [
                            'order_id' => $orderId,
                            'native_id' => $scope['native_id'],
                            'local_snapshot' => $this->localSnapshot($view, $identity['parcel_id']),
                        ] + $identity;
                    }
                } catch (\Throwable $exception) {
                    ++$stats['failed'];
                    $this->logFailure('تشخیص هویت سفارش سلام‌گیت ناموفق بود.', ['order_id' => $orderId]);
                }
            }

            foreach (array_chunk(array_keys($ordersByParcel), 30) as $parcelIds) {
                $renewed = OptionLock::renew(self::LOCK_KEY, $lease);
                if ($renewed === null) {
                    $stats['skipped'] = 'lock_lost';
                    break;
                }
                $lease = $renewed;
                try {
                    $response = $client->get(SalamGateClient::PATH_PARCELS, [
                        'ids' => $parcelIds,
                        'market_name' => [OrderContext::MARKET_BASALAM, OrderContext::MARKET_WEBSITE],
                        'per_page' => 30,
                    ]);
                    $data = $response['body']['data'] ?? null;
                    if (!is_array($data)) {
                        throw new \RuntimeException('Invalid parcel-list envelope.');
                    }
                    // Do not write if our lease expired or a successor has taken over.
                    $renewed = OptionLock::renew(self::LOCK_KEY, $lease);
                    if ($renewed === null) {
                        $stats['skipped'] = 'lock_lost';
                        break;
                    }
                    $lease = $renewed;
                    $items = $this->indexItems($data);
                } catch (\Throwable $exception) {
                    foreach ($parcelIds as $parcelId) {
                        $stats['failed'] += count($ordersByParcel[$parcelId]);
                    }
                    $this->logFailure('دریافت یک دسته از وضعیت مرسوله‌ها ناموفق بود.', ['parcel_count' => count($parcelIds)]);
                    continue;
                }

                foreach ($parcelIds as $parcelId) {
                    foreach ($ordersByParcel[$parcelId] as $expected) {
                        $workflowKey = 'salam_gate_quote_workflow_' . $parcelId;
                        $workflowLease = null;
                        try {
                            // Foreground creation recovery, quote selection, edits and payment
                            // use this same lease. The batch lease alone cannot exclude them.
                            $workflowLease = OptionLock::acquire($workflowKey);
                            if ($workflowLease === null) {
                                ++$stats['failed'];
                                continue;
                            }
                            $item = $items[$parcelId] ?? null;
                            $order = $this->freshScopedOrder($expected['order_id'], $expected['native_id']);
                            if (!$order || $this->identityFor($order) !== array_diff_key($expected, ['order_id' => true, 'native_id' => true, 'local_snapshot' => true])
                                || !is_array($item) || !$this->matches($item, $expected)) {
                                ++$stats['failed'];
                                continue;
                            }
                            // The foreground workflow could have reached a final status since selection.
                            if (ParcelStatuses::isFinal((int) $order->get_meta('_salam_gate_status'))) {
                                ++$stats['checked'];
                                continue;
                            }
                            // Acquiring the lease after HTTP cannot make an earlier response
                            // fresh again. Discard it if a foreground operation completed in
                            // the meantime, including non-final barcode/shipped transitions.
                            // Actual enum transitions are not assumed numerically monotonic.
                            if ($this->localSnapshot($order, $parcelId) !== $expected['local_snapshot']) {
                                ++$stats['failed'];
                                continue;
                            }
                            // A preceding CRUD save can stall beyond the lease; fence every
                            // subsequent order instead of trusting the post-HTTP renewal.
                            $renewed = OptionLock::renew(self::LOCK_KEY, $lease);
                            if ($renewed === null) {
                                $stats['skipped'] = 'lock_lost';
                                break 3;
                            }
                            $lease = $renewed;
                            $renewed = OptionLock::renew($workflowKey, $workflowLease);
                            if ($renewed === null) {
                                ++$stats['failed'];
                                continue;
                            }
                            $workflowLease = $renewed;
                            $changed = $this->apply($order, $item, $expected['market'], static function () use (
                                $workflowKey, &$workflowLease, &$lease, &$stats
                            ): bool {
                                // A CRUD save can outlive either lease. A later order-note
                                // write must not proceed on the now stale ownership token.
                                $renewed = OptionLock::renew(self::LOCK_KEY, $lease);
                                if ($renewed === null) {
                                    $stats['skipped'] = 'lock_lost';
                                    return false;
                                }
                                $lease = $renewed;
                                $renewed = OptionLock::renew($workflowKey, $workflowLease);
                                if ($renewed === null) return false;
                                $workflowLease = $renewed;
                                return true;
                            });
                            ++$stats['checked'];
                            $stats['updated'] += (int) $changed;
                            if ($stats['skipped'] === 'lock_lost') break 3;
                        } catch (\Throwable $exception) {
                            ++$stats['failed'];
                            $this->logFailure('ذخیره وضعیت مرسوله ناموفق بود.', ['order_id' => $expected['order_id']]);
                        } finally {
                            if ($workflowLease !== null) {
                                OptionLock::release($workflowKey, $workflowLease);
                            }
                        }
                    }
                }
            }

            // Failed orders are retried next cycle; they never block all later orders.
            if ($ids !== [] && $stats['skipped'] !== 'lock_lost') {
                update_option(self::CURSOR_KEY, (int) end($ids), false);
            }
            // Retain diagnostics without emitting a routine INFO entry on every run.
            update_option(self::LAST_SYNC_KEY, ['ran_at' => time()] + $stats, false);
        } catch (\Throwable $exception) {
            $stats['skipped'] = 'error';
            $this->logFailure('اجرای همگام‌سازی سلام‌گیت ناموفق بود.', []);
            update_option(self::LAST_SYNC_KEY, ['ran_at' => time()] + $stats, false);
        } finally {
            OptionLock::release(self::LOCK_KEY, $lease);
        }

        return $stats;
    }

    private function queryOrderIds(int $cursor, int $limit, bool $wrap): array
    {
        global $wpdb;

        $hpos = class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        $orders = $hpos ? $wpdb->prefix . 'wc_orders' : $wpdb->posts;
        $meta = $hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
        $orderId = $hpos ? 'id' : 'ID';
        $metaOrderId = $hpos ? 'order_id' : 'post_id';
        $type = $hpos ? 'type' : 'post_type';
        $operator = $wrap ? '<=' : '>';
        // Identifiers come only from WordPress/Woo; cursor and limit are prepared.
        $sql = $wpdb->prepare(
            "SELECT o.{$orderId} FROM `{$orders}` o
             WHERE o.{$type} = 'shop_order' AND o.{$orderId} {$operator} %d
             AND ((EXISTS (SELECT 1 FROM `{$meta}` p WHERE p.{$metaOrderId} = o.{$orderId}
                 AND p.meta_key = '_salam_gate_parcel_id' AND CAST(p.meta_value AS UNSIGNED) > 0)
             AND NOT EXISTS (SELECT 1 FROM `{$meta}` s WHERE s.{$metaOrderId} = o.{$orderId}
                 AND s.meta_key = '_salam_gate_status' AND s.meta_value IN ('10', '12', '44')))
             OR EXISTS (SELECT 1 FROM `{$meta}` n WHERE n.{$metaOrderId} = o.{$orderId}
                 AND n.meta_key LIKE '\\\\_salam\\\\_gate\\\\_native\\\\_link\\\\_%%'
                 AND CAST(n.meta_value AS UNSIGNED) > 0))
             ORDER BY o.{$orderId} ASC LIMIT %d",
            $cursor,
            $limit
        );
        $ids = $wpdb->get_col($sql);
        if (!is_array($ids) || $wpdb->last_error !== '') {
            throw new \RuntimeException('Unable to select syncable orders.');
        }

        return array_map('intval', $ids);
    }

    private function identityFor($order): ?array
    {
        $orderId = (int) $order->get_id();
        $parcelId = ParcelService::positiveId($order->get_meta('_salam_gate_parcel_id'));
        $market = OrderContext::marketFor($orderId);
        $externalId = OrderContext::externalIdForOrder($order, $market);
        $storedMarket = $order->get_meta('_salam_gate_market');
        $storedExternal = $order->get_meta('_salam_gate_external_id');
        if ($parcelId === null || $externalId === null || $externalId <= 0
            || ($storedMarket !== '' && $storedMarket !== $market)
            || ($storedExternal !== '' && ParcelService::positiveId($storedExternal) !== $externalId)) {
            return null;
        }

        return ['parcel_id' => $parcelId, 'market' => $market, 'external_id' => $externalId];
    }

    /** Bypass an already hydrated Woo metadata instance when checking a foreground write. */
    private function freshOrder(int $orderId)
    {
        $order = wc_get_order($orderId);
        if ($order && method_exists($order, 'read_meta_data')) {
            $order->read_meta_data(true);
        }
        return $order;
    }

    /** Shipping scopes are independent; copied legacy state is never reconciled twice. */
    private function scopesFor($root): array
    {
        $scopes = [];
        $nativeParcelIds = [];
        if ($root instanceof \WC_Order) {
            foreach (BasalamParcelOrder::entries($root) as $nativeId => $view) {
                $parcelId = ParcelService::positiveId($view->get_meta('_salam_gate_parcel_id'));
                if ($parcelId === null) continue;
                $scopes[] = ['native_id' => (int) $nativeId, 'order' => $view];
                $nativeParcelIds[$parcelId] = true;
            }
        }
        $legacyId = ParcelService::positiveId($root->get_meta('_salam_gate_parcel_id'));
        if ($legacyId !== null && !isset($nativeParcelIds[$legacyId]) && !$this->legacyHasScopedEvidence($root, $legacyId)) {
            $scopes[] = ['native_id' => null, 'order' => $root];
        }
        return $scopes;
    }

    /** A damaged migrated scope must not redirect reconciliation onto its parent. */
    private function legacyHasScopedEvidence($root, int $parcelId): bool
    {
        if (ParcelService::positiveId($root->get_meta('_salam_gate_native_link_' . $parcelId)) !== null) return true;
        if (!method_exists($root, 'get_meta_data')) return false;
        foreach ($root->get_meta_data() as $meta) {
            $data = $meta->get_data();
            if (preg_match('/^_salam_gate_bp_[1-9][0-9]*_salam_gate_parcel_id$/D', (string) ($data['key'] ?? ''))
                && ParcelService::positiveId($data['value'] ?? null) === $parcelId) return true;
        }
        return false;
    }

    /** Reopen the exact child after HTTP; never fall back to its parent or sibling. */
    private function freshScopedOrder(int $orderId, ?int $nativeId)
    {
        $root = $this->freshOrder($orderId);
        if (!$root || $nativeId === null) return $root;
        return $root instanceof \WC_Order ? BasalamParcelOrder::stored($root, $nativeId) : null;
    }

    /** Local evidence captured before the remote batch read; never changes financial guards. */
    private function localSnapshot($order, int $parcelId): array
    {
        $snapshot = [];
        if ($order instanceof BasalamParcelOrder) {
            $snapshot['native_identity'] = serialize($order->getIdentity());
            $snapshot['native_allocation'] = serialize($order->getAllocation());
        }
        foreach (['_salam_gate_status', '_salam_gate_status_checked_at', '_salam_gate_tracking_code',
            '_basalam_order_tracking_code', '_salam_gate_shipping_cost_rial', '_salam_gate_invoice_id',
            '_salam_gate_paid_at', '_salam_gate_pay_unknown', '_salam_gate_selected_service'] as $key) {
            $value = $order->get_meta($key);
            $snapshot[$key] = is_scalar($value) || $value === null ? (string) $value : serialize($value);
        }
        global $wpdb;
        foreach ([ParcelService::paymentOutcomeKey($parcelId), 'salam_gate_parcel_edit_' . $parcelId] as $key) {
            // Read the authoritative row rather than an option cached earlier by this worker.
            $raw = $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s LIMIT 1", $key
            ));
            if ($wpdb->last_error !== '') {
                throw new \RuntimeException('Unable to read parcel workflow evidence.');
            }
            $snapshot[$key] = hash('sha256', is_string($raw) ? $raw : serialize($raw));
        }
        return $snapshot;
    }

    private function indexItems(array $data): array
    {
        $items = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = ParcelService::positiveId($item['id'] ?? null);
            if ($id !== null) {
                // Duplicate identities are ambiguous; never use the first/last one silently.
                $items[$id] = array_key_exists($id, $items) ? null : $item;
            }
        }

        return $items;
    }

    private function matches(array $item, array $expected): bool
    {
        $status = ParcelService::positiveId($item['status']['id'] ?? null);
        $tracking = $item['tracking_code'] ?? null;
        $external = $item['external_id'] ?? null;
        // Website updates use the recorded Salam Gate id (the API forbids id+external_id)
        // and may clear external_id. The authenticated vendor list plus exact local id/market
        // remains authoritative. Basalam still requires its verified vendor-parcel id; a supplied external
        // id on either market must match and can never be ignored.
        $externalMatches = $external === null
            ? $expected['market'] === OrderContext::MARKET_WEBSITE
            : ParcelService::positiveId($external) === $expected['external_id'];

        return ParcelService::positiveId($item['id'] ?? null) === $expected['parcel_id']
            && ($item['market']['value'] ?? null) === $expected['market']
            && $externalMatches
            && $status !== null && ParcelStatuses::isValid($status)
            && ($tracking === null || is_string($tracking) || is_int($tracking));
    }

    private function apply($order, array $item, string $market, ?callable $canWriteNote = null): bool
    {
        $status = ParcelService::positiveId($item['status']['id']);
        $previous = (int) $order->get_meta('_salam_gate_status');
        $changed = $previous !== $status;
        $order->update_meta_data('_salam_gate_status', $status);
        $order->update_meta_data('_salam_gate_status_checked_at', current_time('mysql'));
        $tracking = (string) ($item['tracking_code'] ?? '');
        if ($tracking !== '' && (string) $order->get_meta('_salam_gate_tracking_code') === '') {
            $order->update_meta_data('_salam_gate_tracking_code', $tracking);
            $changed = true;
        }
        if ($market === OrderContext::MARKET_BASALAM && $tracking !== ''
            && (string) $order->get_meta('_basalam_order_tracking_code') === '') {
            $order->update_meta_data('_basalam_order_tracking_code', $tracking);
            $changed = true;
        }
        $invoice = is_array($item['invoice'] ?? null) ? $item['invoice'] : [];
        if (ParcelService::positiveId($invoice['status'] ?? null) === 2) {
            $shippingCostRial = ParcelViewNormalizer::invoiceAmountRial($item);
            if ($shippingCostRial !== null
                && (string) $order->get_meta('_salam_gate_shipping_cost_rial') !== (string) $shippingCostRial) {
                $order->update_meta_data('_salam_gate_shipping_cost_rial', $shippingCostRial);
                $changed = true;
            }
        }
        $order->save();
        $notes = [
            ParcelStatuses::SHIPPED => 'مرسوله سلام‌گیت ارسال شد.',
            ParcelStatuses::DELIVERED => 'مرسوله سلام‌گیت تحویل داده شد.',
            ParcelStatuses::HANDED_TO_POSTMAN => 'مرسوله سلام‌گیت تحویل نامه‌رسان شد.',
            ParcelStatuses::RETURNED_TO_SENDER => 'مرسوله سلام‌گیت مرجوع شد.',
            ParcelStatuses::VOIDED => 'مرسوله سلام‌گیت ابطال شد.',
        ];
        if ($previous !== $status && isset($notes[$status]) && ($canWriteNote === null || $canWriteNote())) {
            $order->add_order_note($notes[$status]);
        }

        return $changed;
    }

    private function logFailure(string $message, array $context): void
    {
        if (class_exists(\SyncBasalam\Logger\Logger::class)) {
            \SyncBasalam\Logger\Logger::warning($message, $context);
        }
    }
}
