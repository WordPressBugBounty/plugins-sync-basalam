<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Cancel_Add_Products extends Sync_BasalamController
{
    public function __invoke()
    {
        sync_basalam_QueueManager::cancel_all_tasks_group('sync_basalam_plugin_create_product');
        sync_basalam_QueueManager::cancel_all_tasks_group('sync_basalam_plugin_chunk_create_products');
    }
}
