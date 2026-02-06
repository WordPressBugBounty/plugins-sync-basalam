<?php

namespace SyncBasalam\Services\Products\Discount;

defined('ABSPATH') || exit;

interface DiscountInterface
{
    public function apply($product): void;

    public function remove($product): void;
}
