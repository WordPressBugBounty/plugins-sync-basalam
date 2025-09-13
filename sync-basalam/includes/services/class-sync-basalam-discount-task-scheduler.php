<?php
defined('ABSPATH') || exit;

class Sync_Basalam_Discount_Task_Scheduler
{
    private $task_model;
    private $queue_manager;
    
    private const PROCESSOR_TASK_NAME = 'sync_basalam_discount_processor_task';

    public function __construct()
    {
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'models/class-sync-basalam-discount-task.php';
        $this->task_model = new Sync_Basalam_Discount_Task();
        $this->queue_manager = new Sync_basalam_QueueManager(self::PROCESSOR_TASK_NAME);
    }

    public function schedule_discount_task($product_id, $discount_percent, $active_days, $variation_id = null, $delay_minutes = 0, $action = 'apply')
    {
        $scheduled_at = null;
        if ($delay_minutes > 0) {
            $scheduled_at = date('Y-m-d H:i:s', current_time('timestamp') + ($delay_minutes * 60));
        }

        return $this->task_model->create([
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'discount_percent' => $discount_percent,
            'active_days' => $active_days,
            'action' => $action,
            'scheduled_at' => $scheduled_at,
            'status' => Sync_Basalam_Discount_Task::STATUS_PENDING
        ]);
    }

    public function schedule_multiple_discount_tasks($tasks)
    {
        $results = [];
        foreach ($tasks as $task) {
            $result = $this->schedule_discount_task(
                $task['product_id'],
                $task['discount_percent'],
                $task['active_days'],
                isset($task['variation_id']) ? $task['variation_id'] : null,
                isset($task['delay_minutes']) ? $task['delay_minutes'] : 0,
                isset($task['action']) ? $task['action'] : 'apply'
            );
            $results[] = $result;
        }
        return $results;
    }

    public function start_processor()
    {
        if (!$this->queue_manager->has_pending_tasks(self::PROCESSOR_TASK_NAME)) {
            $this->queue_manager->schedule_recurring_task(60);
            return true;
        }
        return false;
    }

    public function stop_processor()
    {
        $this->queue_manager->cancel_all_tasks_group(self::PROCESSOR_TASK_NAME);
    }

    public function get_task_statistics()
    {
        return [
            'pending' => $this->task_model->get_tasks_count_by_status(Sync_Basalam_Discount_Task::STATUS_PENDING),
            'processing' => $this->task_model->get_tasks_count_by_status(Sync_Basalam_Discount_Task::STATUS_PROCESSING),
            'completed' => $this->task_model->get_tasks_count_by_status(Sync_Basalam_Discount_Task::STATUS_COMPLETED),
            'failed' => $this->task_model->get_tasks_count_by_status(Sync_Basalam_Discount_Task::STATUS_FAILED),
            'total' => $this->task_model->get_tasks_count_by_status()
        ];
    }

    public function get_pending_tasks_grouped()
    {
        return $this->task_model->get_grouped_pending_tasks();
    }
}