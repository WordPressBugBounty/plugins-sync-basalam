<?php

namespace SyncBasalam\Services\Products;

class ConnectSingleProductService
{
    public static function connectProductById($wooProductId, $syncBasalamProductId)
    {
        $existingPosts = get_posts([
            'post_type'  => 'product',
            'meta_query' => [
                [
                    'key'   => 'sync_basalam_product_id',
                    'value' => $syncBasalamProductId,
                ],
            ],
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ]);

        if (!$existingPosts) {
            update_post_meta($wooProductId, 'sync_basalam_product_id', $syncBasalamProductId);
            update_post_meta($wooProductId, 'sync_basalam_product_status', 2976);
            update_post_meta($wooProductId, 'sync_basalam_product_sync_status', 'synced');

            return true;
        } else return false;
    }
}
