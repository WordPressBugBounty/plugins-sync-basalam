<?php

namespace SyncBasalam\SalamGate\Order;

use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\ConnectionCache;
use SyncBasalam\SalamGate\Services\ParcelService;
use SyncBasalam\SalamGate\Services\ParcelsListService;
use SyncBasalam\SalamGate\Utils\Units;

defined('ABSPATH') || exit;
require_once __DIR__ . '/BasalamParcelSet.php';
require_once __DIR__ . '/BasalamParcelOrder.php';
require_once __DIR__ . '/BasalamShippingPreflight.php';
require_once __DIR__ . '/OrderWeightConfirmation.php';
require_once __DIR__ . '/OrderParcelMapping.php';

/** Read-only source checks for existing Basalam parcels, including unlinked account drafts. */
final class BasalamCheckoutPreflight
{
    /** Caller has already verified the normalized SalamGate item against the current account. */
    public static function check(array $item, bool $requireWeightConsent = false, array $request = []): ?array
    {
        if (($item['market'] ?? null) !== 'basalam') { return null; }
        $parcelId = ParcelService::positiveId($item['id'] ?? null);
        $nativeId = ParcelService::positiveId($item['external_id'] ?? null);
        $vendorId = self::vendorId();
        $connection = ConnectionCache::identityHash();
        if ($parcelId === null || $nativeId === null || $vendorId === null) {
            throw self::error('ارتباط این مرسوله با سفارش اصلی و غرفه باسلام مشخص نیست؛ ثبت و پرداخت انجام نشد.');
        }
        self::fence($connection, $vendorId);
        $order = ParcelsListService::linkedOrderForParcel($item);
        if ($order === null && OrderParcelMapping::hasEvidence($parcelId, $nativeId)) {
            throw self::error('ارتباط ذخیره‌شده این مرسوله معتبر یا یکتا نیست؛ ابتدا ارسال و پرداخت قبلی را بررسی کنید.');
        }

        if ($order instanceof BasalamParcelOrder) {
            $preflight = BasalamShippingPreflight::check($order);
        } elseif ($order instanceof \WC_Order) {
            // check(WC_Order) may migrate legacy mapping metadata. Use unchanged fresh
            // snapshots instead: checkout preflight must never save or repair an order.
            $snapshots = BasalamParcelSet::load($order, $nativeId);
            $preflight = BasalamShippingPreflight::fromSnapshots($order, $snapshots['order'], $snapshots['parcel'],
                $vendorId, $snapshots['invoice_id'], $nativeId);
        } else {
            // An external_id is a native parcel ID, never an invoice or Woo order ID.
            $parcel = BasalamParcelReader::parcel($nativeId);
            self::fence($connection, $vendorId);
            $invoiceId = is_array($parcel['order'] ?? null)
                ? ParcelService::positiveId($parcel['order']['id'] ?? null) : null;
            if ($invoiceId === null) {
                throw self::error('شناسه سفارش اصلی این مرسوله باسلام مشخص نیست؛ ارسال را از پنل باسلام بررسی کنید.');
            }
            $parent = BasalamParcelReader::order($vendorId, $invoiceId);
            self::fence($connection, $vendorId);
            $preflight = BasalamShippingPreflight::fromSnapshots(null, $parent, $parcel,
                $vendorId, $invoiceId, $nativeId, [], true, true);
        }
        self::fence($connection, $vendorId);
        if (($preflight['identity']['parcel_id'] ?? null) !== $nativeId
            || ($preflight['identity']['vendor_id'] ?? null) !== $vendorId) {
            throw self::error('ارتباط مرسوله با سفارش اصلی باسلام تغییر کرده است؛ پرداخت انجام نشد.');
        }

        $detail = (new ParcelService())->getDetail($parcelId);
        self::fence($connection, $vendorId);
        self::assertLocalIdentity($order, $item, $preflight);
        if (ParcelService::positiveId($detail['id'] ?? null) !== $parcelId
            || (array_key_exists('external_id', $detail) && ParcelService::positiveId($detail['external_id']) !== $nativeId)
            || (array_key_exists('market', $detail) && ($detail['market']['value'] ?? null) !== 'basalam')) {
            throw self::error('هویت مرسوله سلام‌گیت با مرسوله اصلی باسلام مطابقت ندارد؛ پرداخت انجام نشد.');
        }
        BasalamShippingPreflight::assertOriginalFinancials($preflight, $detail);
        $receiver = is_array($detail['shipping_info']['receiver'] ?? null) ? $detail['shipping_info']['receiver'] : [];
        $errors = BasalamShippingPreflight::receiverInputErrors([
            'name' => $receiver['full_name'] ?? null, 'mobile' => $receiver['phone'] ?? null,
            'address' => $receiver['address'] ?? null, 'postal_code' => $receiver['postal_code'] ?? null,
            'city_id' => $receiver['city']['id'] ?? $receiver['city_id'] ?? null,
        ]);
        if ($errors !== []) {
            $fields = [];
            foreach ($errors as $field => $message) { $fields[] = ['location' => 'receiver.' . $field, 'message' => $message]; }
            throw new SalamGateException('مشخصات گیرنده مرسوله را پیش از ادامه کامل کنید؛ پرداخت انجام نشد.',
                422, $fields, SalamGateException::KIND_VALIDATION);
        }
        $weight = Units::parseBoundedFormInteger($detail['weight'] ?? null, 50, 30000);
        if ($weight === null) { throw self::error('وزن فعلی مرسوله معتبر نیست؛ پیش از ادامه مشخصات مرسوله را بررسی کنید.'); }
        if ($requireWeightConsent) { OrderWeightConfirmation::assertConfirmed($request, $weight); }
        self::fence($connection, $vendorId);
        return ['preflight' => $preflight, 'basalam_parcel_id' => $nativeId,
            'basalam_order_id' => $preflight['identity']['invoice_id'],
            'woo_order_id' => $order !== null ? (int) $order->get_id() : null,
            'weight_confirmation' => OrderWeightConfirmation::describe(['parcel' => ['weight' => $weight]], true)];
    }

    /** Re-read local ownership after HTTP; a valid earlier snapshot is not a permanent authorization. */
    private static function assertLocalIdentity(\WC_Order|BasalamParcelOrder|null $order, array $item, array $preflight): void
    {
        if ($order === null) {
            if (OrderParcelMapping::hasEvidence($item['id'], $item['external_id'])) {
                throw self::error('ارتباط یا نتیجه پرداخت محلی این مرسوله هنگام بررسی تغییر کرد؛ دوباره وضعیت را بررسی کنید.');
            }
            return;
        }
        $root = $order instanceof BasalamParcelOrder ? $order->getRoot() : $order;
        if (method_exists($root, 'read_meta_data')) { $root->read_meta_data(true); }
        $fresh = ParcelsListService::linkedOrderForParcel($item);
        $identity = $preflight['identity'];
        if ($fresh === null || (int) $fresh->get_id() !== (int) $order->get_id()
            || BasalamOrderContext::getInvoiceId((int) $order->get_id()) !== $identity['invoice_id']) {
            throw self::error('ارتباط ذخیره‌شده مرسوله یا سفارش اصلی هنگام بررسی تغییر کرد؛ پرداخت انجام نشد.');
        }
        if ($order instanceof BasalamParcelOrder) {
            if (!$fresh instanceof BasalamParcelOrder || $fresh->getIdentity() !== $order->getIdentity()
                || $fresh->getAllocation() !== $order->getAllocation()) {
                throw self::error('ارتباط یا اقلام ذخیره‌شده مرسوله هنگام بررسی تغییر کرد؛ پرداخت انجام نشد.');
            }
        } elseif ($fresh instanceof BasalamParcelOrder || $fresh->get_meta('_salam_gate_basalam_mapping') !== $identity) {
            throw self::error('ارتباط مرسوله قبلی با سفارش اصلی هنگام بررسی تغییر کرد؛ پرداخت انجام نشد.');
        }
        if (($reason = BasalamShippingPreflight::localStatusBlockReason($fresh)) !== null) {
            throw self::error($reason);
        }
        if ($fresh->get_meta('_salam_gate_pay_unknown') || $fresh->get_meta('_salam_gate_paid_at')
            || ParcelService::positiveId($fresh->get_meta('_salam_gate_invoice_id')) !== null) {
            throw self::error('پرداخت قبلی این مرسوله ثبت شده یا نیاز به بررسی دارد؛ دوباره پرداخت نکنید.');
        }
    }

    private static function vendorId(): ?int
    {
        return function_exists('syncBasalamSettings')
            ? ParcelService::positiveId(syncBasalamSettings()->getSettings('vendor_id')) : null;
    }

    private static function fence(string $connection, int $vendorId): void
    {
        ConnectionCache::assertCurrentIdentity($connection);
        if (self::vendorId() !== $vendorId) {
            throw self::error('غرفه متصل هنگام بررسی مرسوله تغییر کرد؛ دوباره وضعیت را بررسی کنید. پرداخت انجام نشد.');
        }
    }

    private static function error(string $message): SalamGateException
    {
        return new SalamGateException($message, 422, [], SalamGateException::KIND_VALIDATION);
    }
}
