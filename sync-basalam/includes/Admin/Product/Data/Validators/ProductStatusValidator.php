<?php

namespace SyncBasalam\Admin\Product\Data\Validators;

defined('ABSPATH') || exit;

class ProductStatusValidator implements ValidatorInterface
{
    public function validate($product): void
    {
        if ($product->get_status() !== 'publish') {
            throw new \InvalidArgumentException(
                sprintf('Product %d is not published (current status: %s)',
                    $product->get_id(),
                    $product->get_status()
                )
            );
        }
    }
}