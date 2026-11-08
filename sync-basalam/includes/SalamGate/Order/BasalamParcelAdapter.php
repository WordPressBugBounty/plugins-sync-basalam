<?php
namespace SyncBasalam\SalamGate\Order;

defined('ABSPATH') || exit;

final class BasalamParcelAdapter implements ShipmentOrderPort
{
    private BasalamParcelOrder $parcel;
    public function __construct(BasalamParcelOrder $parcel) { $this->parcel = $parcel; }
    public function orderId(): int { return (int) $this->parcel->get_id(); }
    public function nativeParcelId(): ?int { return $this->parcel->getNativeParcelId(); }
    public function items(): array { return $this->parcel->get_items(); }
    public function meta(string $key) { return $this->parcel->get_meta($key); }
    public function writeShipmentMeta(string $key, $value): void
    {
        if (strpos($key, '_salam_gate_') !== 0 && $key !== '_basalam_order_tracking_code') throw new \LogicException('Not shipment metadata.');
        $this->parcel->update_meta_data($key, $value);
    }
    public function saveShipment(): void { $this->parcel->save(); }
    public function sourceStatus(): string { return $this->parcel->get_status(); }
    public function note(string $message): void { $this->parcel->add_order_note($message); }
    public function recordShipped(string $message): void { $this->note($message); }
}
