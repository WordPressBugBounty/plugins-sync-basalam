<?php
if (!defined('ABSPATH')) exit;

class Sync_Basalam_Variable_Discount_Strategy implements Sync_Basalam_Discount_Strategy
{
    private $discount_service;

    public function __construct(Sync_Basalam_Discount_Manager $discount_service)
    {
        $this->discount_service = $discount_service;
    }

    public function apply(\WC_Product $product): void
    {
        foreach ($product->get_children() as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation) continue;

            $basalam_id = get_post_meta($variation_id, 'sync_basalam_variation_id', true);
            if (!$basalam_id) continue;

            $regular = $variation->get_regular_price();
            $sale = $variation->get_sale_price();

            if (!$regular || !$sale) continue;

            $discount = $this->discount_service->calculate_discount_percent($regular, $sale);
            $this->discount_service->apply($discount, null, [$basalam_id], null);
        }
    }

    public function remove(\WC_Product $product): void
    {
        $basalam_ids = [];
        foreach ($product->get_children() as $variation_id) {
            $basalam_id = get_post_meta($variation_id, 'sync_basalam_variation_id', true);
            if ($basalam_id) {
                $basalam_ids[] = $basalam_id;
            }
        }
        if (!empty($basalam_ids)) {
            $this->discount_service->remove(null, $basalam_ids);
        }
    }
}
