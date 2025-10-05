<?php
defined('ABSPATH') || exit;

require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'queue/class-sync-basalam-abstract-task.php';

class Sync_Basalam_Discount_Processor_Task extends Sync_basalam_AbstractTask
{
    public function handle($args)
    {
        $this->run();
    }

    public function run()
    {
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'services/class-sync-basalam-discount-task-model.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'services/class-sync-basalam-discount-task-processor.php';
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'services/class-sync-basalam-discount-manager.php';

        $processor = new Sync_Basalam_Discount_Task_Processor();
        
        
        $result = $processor->process_single_discount_group();
        
    }

    protected function get_hook_name()
    {
        return 'sync_basalam_discount_processor_task';
    }

    public function get_task_name()
    {
        return 'sync_basalam_discount_processor_task';
    }
}