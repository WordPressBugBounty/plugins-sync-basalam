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
        $class = new sync_basalam_Create_Product_wp_bg_proccess_Task();
        $class->push($args);
        $class->save();
        $class->dispatch();
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
