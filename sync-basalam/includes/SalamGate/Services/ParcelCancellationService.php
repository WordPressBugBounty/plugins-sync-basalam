<?php

namespace SyncBasalam\SalamGate\Services;

use SyncBasalam\SalamGate\Utils\Arrays;
use SyncBasalam\SalamGate\ClientFactory;
use SyncBasalam\SalamGate\Contracts\CancellationEligibility;
use SyncBasalam\SalamGate\Contracts\LabelEligibility;
use SyncBasalam\SalamGate\Contracts\ParcelViewNormalizer;
use SyncBasalam\SalamGate\Order\NumericIdentity;
use SyncBasalam\SalamGate\Order\OrderContext;
use SyncBasalam\SalamGate\SalamGateClient;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Utils\OptionLock;

defined('ABSPATH') || exit;

/** No local monetary writes: POST delegates carrier void and refund to SalamGate. */
final class ParcelCancellationService
{
    public static function key(int $id): string { return 'salam_gate_parcel_cancel_' . $id; }

    public function run(int $id, bool $confirm): array
    {
        $lock = 'salam_gate_quote_workflow_' . $id;
        $lease = OptionLock::acquire($lock);
        if ($lease === null) {
            throw new SalamGateException('عملیات دیگری روی این مرسوله در حال اجراست؛ کمی بعد دوباره بررسی کنید.', 409);
        }
        try {
            $renew = static function () use ($lock, &$lease): void {
                $next = OptionLock::renew($lock, $lease);
                if ($next === null) { throw new \RuntimeException('Cancellation workflow lease lost.'); }
                $lease = $next;
            };
            // Never trust browser-supplied market, invoice, tracking code or order id.
            $client = ClientFactory::make();
            $response = $client->get(SalamGateClient::PATH_PARCELS,
                ['ids' => [$id], 'market_name' => ParcelsListService::ALL_MARKETS, 'per_page' => 1]);
            $items = $response['body']['data'] ?? null;
            if (!is_array($items) || !Arrays::isList($items) || count($items) !== 1
                || !is_array($items[0]) || !LabelEligibility::validListShape($items[0])
                || ParcelService::positiveId($items[0]['id'] ?? null) !== $id) {
                throw new SalamGateException('مرسوله در حساب متصل تأیید نشد؛ فهرست را دوباره بررسی کنید.', 422);
            }
            $raw = $items[0];
            if (!is_array($raw['market'] ?? null) || !is_string($raw['market']['value'] ?? null)
                || (isset($raw['external_id']) && ParcelService::positiveId($raw['external_id']) === null)) {
                throw new SalamGateException('اطلاعات مرسوله برای لغو معتبر نیست؛ درخواست لغو ارسال نشد.', 422);
            }
            $parcel = ParcelViewNormalizer::fromListItem($raw);
            if (!in_array($parcel['market'], CancellationEligibility::MARKETS, true)) {
                throw new SalamGateException('لغو مرسوله باسلامی از این بخش پشتیبانی نمی‌شود.', 422);
            }
            $renew();
            $record = get_option(self::key($id), null);
            if ($record !== null && (!is_array($record) || ($record['market'] ?? null) !== $parcel['market']
                || ($record['external_id'] ?? null) !== $parcel['external_id'])) {
                throw new SalamGateException('ارتباط درخواست لغو با این مرسوله تأیید نشد؛ درخواست جدیدی ارسال نشد.', 422);
            }
            if (($record['state'] ?? '') === 'confirmed') {
                return $this->finish($raw, $record, $renew);
            }
            // Read-only recovery after a lost response. VOIDED alone is NOT refund proof.
            if ($parcel['status_id'] === 44 && $parcel['invoice_status'] === 63) {
                $next = ['state' => 'confirmed', 'confirmed_at' => time()] + ($record ?? $this->snapshot($parcel));
                $this->store(self::key($id), $record, $next);
                return $this->finish($raw, $next, $renew);
            }
            if (!CancellationEligibility::canCancel($parcel)) {
                if (is_array($record) && in_array($parcel['status_id'], [9, 10, 11, 12], true) && $parcel['invoice_status'] === 2) {
                    $this->store(self::key($id), $record, ['state' => 'rejected'] + $record);
                }
                return $this->reply($raw, 'unavailable', 'این مرسوله قابل لغو نیست؛ فقط مرسوله پرداخت‌شده و تحویل‌داده‌نشده به پست قابل لغو است.');
            }
            // An interrupted write is checked first; a replay cannot silently become another POST.
            if ($record !== null && in_array($record['state'] ?? '', ['pending', 'review'], true)) {
                if (!$confirm) {
                    $next = ['state' => 'retryable'] + $record;
                    $this->store(self::key($id), $record, $next);
                    return $this->reply($raw, 'retryable', 'لغو هنوز در سلام‌گیت تأیید نشده است؛ در صورت تمایل دوباره درخواست لغو را تأیید کنید.');
                }
                return $this->reply($raw, 'review', 'نتیجه درخواست قبلی را با «بررسی وضعیت لغو» بررسی کنید.');
            }
            if (!$confirm) { return $this->reply($raw, 'eligible', 'این مرسوله تا پیش از تحویل به پست قابل لغو است.'); }

            $attempt = ['state' => 'pending', 'attempt_id' => bin2hex(random_bytes(16)), 'started_at' => time()]
                + $this->snapshot($parcel);
            $this->store(self::key($id), $record, $attempt);
            $dispatched = false;
            try {
                $renew();
                $dispatched = true;
                $result = $client->post(sprintf(SalamGateClient::PATH_PARCEL_CANCEL, $id));
                if (($result['body']['success'] ?? null) !== true) {
                    throw new SalamGateException('پاسخ لغو از سلام‌گیت کامل دریافت نشد.', 200);
                }
                // Persist upstream success BEFORE any Woo metadata writes, so repair never re-refunds.
                $next = ['state' => 'confirmed', 'confirmed_at' => time()] + $attempt;
                $this->store(self::key($id), $attempt, $next);
                return $this->finish($raw, $next, $renew);
            } catch (\Throwable $error) {
                // A local save/repair failure must not downgrade a confirmed upstream outcome.
                $current = get_option(self::key($id), null);
                if (is_array($current) && ($current['state'] ?? '') === 'confirmed') {
                    return $this->reply($raw, 'cancelled', 'مرسوله لغو شد؛ به‌روزرسانی سفارش کامل نشد. «بررسی وضعیت لغو» را بزنید.', false);
                }
                $noDispatch = !$dispatched || ($error instanceof SalamGateException && $error->isDefinitelyNotDispatched());
                $this->store(self::key($id), $attempt, ['state' => $noDispatch ? 'retryable' : 'review'] + $attempt);
                $message = $error instanceof SalamGateException ? $error->firstMessage() : 'پاسخ کامل دریافت نشد.';
                return $this->reply($raw, $noDispatch ? 'retryable' : 'review', $message . ($noDispatch
                    ? ' درخواست لغو ارسال نشد.' : ' نتیجه لغو را با «بررسی وضعیت لغو» بررسی کنید.'));
            }
        } finally {
            OptionLock::release($lock, $lease);
        }
    }

    private function snapshot(array $parcel): array
    {
        $order = ParcelsListService::linkedOrderForParcel($parcel);
        return ['parcel_id' => $parcel['id'], 'market' => $parcel['market'], 'external_id' => $parcel['external_id'],
            'invoice_id' => $parcel['invoice_id'], 'tracking_code' => $parcel['tracking_code'],
            'order_id' => $order !== null ? (int) $order->get_id() : null];
    }

    private function finish(array $raw, array $record, callable $renew): array
    {
        $renew();
        $this->syncOrder($record, $renew);
        $this->store(self::key((int) $raw['id']), $record, ['order_synced' => true] + $record);
        return $this->reply($raw, 'cancelled', 'مرسوله لغو شد و بازگشت هزینه در سلام‌گیت ثبت شد.');
    }

    /** Retain old paid identities forever; only a NEW shipment may charge the order again. */
    private function syncOrder(array $record, callable $renew): void
    {
        $orderId = ParcelService::positiveId($record['order_id'] ?? null);
        if (($record['market'] ?? '') !== 'website' || $orderId === null || !function_exists('wc_get_order')) { return; }
        $id = $record['parcel_id'];
        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order || OrderContext::marketFor($orderId) !== 'website') { return; }
        if (method_exists($order, 'read_meta_data')) { $order->read_meta_data(true); }
        $historyKey = '_salam_gate_cancelled_' . $id;
        $history = $order->get_meta($historyKey);
        $current = ParcelService::positiveId($order->get_meta('_salam_gate_parcel_id'));
        $detached = $current === null && is_array($history) && ($history['parcel_id'] ?? null) === $id;
        if ($current !== $id && !$detached) {
            // Repair of an old cancellation must never clear a newer shipment or its guard.
            if (is_array($history)) { $this->releaseOrderClaim($orderId, $id); }
            return;
        }
        $external = is_array($history) ? ParcelService::positiveId($history['external_id'] ?? null)
            : ParcelService::positiveId($order->get_meta('_salam_gate_external_id'));
        if ($external === null) { $external = ParcelService::positiveId($record['external_id'] ?? null) ?? $orderId; }
        if (($record['external_id'] ?? null) !== null && $record['external_id'] !== $external) {
            throw new \RuntimeException('Order cancellation identity changed.');
        }
        $nextExternal = NumericIdentity::forKey('woo-order:' . $orderId . ':after-cancel:' . $id);
        if ($nextExternal === null || $nextExternal === $external) { throw new \RuntimeException('New shipment identity unavailable.'); }
        $storedExternal = ParcelService::positiveId($order->get_meta('_salam_gate_external_id'));
        if ($storedExternal !== null && !in_array($storedExternal, [$external, $nextExternal], true)) {
            throw new \RuntimeException('Order shipment identity changed during cancellation.');
        }
        // Persist the old external fence even for legacy payments without identity claims.
        $externalKey = 'salam_gate_website_payment_external_' . $external;
        if (get_option($externalKey, null) === null) {
            $this->store($externalKey, null, ['state' => 'paid', 'parcel_id' => $id, 'cancelled' => true]);
        }
        $renew();
        $identityKey = 'salam_gate_website_order_identity_' . $orderId;
        $saved = get_option($identityKey, null);
        if ($saved !== null && !in_array(ParcelService::positiveId($saved), [$external, $nextExternal], true)) {
            throw new \RuntimeException('Newer order identity must not be replaced.');
        }
        $this->store($identityKey, $saved, (string) $nextExternal);
        $renew();
        $order->update_meta_data($historyKey, ['parcel_id' => $id, 'external_id' => $external,
            'invoice_id' => $record['invoice_id'], 'tracking_code' => $record['tracking_code'],
            'cancelled_at' => $record['confirmed_at'], 'next_external_id' => $nextExternal]);
        foreach (['_salam_gate_parcel_id', '_salam_gate_tracking_code', '_salam_gate_paid_at', '_salam_gate_invoice_id',
            '_salam_gate_pay_unknown', '_salam_gate_label', '_salam_gate_selected_service', '_salam_gate_delivery_node',
            '_salam_gate_shipping_cost_rial', '_salam_gate_created_at', '_salam_gate_order_draft_payload', '_salam_gate_order_draft_parcel_id'] as $key) {
            $order->delete_meta_data($key);
        }
        if ($order->get_meta('_basalam_order_tracking_code') === ($record['tracking_code'] ?? null)) {
            $order->delete_meta_data('_basalam_order_tracking_code');
        }
        $order->update_meta_data('_salam_gate_status', 44);
        $order->update_meta_data('_salam_gate_external_id', $nextExternal);
        $order->save_meta_data(); // Never change Woo status, customer payment, items or stock.
        $renew();
        $this->releaseOrderClaim($orderId, $id);
        if (!is_array($history)) { $order->add_order_note(sprintf('مرسوله سلام‌گیت #%d لغو و بازگشت هزینه ارسال در سلام‌گیت ثبت شد. خود سفارش لغو نشده است.', $id)); }
    }

    private function releaseOrderClaim(int $orderId, int $id): void
    {
        $key = 'salam_gate_website_payment_order_' . $orderId;
        $previous = get_option($key, null);
        if (is_array($previous) && ParcelService::positiveId($previous['parcel_id'] ?? null) === $id) {
            $this->store($key, $previous, null);
        }
    }

    private function reply(array $raw, string $state, string $message, bool $orderSynced = true): array
    {
        return ['parcel_id' => (int) $raw['id'], 'state' => $state, 'message' => $message,
            'order_synced' => $orderSynced, 'parcel' => ParcelsListService::normalizeItem($raw)];
    }

    /** Compare-and-swap: a late worker cannot erase another shipment's payment claim. */
    private function store(string $key, $previous, $next): void
    {
        if ($previous === $next) { return; }
        global $wpdb;
        $encode = static fn ($value): string => is_array($value) ? serialize($value) : (string) $value;
        $sql = $previous === null
            ? $wpdb->prepare("INSERT IGNORE INTO `{$wpdb->options}` (option_name, option_value, autoload) VALUES (%s, %s, %s)", $key, $encode($next), 'no')
            : ($next === null
                ? $wpdb->prepare("DELETE FROM `{$wpdb->options}` WHERE option_name = %s AND BINARY option_value = BINARY %s", $key, $encode($previous))
                : $wpdb->prepare("UPDATE `{$wpdb->options}` SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s", $encode($next), $key, $encode($previous)));
        $changed = $wpdb->query($sql);
        wp_cache_delete($key, 'options');
        wp_cache_delete('notoptions', 'options');
        if ((int) $changed !== 1) { throw new \RuntimeException('Cancellation evidence could not be persisted.'); }
    }
}
