<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Cancel_Debug extends Sync_BasalamController
{
    public function __invoke()
    {
        sync_basalam_QueueManager::cancel_all_tasks_group('sync_basalam_plugin_debug');
    }
}
