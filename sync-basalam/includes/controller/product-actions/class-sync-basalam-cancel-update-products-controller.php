<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Cancel_Update_Products extends Sync_BasalamController
{
    public function __invoke()
    {
        $args = array(
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => array(
                array(
                    'key'     => 'sync_basalam_product_sync_status',
                    'value'   => 'pending',
                    'compare' => '=',
                ),
                array(
                    'key'     => 'sync_basalam_product_id',
                    'compare' => 'EXISTS',
                ),

            ),
        );

        $products = get_posts($args);

        foreach ($products as $product) {
            update_post_meta($product->ID, 'sync_basalam_product_sync_status', 'ok');
        }
        sync_basalam_QueueManager::cancel_all_tasks_group('sync_basalam_plugin_update_product');
    }
}
