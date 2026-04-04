<?php

namespace SyncBasalam\Admin\Product\Data\Validators;

defined('ABSPATH') || exit;

class WeightValidator implements ValidatorInterface
{
    public function validate($product): void
    {
        $rawWeight = $product->get_weight();

        if (empty($rawWeight)) return;

        $weight = floatval(str_replace(',', '.', $rawWeight));
        $weightUnit = get_option('woocommerce_weight_unit');

        $weightInGrams = ($weightUnit === 'kg') ? $weight * 1000 : $weight;
        
        if ($weightInGrams < 0.001) throw new \InvalidArgumentException('وزن محصول نباید کمتر از یک هزارم گرم باشد.');
    }
}
