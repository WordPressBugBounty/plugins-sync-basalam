<?php

namespace SyncBasalam\Actions\Controller\OrderActions;

use SyncBasalam\JobManager;
use SyncBasalam\Actions\Controller\ActionController;

defined('ABSPATH') || exit;

class CancelFetchOrders extends ActionController
{
    public function __invoke()
    {
        $jobManager = JobManager::getInstance();

        $jobManager->deleteJob(['job_type' => 'sync_basalam_fetch_orders', 'status' => 'pending']);
        $jobManager->deleteJob(['job_type' => 'sync_basalam_fetch_orders', 'status' => 'processing']);

        wp_send_json_success([
            'message' => 'فرآیند همگام‌سازی سفارشات لغو شد.'
        ]);
    }
}
