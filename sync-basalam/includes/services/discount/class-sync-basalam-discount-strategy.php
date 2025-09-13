<?php
if (!defined('ABSPATH')) exit;

interface Sync_Basalam_Discount_Strategy {
    public function apply(\WC_Product $product): void;
    public function remove(\WC_Product $product): void;
}
