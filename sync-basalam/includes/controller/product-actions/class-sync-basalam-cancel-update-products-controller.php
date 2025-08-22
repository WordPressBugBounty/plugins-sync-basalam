<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Cancel_Update_Products extends Sync_BasalamController
{
    public function __invoke()
    {
        sync_basalam_QueueManager::cancel_all_tasks_group('sync_basalam_plugin_chunk_update_products');
        sync_basalam_QueueManager::cancel_all_tasks_group('sync_basalam_plugin_update_product');
    }
}
