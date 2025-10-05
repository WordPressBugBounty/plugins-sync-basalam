<?php

if (! defined('ABSPATH')) exit;

class Sync_basalam_Chunk_Create_Products_Task extends WP_Async_Background_Process
{
    protected $action = 'sync_basalam_create_products_task';
    protected $batch_size = 1;

    protected function task($args)
    {
        $job_manager = new SyncBasalamJobManager();

        $posts_per_page = 200;
        $offset = get_option('last_offset_create_products') ?? 0;
        $include_out_of_stock = $args['include_out_of_stock'] ?? false;

        $batch_data = [
            'posts_per_page'        => $posts_per_page,
            'offset'                => $offset,
            'include_out_of_stock'  => $include_out_of_stock
        ];

        $product_ids = sync_basalam_Product_Queue_Manager::get_products_for_creation($batch_data);

        if (!$product_ids) {
            delete_option('last_offset_create_products');
            return false;
        }

        
        foreach ($product_ids as $product_id) {
            $job_manager->create_job(
                'sync_basalam_create_single_product',
                'pending',
                json_encode(['product_id' => $product_id])
            );
        }

        
        update_option('last_offset_create_products', $offset + $posts_per_page);

        
        $next_batch_data = json_encode([
            'offset' => $offset + $posts_per_page,
            'include_out_of_stock' => $include_out_of_stock
        ]);

        $job_manager->create_job(
            'sync_basalam_create_all_products',
            'pending',
            $next_batch_data
        );

        return false;
    }

    protected function complete()
    {
        parent::complete();
    }
}
