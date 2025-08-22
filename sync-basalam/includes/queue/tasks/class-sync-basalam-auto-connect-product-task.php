<?php

if (! defined('ABSPATH')) exit;

class Sync_basalam_Auto_Connect_Product_Task extends WP_Background_Process
{

    protected $action = 'sync_basalam_plugin_connect_auto_product';
    protected $batch_size = 1;
    protected function task($item)
    {
        $page = is_numeric($item) ? (int)$item : 1;

        $BasalamChecker = new sync_basalam_Auto_Connect_Products();
        $BasalamChecker->check_same_product(null, $page);
        return false;
    }

    protected function complete()
    {
        parent::complete();

        $current = get_option('sync_basalam_auto_connect_last_page_checked', 1);

        $next = $current + 1;

        if (get_option('sync_basalam_auto_connect_all_pages') > $current && get_option('sync_basalam_cancel_auto_connect_task') != 1) {
            update_option('sync_basalam_auto_connect_last_page_checked', $next);

            $this->push_to_queue($next);
            $this->save()->dispatch();
        } else {
            delete_option('sync_basalam_auto_connect_last_page_checked');
            delete_option('sync_basalam_auto_connect_all_pages');
            delete_option('sync_basalam_cancel_auto_connect_task');
        }
    }

    public function is_active()
    {
        return get_site_transient($this->identifier . '_process_lock') !== false;
    }
}
