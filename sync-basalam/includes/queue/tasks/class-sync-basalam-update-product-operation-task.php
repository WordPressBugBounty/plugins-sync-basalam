<?php
if (! defined('ABSPATH')) exit;

class sync_basalam_Update_Product_wp_bg_proccess_Task extends WP_Async_Background_Process
{
    protected $action = 'sync_basalam_plugin_update_product';
    protected $batch_size = 1;
    protected function task($item)
    {
        if ($item['type'] == 'update_product') {
            $operator = new sync_basalam_Admin_Product_Operations;
            $operator->update_exist_product($item['id']);
            return false;
        }

        if ($item['type'] == 'update_chunk') {

            $chunk_size = 100;
            $max_chunks_per_task = 10;

            $data = [
                'posts_per_page' => $chunk_size,
                'offset'         => $item['offset_id'] ? $item['offset_id'] : 0,
                'max_chunks'     => $max_chunks_per_task,
            ];

            (new sync_basalam_Chunk_Update_Products_Task())->schedule($data);
            return false;
        }
    }

    protected function complete()
    {
        parent::complete();
    }

    public function is_active()
    {
        return get_site_transient($this->identifier . '_process_lock') !== false;
    }
}
