<?php

namespace SyncBasalam\Queue;

defined('ABSPATH') || exit;
class QueueManager
{
    private const GROUP_NAME = 'sync-basalam';

    public $taskName;

    public function __construct($taskName)
    {
        $this->taskName = $taskName;
    }

    public function scheduleSingleTask($args = [], $delay = 0)
    {
        $timestamp = time() + $delay;

        $this->setLastRunTimestamp($timestamp);

        return \WC()->queue()->schedule_single(
            $timestamp,
            $this->taskName,
            [$args],
            self::GROUP_NAME
        );
    }

    public function scheduleRecurringTask($intervalInSeconds, $args = [])
    {
        if (self::hasPendingTasks($this->taskName)) return false;

        $startTimestamp = time();

        $this->setLastRunTimestamp($startTimestamp);

        return \WC()->queue()->schedule_recurring(
            $startTimestamp,
            $intervalInSeconds,
            $this->taskName,
            [$args],
            self::GROUP_NAME
        );
    }

    protected function setLastRunTimestamp($timestamp)
    {
        return update_option($this->taskName . '_last_run', $timestamp);
    }

    public static function hasPendingTasks($taskName)
    {
        $pendingTasks = \WC()->queue()->search([
            'hook'   => $taskName,
            'status' => 'pending',
        ]);

        return !empty($pendingTasks);
    }

    public static function cancelAllTasksGroup($taskName)
    {
        \WC()->queue()->cancel_all($taskName);

        delete_option($taskName . '_last_run');
    }

    public static function countOfPendingTasks($taskName)
    {
        $pendingTasks = \WC()->queue()->search([
            'hook'     => $taskName,
            'status'   => 'pending',
            'per_page' => 5000,
        ]);

        return count($pendingTasks);
    }
}
