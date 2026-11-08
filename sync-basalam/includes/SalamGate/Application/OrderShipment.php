<?php
namespace SyncBasalam\SalamGate\Application;

use SyncBasalam\SalamGate\Order\OrderContext;

defined('ABSPATH') || exit;

/** OrderShipment: explicit inputs, returned outcomes, no HTTP response termination. */
final class OrderShipment
{

    private array $input;
    private ExecutionContext $context;

    public function __construct(array $input, ?ExecutionContext $context = null)
    {
        $this->input = $input;
        $this->context = $context ?? ExecutionContext::individual();
    }

    public function prepareParcel(bool $refreshOrderReceiver = false): array
    {
        return (new PrepareShipment($this->input, $this->context))->prepareParcel($refreshOrderReceiver);
    }

    public function associateRemoteItems(array $remoteItems, array $localItems): array
    {
        return (new PrepareShipment($this->input, $this->context))->associateRemoteItems($remoteItems, $localItems);
    }

    public function productLinksForOrder($order): array
    {
        return (new PrepareShipment($this->input, $this->context))->productLinksForOrder($order);
    }

    public function upsertParcel(): array
    {
        return (new CreateOrderShipment($this->input, $this->context))->upsertParcel();
    }

    public function getQuotes(): array
    {
        return (new OrderCheckout($this->input, $this->context))->getQuotes();
    }

    public function selectQuote(): array
    {
        return (new OrderCheckout($this->input, $this->context))->selectQuote();
    }

    public function payParcel(): array
    {
        return (new OrderCheckout($this->input, $this->context))->payParcel();
    }

    public function resolvePayMethod(string $market, $raw): int
    {
        return (new OrderCheckout($this->input, $this->context))->resolvePayMethod($market, $raw);
    }

    public function applyPaySuccess(object $order, array $payBody, ?array $parcelItem, string $market = OrderContext::MARKET_BASALAM): array
    {
        return (new OrderCheckout($this->input, $this->context))->applyPaySuccess($order, $payBody, $parcelItem, $market);
    }

    public function getTrack(): array
    {
        return (new ShipmentReader($this->input, $this->context))->getTrack();
    }

    public function getLabel(): array
    {
        return (new ShipmentReader($this->input, $this->context))->getLabel();
    }

    public function labelStatus(): array
    {
        return (new ShipmentReader($this->input, $this->context))->labelStatus();
    }

    public function nativeParcels(): array
    {
        return (new ShipmentReader($this->input, $this->context))->nativeParcels();
    }

    public function numericInputErrors(array $input, ?string $market = null): array
    {
        return (new CreateOrderShipment($this->input, $this->context))->numericInputErrors($input, $market);
    }
}
