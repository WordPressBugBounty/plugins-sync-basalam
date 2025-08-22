<?php
class Sync_basalam_connect_product_service
{
    public static function connect_product_by_id($woo_product_id, $sync_basalam_product_id)
    {
        $existing_posts = get_posts([
            'post_type'  => 'product',
            'meta_query' => [
                [
                    'key'   => 'sync_basalam_product_id',
                    'value' => $sync_basalam_product_id,
                ]
            ],
            'posts_per_page' => 1,
            'fields' => 'ids',
        ]);

        if (!$existing_posts) {
            update_post_meta($woo_product_id, 'sync_basalam_product_id', $sync_basalam_product_id);
            update_post_meta($woo_product_id, 'sync_basalam_product_status', 2976);
            update_post_meta($woo_product_id, 'sync_basalam_product_sync_status', 'ok');
            return true;
        } else {
            return false;
        }
    }
}
