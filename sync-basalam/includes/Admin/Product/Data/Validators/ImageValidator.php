<?php

namespace SyncBasalam\Admin\Product\Data\Validators;

use SyncBasalam\Utilities\ProductMetaKey;

defined('ABSPATH') || exit;

class ImageValidator implements ValidatorInterface
{
    public function validate($product): void
    {
        $basalamProductId = get_post_meta($product->get_id(), ProductMetaKey::basalamProductId(), true);
        
        if (!empty($basalamProductId)) return;

        if (!$product->get_image_id()) {
            throw new \InvalidArgumentException('محصول فاقد تصویر است.');
        }
    }
}
