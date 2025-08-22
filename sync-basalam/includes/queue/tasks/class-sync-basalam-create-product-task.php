<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Create_Product_Task extends sync_basalam_AbstractTask
{
    protected function get_hook_name()
    {
        return 'sync_basalam_plugin_create_product';
    }

    public function handle($args)
    {
        if ($args['type'] == 'create_product') {
            $operator = new sync_basalam_Admin_Product_Operations;
            $operator->create_new_product($args['id']);
        }

        if ($args['type'] == 'create_chunk') {

            $chunk_size = 100;
            $max_chunks_per_task = 10;

            $data = [
                'posts_per_page'        => $chunk_size,
                'offset'                => $args['offset_id'] ? $args['offset_id'] : 0,
                'max_chunks'            => $max_chunks_per_task,
                'include_out_of_stock'  => $args['include_out_of_stock'] ? $args['include_out_of_stock'] : false,
            ];

            (new sync_basalam_Chunk_Create_Products_Task())->schedule($data);
        }
    }

    public function schedule($data, $delay = null)
    {
        if (isset($data['id'])) {
            update_post_meta($data['id'], 'sync_basalam_product_sync_status', 'pending');
        }

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
