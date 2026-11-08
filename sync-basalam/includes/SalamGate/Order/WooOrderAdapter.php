<?php
namespace SyncBasalam\SalamGate\Order;

defined('ABSPATH') || exit;

final class WooOrderAdapter implements ShipmentOrderPort
{
    private \WC_Order $order;
    public function __construct(\WC_Order $order) { $this->order = $order; }
    public function orderId(): int { return (int) $this->order->get_id(); }
    public function nativeParcelId(): ?int { return null; }
    public function items(): array { return $this->order->get_items(); }
    public function meta(string $key) { return $this->order->get_meta($key); }
    public function writeShipmentMeta(string $key, $value): void
    {
        if (strpos($key, '_salam_gate_') !== 0 && $key !== '_basalam_order_tracking_code') throw new \LogicException('Not shipment metadata.');
        $this->order->update_meta_data($key, $value);
    }
    public function saveShipment(): void { $this->order->save(); }
    public function sourceStatus(): string { return $this->order->get_status(); }
    public function note(string $message): void { $this->order->add_order_note($message); }
    public function recordShipped(string $message): void
    {
        // Legacy single-parcel Basalam only. Website orders retain their own lifecycle.
        if (OrderContext::marketFor($this->orderId()) === 'basalam' && !BasalamParcelOrder::hasScopes($this->order)) {
            $this->order->update_status('bslm-shipping', $message);
        } else { $this->note($message); }
    }
}
