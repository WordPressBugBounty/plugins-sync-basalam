<?php
if (!defined('ABSPATH')) exit;

class Sync_Basalam_Simple_Discount_Strategy implements Sync_Basalam_Discount_Strategy
{
    private $discount_service;

    public function __construct(Sync_Basalam_Discount_Manager $discount_service)
    {
        $this->discount_service = $discount_service;
    }

    public function apply(\WC_Product $product): void
    {
        $basalam_id = get_post_meta($product->get_id(), 'sync_basalam_product_id', true);
        if (!$basalam_id) return;

        $regular = $product->get_regular_price();
        $sale = $product->get_sale_price();

        if (!$regular || !$sale) return;

        $discount = $this->discount_service->calculate_discount_percent($regular, $sale);
        $this->discount_service->apply($discount, [$basalam_id], null, null);
    }

    public function remove(\WC_Product $product): void
    {
        $basalam_id = get_post_meta($product->get_id(), 'sync_basalam_product_id', true);
        if ($basalam_id) {
            $this->discount_service->remove([$basalam_id], null);
        }
    }
}
