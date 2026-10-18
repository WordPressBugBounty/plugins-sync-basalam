<?php

namespace SyncBasalam\SalamGate\Services;

use SyncBasalam\SalamGate\Order\OrderContext;
use SyncBasalam\SalamGate\Order\OrderParcelMapping;
use SyncBasalam\SalamGate\SalamGateException;

defined('ABSPATH') || exit;

/** Payment-only identity. It must never expand the order mapping used for metadata writes. */
final class WebsitePaymentGuard
{
    public static function keysForParcel(array $parcel): array
    {
        return self::identity($parcel)['keys'];
    }

    public static function hasLinkedOrder(array $parcel): bool
    {
        return self::identity($parcel)['order'] !== null;
    }

    /** Verified read-only link; it cannot expand metadata-write authorization. */
    public static function linkedOrderForParcel(array $parcel): ?\WC_Order
    {
        return self::identity($parcel)['order'];
    }

    /** Read-only UI/backend preflight, also covering outcomes from before the identity guard. */
    public static function blockForParcel(array $parcel): ?array
    {
        if (($parcel['market'] ?? '') !== 'website') { return null; }
        try { $identity = self::identity($parcel); }
        catch (SalamGateException $exception) {
            return ['state' => 'blocked', 'message' => $exception->firstMessage()];
        }
        $states = [];
        foreach ($identity['keys'] as $key) {
            $record = get_option($key, null);
            if ($record !== null) { $states[] = is_array($record) ? ($record['state'] ?? 'unknown') : 'unknown'; }
        }
        $order = $identity['order'] ?? self::legacyOrderWithPaymentEvidence($identity['external_id']);
        if ($order !== null) {
            if ($order->get_meta('_salam_gate_paid_at') !== ''
                || ParcelService::positiveId($order->get_meta('_salam_gate_invoice_id')) !== null) { $states[] = 'paid'; }
            if ($order->get_meta('_salam_gate_pay_unknown') !== '') { $states[] = 'unknown'; }
            $current = ParcelService::positiveId($order->get_meta('_salam_gate_parcel_id'));
            $outcome = $current !== null ? get_option(ParcelService::paymentOutcomeKey($current), null) : null;
            if ($outcome !== null) { $states[] = is_array($outcome) ? ($outcome['state'] ?? 'unknown') : 'unknown'; }
        }
        if (in_array('paid', $states, true)) {
            return ['state' => 'paid', 'message' => 'هزینه ارسال این سفارش قبلاً پرداخت شده است؛ پرداخت دوباره انجام نمی‌شود.'];
        }
        if ($states !== []) {
            return ['state' => in_array('unknown', $states, true) ? 'unknown' : 'pending',
                'message' => 'پرداخت هزینه ارسال این سفارش در حال بررسی است؛ وضعیت پرداخت قبلی را بررسی کنید و دوباره پرداخت نکنید.'];
        }
        return null;
    }

    /**
     * Older plugin versions used the Woo number as external_id without persisting it.
     * This fallback can only REFUSE payment, never authorize a mapping, metadata write,
     * or an unpaid order claim. A colliding legacy number remains conservative.
     */
    private static function legacyOrderWithPaymentEvidence(?int $externalId): ?\WC_Order
    {
        if ($externalId === null || $externalId >= 4503599627370496 || !function_exists('wc_get_order')) { return null; }
        $order = wc_get_order($externalId);
        if (!$order instanceof \WC_Order || $order->get_meta('_salam_gate_market') !== 'website'
            || $order->get_meta('_salam_gate_external_id') !== ''
            || get_option('salam_gate_website_order_identity_' . $externalId, null) !== null
            || OrderContext::marketFor($externalId) !== 'website') { return null; }
        $current = ParcelService::positiveId($order->get_meta('_salam_gate_parcel_id'));
        if ($current === null) { return null; }
        $outcome = get_option(ParcelService::paymentOutcomeKey($current), null);
        return $order->get_meta('_salam_gate_paid_at') !== '' || $order->get_meta('_salam_gate_pay_unknown') !== ''
            || ParcelService::positiveId($order->get_meta('_salam_gate_invoice_id')) !== null || $outcome !== null
            ? $order : null;
    }

    /** Preserve owner-verified identity before UPDATE clears external_id in the remote DTO. */
    public static function rememberIdentity(array $parcel, ?int $targetId = null): void
    {
        if (($parcel['market'] ?? '') !== 'website') { return; }
        $identity = self::identity($parcel);
        $id = $targetId ?? ParcelService::positiveId($parcel['id'] ?? null);
        if ($id === null || $id < 1) { throw self::identityError(); }
        $key = 'salam_gate_website_parcel_identity_' . $id;
        $next = ['external_id' => $identity['external_id'],
            'order_id' => $identity['order'] !== null ? (int) $identity['order']->get_id() : null];
        $existing = get_option($key, null);
        if ($existing === $next) { return; }
        if ($existing !== null) { throw self::identityError(); }
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO `{$wpdb->options}` (option_name, option_value, autoload) VALUES (%s, %s, %s)",
            $key, serialize($next), 'no'
        ));
        wp_cache_delete($key, 'options');
        wp_cache_delete('notoptions', 'options');
        if (get_option($key, null) !== $next) { throw self::identityError(); }
    }

    private static function identity(array $parcel): array
    {
        if (($parcel['market'] ?? '') !== 'website') { return ['keys' => [], 'order' => null, 'external_id' => null]; }
        require_once dirname(__DIR__) . '/Order/OrderParcelMapping.php';
        $id = ParcelService::positiveId($parcel['id'] ?? null);
        $external = ParcelService::positiveId($parcel['external_id'] ?? null);
        if ($id === null || (isset($parcel['external_id']) && $external === null)) { throw self::identityError(); }
        $saved = get_option('salam_gate_website_parcel_identity_' . $id, null);
        if ($saved !== null) {
            if (!is_array($saved) || ($savedExternal = ParcelService::positiveId($saved['external_id'] ?? null)) === null
                || ($external !== null && $savedExternal !== $external)) { throw self::identityError(); }
            $external = $savedExternal;
        }
        $exact = ParcelsListService::linkedOrderForParcel($parcel);
        $mappedIds = OrderParcelMapping::websiteOrdersForParcel($id);
        if ($mappedIds === null) { throw self::identityError(); }
        if ($external === null && $exact === null && $mappedIds === []) {
            $external = CreationAttemptService::externalIdForWebsiteParcel($id);
        }
        $orderIds = $external !== null ? OrderParcelMapping::websiteOrdersForExternalId($external) : [];
        if ($orderIds === null) { throw self::identityError(); }
        $orderIds = array_merge($orderIds, $mappedIds);
        if ($exact !== null) { $orderIds[] = (int) $exact->get_id(); }
        if (is_array($saved) && ($saved['order_id'] ?? null) !== null) {
            $savedOrder = ParcelService::positiveId($saved['order_id']);
            if ($savedOrder === null) { throw self::identityError(); }
            $orderIds[] = $savedOrder;
        }
        $orderIds = array_values(array_unique($orderIds));
        if (count($orderIds) > 1) { throw self::identityError(); }
        $order = $orderIds !== [] && function_exists('wc_get_order') ? wc_get_order($orderIds[0]) : null;
        if ($orderIds !== [] && (!$order instanceof \WC_Order || OrderContext::marketFor((int) $order->get_id()) !== 'website'
            || !in_array($order->get_meta('_salam_gate_market'), ['', 'website'], true))) { throw self::identityError(); }
        if ($order !== null) {
            $raw = $order->get_meta('_salam_gate_external_id');
            $canonical = $raw !== '' ? ParcelService::positiveId($raw)
                : ParcelService::positiveId(get_option('salam_gate_website_order_identity_' . $order->get_id(), null));
            // Legacy local-order-number identity is trusted only by exact saved SG mapping.
            if ($canonical === null && $raw === '' && $exact !== null) { $canonical = (int) $order->get_id(); }
            if ($canonical === null || ($external !== null && $canonical !== $external)) { throw self::identityError(); }
            $external = $canonical;
        }
        // An unlinked historical UPDATE with no identity must not become another charge.
        if ($external === null) { throw self::identityError(); }
        $keys = ['salam_gate_website_payment_external_' . $external];
        if ($order !== null) { $keys[] = 'salam_gate_website_payment_order_' . $order->get_id(); }
        sort($keys, SORT_STRING);
        return ['keys' => $keys, 'order' => $order, 'external_id' => $external];
    }

    private static function identityError(): SalamGateException
    {
        return new SalamGateException('ارتباط این مرسوله با ثبت قبلی قابل تأیید نیست؛ برای جلوگیری از پرداخت تکراری، پرداخت انجام نمی‌شود. وضعیت ارسال قبلی را از صفحه سفارش یا جزئیات مرسوله بررسی کنید.',
            422, [['location' => 'payment.identity', 'message' => 'website-payment-identity-unconfirmed']]);
    }
}
