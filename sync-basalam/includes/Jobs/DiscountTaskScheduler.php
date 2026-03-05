<?php

namespace SyncBasalam\Jobs;


defined('ABSPATH') || exit;

class DiscountTaskScheduler
{
    private $discountProcessor;

    public function __construct($discountProcessor)
    {
        $this->discountProcessor = $discountProcessor;
    }

    public function process(): void
    {
        $cacheKey = 'sync_basalam_discount_tasks_last_run';
        $cacheThreshold = 30;

        $lastRun = floatval(get_option($cacheKey, 0));
        $now = microtime(true);

        if (($now - $lastRun) >= $cacheThreshold) {
            update_option($cacheKey, microtime(true), false);
            $this->discountProcessor->processDiscountTasks();
        }
    }
}
