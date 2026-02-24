<?php

namespace SyncBasalam\Actions\Controller\OrderActions;

use SyncBasalam\Actions\Controller\ActionController;
use SyncBasalam\JobManager;

defined('ABSPATH') || exit;

class FetchUnsyncOrders extends ActionController
{
    public function __invoke()
    {
        $jobManager = JobManager::getInstance();

        $hasRunningJob = $jobManager->getCountJobs([
            'job_type' => 'sync_basalam_fetch_orders',
            'status' => ['pending', 'processing']
        ]) > 0;

        if ($hasRunningJob) {
            wp_send_json_error([
                'message' => 'یک فرآیند دریافت سفارشات در حال اجرا است. لطفاً صبر کنید.'
            ], 400);
            return;
        }

        $day = isset($_POST['days']) ? intval($_POST['days']) : 7;

        if ($day < 1 || $day > 30) $day = 7;

        $jobManager->createJob(
            'sync_basalam_fetch_orders',
            'pending',
            json_encode([
                'cursor' => null,
                'day' => $day
            ])
        );

        wp_send_json_success([
            'message' => "فرآیند دریافت سفارشات {$day} روز اخیر شروع شد.",
            'day' => $day
        ]);
    }
}
