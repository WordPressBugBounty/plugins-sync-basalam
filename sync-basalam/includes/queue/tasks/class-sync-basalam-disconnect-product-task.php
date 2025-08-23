<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Disconnect_Product_Task extends WP_Async_Background_Process
{

    protected $action = 'sync_basalam_plugin_disconnect_product';
    protected $batch_size = 1;
    protected function task($item)
    {
        $operator = new sync_basalam_Admin_Product_Operations;
        $operator->disconnect_product($item);

        return false;
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
