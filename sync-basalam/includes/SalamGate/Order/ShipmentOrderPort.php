<?php
namespace SyncBasalam\SalamGate\Order;

defined('ABSPATH') || exit;

/** Shipment state only. No purchased-item, order-total, stock or parent-status mutation API. */
interface ShipmentOrderPort
{
    public function orderId(): int;
    public function nativeParcelId(): ?int;
    public function items(): array;
    public function meta(string $key);
    public function writeShipmentMeta(string $key, $value): void;
    public function saveShipment(): void;
    public function sourceStatus(): string;
    public function note(string $message): void;
    public function recordShipped(string $message): void;
}
