<?php

namespace SyncBasalam\Services\Products\Discount;

use SyncBasalam\Utilities\ProductMetaKey;

defined('ABSPATH') || exit;

class SimpleProductDiscount implements DiscountInterface
{
    private $discountService;

    public function __construct(DiscountManager $discountService)
    {
        $this->discountService = $discountService;
    }

    public function apply($product): void
    {
        $basalam_id = get_post_meta($product->get_id(), ProductMetaKey::basalamProductId(), true);
        if (!$basalam_id) return;

        $regular = $product->get_regular_price();
        $sale = $product->get_sale_price();

        if (!$regular || !$sale) return;

        $discount = $this->discountService->calculateDiscountPercent($regular, $sale);
        $this->discountService->apply($discount, [$basalam_id], null, null);
    }

    public function remove($product): void
    {
        $basalam_id = get_post_meta($product->get_id(), ProductMetaKey::basalamProductId(), true);
        if ($basalam_id) {
            $this->discountService->remove([$basalam_id], null);
        }
    }
}
