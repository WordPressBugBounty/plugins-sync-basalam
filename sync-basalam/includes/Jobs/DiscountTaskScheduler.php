<?php

namespace SyncBasalam\Jobs;

use SyncBasalam\Services\Products\Discount\DiscountTaskProcessor;

defined('ABSPATH') || exit;

class DiscountTaskScheduler
{
    private static $instance = null;

    private function __construct()
    {
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    public function process(): void
    {
        $cacheKey = 'sync_basalam_discount_tasks_last_run';
        $cacheThreshold = 30;

        $lastRun = floatval(get_option($cacheKey, 0));
        $now = microtime(true);

        if (($now - $lastRun) >= $cacheThreshold) {
            
            update_option($cacheKey, microtime(true), false);

            $discountProcessor = new DiscountTaskProcessor();
            $discountProcessor->processDiscountTasks();
        }
    }
}
