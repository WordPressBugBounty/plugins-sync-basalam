<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_QueueManager
{
    private const GROUP_NAME = 'basalam-plugin';

    public $task_name;

    public function __construct($task_name)
    {
        $this->task_name = $task_name;
    }

    public function schedule_single_task($args = [], $delay = 0)
    {
        $timestamp = time() + $delay;

        $this->set_last_run_timestamp($timestamp);

        return WC()->queue()->schedule_single(
            $timestamp,
            $this->task_name,
            [$args],
            self::GROUP_NAME
        );
    }
    public function schedule_recurring_task($interval_in_seconds, $args = [])
    {
        $start_timestamp = time();

        $this->set_last_run_timestamp($start_timestamp);

        return WC()->queue()->schedule_recurring(
            $start_timestamp,
            $interval_in_seconds,
            $this->task_name,
            [$args],
            self::GROUP_NAME
        );
    }

    protected function set_last_run_timestamp($timestamp)
    {
        return update_option($this->task_name . '_last_run', $timestamp);
    }

    public static function has_pending_tasks($task_name)
    {
        $pending_tasks = WC()->queue()->search([
            'hook' => $task_name,
            'status' => 'pending'
        ]);

        return !empty($pending_tasks);
    }
    public static function cancel_all_tasks_group($task_name)
    {
        WC()->queue()->cancel_all($task_name);
        delete_option($task_name . '_last_run');
    }
    public static function count_of_pending_tasks($task_name)
    {
        $pending_tasks = WC()->queue()->search([
            'hook' => $task_name,
            'status' => 'pending',
            'per_page' => 5000
        ]);

        return count($pending_tasks);
    }
}
