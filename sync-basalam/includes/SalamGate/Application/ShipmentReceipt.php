<?php
namespace SyncBasalam\SalamGate\Application;

use SyncBasalam\SalamGate\Contracts\ParcelStatuses;
use SyncBasalam\SalamGate\Contracts\ParcelViewNormalizer;
use SyncBasalam\SalamGate\Order\OrderContext;
use SyncBasalam\SalamGate\Services\ParcelService;
use SyncBasalam\SalamGate\Settings\SalamGateSettings;
use SyncBasalam\SalamGate\Utils\Units;

defined('ABSPATH') || exit;

/** Applies confirmed shipment evidence through the narrow order port. */
final class ShipmentReceipt
{
    public function apply(\SyncBasalam\SalamGate\Order\ShipmentOrderPort $order, array $payBody, ?array $parcelItem, string $market = OrderContext::MARKET_BASALAM): array
    {
        $statusId = (int) ($parcelItem['status_id'] ?? 0);
        $trackingCode = (string) ($parcelItem['tracking_code'] ?? '');

        // وضعیت معتبر فقط از پاسخ لیست با رسید پستی — پیش‌فرض/جعل ممنوع.
        $confirmed = $parcelItem !== null && ParcelStatuses::hasReceipt($statusId);

        $invoiceId = isset($payBody['invoice']['id']) ? (int) $payBody['invoice']['id'] : 0;
        if ($confirmed && !ParcelService::recordReceiptOutcome($parcelItem, $invoiceId > 0 ? $invoiceId : null)) {
            $confirmed = false;
        }
        $deliveryNode = (string) ($payBody['delivery_node']['title'] ?? '');
        if ($deliveryNode === '') {
            $deliveryNode = (string) ($parcelItem['delivery_node'] ?? '');
        }

        // رسید پرداخت همیشه ثبت می‌شود (ناشی از پاسخ خود pay):
        if ($invoiceId > 0) {
            $order->writeShipmentMeta('_salam_gate_invoice_id', $invoiceId);
        }
        $invoiceStatus = $payBody['invoice']['status'] ?? null;
        $shippingCostRial = $invoiceStatus === null || ParcelService::positiveId($invoiceStatus) === 2
            ? ParcelViewNormalizer::invoiceAmountRial($payBody) : null;
        // A reconciled timeout has no pay body. Use its paid raw invoice charge, never a quote
        // or a reverse conversion of display toman; otherwise preserve the existing metadata.
        if ($shippingCostRial === null && $confirmed && ($parcelItem['invoice_status'] ?? null) === 2
            && is_int($parcelItem['shipping_cost_rial'] ?? null) && $parcelItem['shipping_cost_rial'] >= 0) {
            $shippingCostRial = $parcelItem['shipping_cost_rial'];
        }
        if ($shippingCostRial !== null) { $order->writeShipmentMeta('_salam_gate_shipping_cost_rial', $shippingCostRial); }
        $order->writeShipmentMeta('_salam_gate_paid_at', current_time('mysql'));

        if ($confirmed) {
            $order->writeShipmentMeta('_salam_gate_pay_unknown', '');
            $order->writeShipmentMeta('_salam_gate_tracking_code', $trackingCode);
            $order->writeShipmentMeta('_salam_gate_status', $statusId);
            $order->writeShipmentMeta('_salam_gate_delivery_node', $deliveryNode);

            if ($market === OrderContext::MARKET_BASALAM) {
                // سازگاری با پلاگین پایه (نمایش کد رهگیری در بقیه UIهای ووسلام)
                $order->writeShipmentMeta('_basalam_order_tracking_code', $trackingCode);
            }
        }

        $order->saveShipment();

        $settings = SalamGateSettings::instance();
        $autoShip = (int) $settings->get(SalamGateSettings::KEY_AUTO_SET_SHIPPED) === 1;
        $currentStatus = $order->sourceStatus();

        if ($confirmed && $trackingCode !== ''
            && $market === OrderContext::MARKET_BASALAM
            && $autoShip
            && in_array($currentStatus, ['bslm-wait-vendor', 'bslm-preparation'], true)
        ) {
            $order->recordShipped('ارسال با سلام‌گیت ثبت شد. کد رهگیری: ' . $trackingCode);
        } elseif ($confirmed && $trackingCode !== '') {
            $order->note('پرداخت ارسال با سلام‌گیت انجام شد. کد رهگیری: ' . $trackingCode);
        } else {
            // پرداخت انجام شد اما وضعیت پستی هنوز نامشخص است — هیچ موفقیتی جعل نمی‌شود.
            $order->note('پرداخت هزینه ارسال سلام‌گیت ثبت شد'
                . ($invoiceId > 0 ? ' (رسید #' . $invoiceId . ')' : '')
                . ' اما وضعیت بارکد پستی هنوز تأیید نشد؛ از فهرست مرسولات دوباره بررسی کنید.');
        }

        return [
            'parcel_id'         => (int) ($parcelItem['id'] ?? ((int) ($order->meta('_salam_gate_parcel_id')))),
            'tracking_code'     => $confirmed ? $trackingCode : '',
            'status_id'         => $confirmed ? $statusId : 0,
            'status_title'      => $confirmed ? (string) ($parcelItem['status_title'] ?? '') : 'در حال بررسی',
            'confirmed'         => $confirmed,
            'invoice_id'        => $invoiceId,
            'final_total_rial'  => $shippingCostRial,
            'final_total_toman' => $shippingCostRial !== null ? Units::toToman($shippingCostRial) : null,
            'delivery_node'     => $deliveryNode,
            'post_tracking_url' => $confirmed && $trackingCode !== '' ? ParcelService::postTrackingUrl($trackingCode) : null,
        ];
    }
}
