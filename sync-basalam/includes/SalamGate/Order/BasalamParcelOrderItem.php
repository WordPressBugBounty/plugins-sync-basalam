<?php

namespace SyncBasalam\SalamGate\Order;

defined('ABSPATH') || exit;

/** Immutable allocation view. Shipping cannot rewrite purchased quantities or prices. */
final class BasalamParcelOrderItem
{
    private $item;
    private int $quantity;

    public function __construct($item, int $quantity)
    {
        $this->item = $item;
        $this->quantity = $quantity;
    }
    public function get_id() { return $this->item->get_id(); }
    public function get_quantity() { return $this->quantity; }
    public function get_product() { return $this->item->get_product(); }
    public function __call($method, $arguments)
    {
        if (strpos($method, 'get_') === 0) { return $this->item->$method(...$arguments); }
        throw new \LogicException('Native parcel item allocations are read-only.');
    }
}
