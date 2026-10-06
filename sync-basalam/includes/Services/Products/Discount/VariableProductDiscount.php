<?php

namespace SyncBasalam\Services\Products\Discount;

defined('ABSPATH') || exit;

class VariableProductDiscount implements DiscountInterface
{
    private $discountService;

    public function __construct(DiscountManager $discountService)
    {
        $this->discountService = $discountService;
    }

    public function apply($product): void
    {
        foreach ($product->get_children() as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation) continue;
            $basalam_ids = array_filter((array) get_post_meta($variation_id, 'sync_basalam_variation_id', false));
            if (!$basalam_ids) continue;

            $regular = $variation->get_regular_price();
            $sale = $variation->get_sale_price();

            if (!$regular || !$sale) continue;

            $discount = $this->discountService->calculateDiscountPercent($regular, $sale);
            $this->discountService->apply($discount, null, $basalam_ids, null);
        }
    }

    public function remove($product): void
    {
        $basalam_ids = [];
        foreach ($product->get_children() as $variation_id) {
            $basalam_ids = array_merge($basalam_ids, array_filter((array) get_post_meta($variation_id, 'sync_basalam_variation_id', false)));
        }
        if (!empty($basalam_ids)) $this->discountService->remove(null, $basalam_ids);
    }
}
