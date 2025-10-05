<?php
if (! defined('ABSPATH')) exit;

class sync_basalam_Create_Product_wp_bg_proccess_Task extends WP_Async_Background_Process
{
    protected $action = 'sync_basalam_plugin_create_product';
    protected $batch_size = 1;
    protected function task($item)
    {
        if ($item['type'] == 'create_product') {
            $operator = new sync_basalam_Admin_Product_Operations;
            $operator->create_new_product($item['id'], null);
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
