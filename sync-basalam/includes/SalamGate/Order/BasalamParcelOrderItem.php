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
    public function get_name() { return $this->item->get_name(); }
    public function get_product_id() { return $this->item->get_product_id(); }
    public function get_variation_id() { return $this->item->get_variation_id(); }
    public function get_subtotal() { return $this->item->get_subtotal(); }
    public function get_meta($key = '', $single = true, $context = 'view') { return $this->item->get_meta($key, $single, $context); }
}
