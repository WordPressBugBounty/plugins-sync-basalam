<?php

namespace SyncBasalam\Admin\Product\Data\Validators;

defined('ABSPATH') || exit;

class ProductExistenceValidator implements ValidatorInterface
{
    public function validate($product): void
    {
        if (!$product) {
            throw new \InvalidArgumentException('Product does not exist');
        }
    }
}