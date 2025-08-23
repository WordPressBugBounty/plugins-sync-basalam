<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Chunk_Create_Products_Task extends sync_basalam_AbstractTask
{
    protected function get_hook_name()
    {
        return 'sync_basalam_plugin_chunk_create_products';
    }

    public function handle($args)
    {
        $posts_per_page = $args['posts_per_page'] ?? 100;
        $offset = $args['offset'] ?? 0;
        $max_chunks = $args['max_chunks'] ?? 10;
        $include_out_of_stock = $args['include_out_of_stock'] ?? false;
        $current_chunk = 0;

        do {

            $batch_data = [
                'posts_per_page'        => $posts_per_page,
                'offset'                => $offset,
                'include_out_of_stock'  => $include_out_of_stock
            ];

            $product_ids = sync_basalam_Product_Queue_Manager::get_products_for_creation($batch_data);
            if (empty($product_ids)) {
                break;
            }
            foreach ($product_ids as $product_id) {
                sync_basalam_Product_Queue_Manager::add_to_schedule(new sync_basalam_Create_Product_Task(), ['type' => 'create_product', 'id' => $product_id]);
            }

            sync_basalam_Product_Queue_Manager::add_to_schedule(new sync_basalam_Create_Product_Task(), ['type' => 'create_chunk', 'offset_id' => ($offset + $posts_per_page), 'include_out_of_stock' => $include_out_of_stock]);

            $offset += $posts_per_page;
            $current_chunk++;
        } while ($current_chunk < $max_chunks && count($product_ids) === $posts_per_page);
    }

    public function schedule($data, $delay = null)
    {

        if ($delay == null) {
            if ($this->get_last_run_timestamp() > time()) {
                $delay = $this->get_last_run_timestamp() - time() + 60;
            } else {
                $delay = 60;
            }
        }

        return $this->queue_manager->schedule_single_task($data, $delay);
    }
}
