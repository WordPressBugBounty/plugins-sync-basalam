<?php

namespace SyncBasalam\SalamGate\Admin\Ajax;

use SyncBasalam\SalamGate\Order\OrderContext;
use SyncBasalam\SalamGate\Services\StatusSyncService;
use SyncBasalam\SalamGate\Application\OrderShipment;

defined('ABSPATH') || exit;

/** WordPress HTTP adapter; public action names remain backward compatible. */
class ParcelActions
{


    public static function references(): void
    {
        self::guard();
        ActionResponder::run(static fn (): array => (new \SyncBasalam\SalamGate\Application\ParcelReferences())->load());
    }

    public static function prepareParcel(bool $refreshOrderReceiver = false): void
    {
        self::guard();
        $workflow = new OrderShipment($_POST);
        ActionResponder::run(static fn (): array => $workflow->prepareParcel($refreshOrderReceiver));
    }

    public static function associateRemoteItems(array $remoteItems, array $localItems): array
    {
        return (new OrderShipment([]))->associateRemoteItems($remoteItems, $localItems);
    }

    public static function productLinksForOrder($order): array
    {
        return (new OrderShipment([]))->productLinksForOrder($order);
    }

    public static function upsertParcel(): void
    {
        self::guard();
        $workflow = new OrderShipment($_POST);
        ActionResponder::run(static fn (): array => $workflow->upsertParcel());
    }

    public static function getQuotes(): void
    {
        self::guard();
        $workflow = new OrderShipment($_POST);
        ActionResponder::run(static fn (): array => $workflow->getQuotes());
    }

    public static function selectQuote(): void
    {
        self::guard();
        $workflow = new OrderShipment($_POST);
        ActionResponder::run(static fn (): array => $workflow->selectQuote());
    }

    public static function payParcel(): void
    {
        self::guard();
        $workflow = new OrderShipment($_POST);
        ActionResponder::run(static fn (): array => $workflow->payParcel());
    }

    public static function resolvePayMethod(string $market, $raw): int
    {
        return (new OrderShipment([]))->resolvePayMethod($market, $raw);
    }

    public static function applyPaySuccess(object $order, array $payBody, ?array $parcelItem, string $market = OrderContext::MARKET_BASALAM): array
    {
        return (new OrderShipment([]))->applyPaySuccess($order, $payBody, $parcelItem, $market);
    }

    public static function getTrack(): void
    {
        self::guard();
        $workflow = new OrderShipment($_POST);
        ActionResponder::run(static fn (): array => $workflow->getTrack());
    }

    public static function getLabel(): void
    {
        self::guard();
        $workflow = new OrderShipment($_POST);
        ActionResponder::run(static fn (): array => $workflow->getLabel());
    }

    public static function labelStatus(): void
    {
        self::guard();
        $workflow = new OrderShipment($_POST);
        ActionResponder::run(static fn (): array => $workflow->labelStatus());
    }

    public static function refreshMetabox(): void
    {
        self::guard();

        $orderId = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        $order = $orderId > 0 ? wc_get_order($orderId) : null;

        if (!$order) {
            ResponseBoundary::error(['message' => 'سفارش یافت نشد.']);
        }

        // Backfill an absent status from the authenticated, identity-checked list only.
        // The status read cannot create a parcel, select a rate or retry a payment.
        (new StatusSyncService())->syncMissingOrder($orderId);
        $order = wc_get_order($orderId);

        ob_start();
        $template = dirname(__DIR__, 4) . '/templates/SalamGate/order-metabox-box.php';
        if (file_exists($template)) {
            include $template;
        }
        $html = (string) ob_get_clean();

        // List actions reuse this guarded read; cached rendering must not create
        // a parcel or infer a payable state from the physical-shipping badge.
        $rowHtml = '';
        ob_start();
        \SyncBasalam\SalamGate\Admin\OrderShippingStatus::render(
            \SyncBasalam\SalamGate\Admin\OrderShippingStatus::COLUMN, $order
        );
        \SyncBasalam\SalamGate\Admin\OrderShippingStatus::render(
            \SyncBasalam\SalamGate\Admin\OrderShippingStatus::ACTION_COLUMN, $order
        );
        $rowHtml = (string) ob_get_clean();
        ResponseBoundary::success(['html' => $html, 'order_id' => $orderId,
            'list_action' => \SyncBasalam\SalamGate\Admin\OrderListAction::forOrder($order), 'row_html' => $rowHtml]);
    }

    public static function nativeParcels(): void
    {
        self::guard();
        $workflow = new OrderShipment($_POST);
        ActionResponder::run(static fn (): array => $workflow->nativeParcels());
    }

    public static function numericInputErrors(array $input, ?string $market = null): array
    {
        return (new OrderShipment([]))->numericInputErrors($input, $market);
    }

    private static function guard(): void
    {
        ActionGuard::forController(self::class, null);
    }
}
