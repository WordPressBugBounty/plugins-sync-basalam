<?php

namespace SyncBasalam\Services\Products;

use SyncBasalam\Utilities\ProductMetaKey;

class ConnectSingleProductService
{
    public static function connectProductById($wooProductId, $syncBasalamProductId)
    {
        $existingPosts = get_posts([
            'post_type'  => 'product',
            'meta_query' => [
                [
                    'key'   => ProductMetaKey::basalamProductId(),
                    'value' => $syncBasalamProductId,
                ],
            ],
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ]);

        if (!$existingPosts) {
            update_post_meta($wooProductId, ProductMetaKey::basalamProductId(), $syncBasalamProductId);
            update_post_meta($wooProductId, ProductMetaKey::basalamProductStatus(), 2976);
            update_post_meta($wooProductId, ProductMetaKey::basalamProductSyncStatus(), 'synced');

            return true;
        } else return false;
    }
}
