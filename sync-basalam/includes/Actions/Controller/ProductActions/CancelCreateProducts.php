<?php

namespace SyncBasalam\Actions\Controller\ProductActions;

use SyncBasalam\JobManager;
use SyncBasalam\Actions\Controller\ActionController;

defined('ABSPATH') || exit;

class CancelCreateProducts extends ActionController
{
    public function __invoke()
    {
        $jobManager = new JobManager();

        $jobTypes = [
            'sync_basalam_create_single_product',
            'sync_basalam_create_all_products',
        ];

        $deletedCount = 0;
        foreach ($jobTypes as $jobType) {
            $result = $jobManager->deleteJob([
                'job_type' => $jobType,
                'status'   => 'pending',
            ]);
            if ($result) {
                $deletedCount += $result;
            }

            $result = $jobManager->deleteJob([
                'job_type' => $jobType,
                'status'   => 'processing',
            ]);
            if ($result) {
                $deletedCount += $result;
            }
        }
        delete_option('sync_basalam_last_creatable_product_id');
    }
}
