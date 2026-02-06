<?php

namespace SyncBasalam\Actions\Controller\ProductActions;

use SyncBasalam\JobManager;
use SyncBasalam\Actions\Controller\ActionController;

defined('ABSPATH') || exit;

class CancelUpdateProducts extends ActionController
{
    public function __invoke()
    {
        $jobManager = new JobManager();

        $jobTypes = [
            'sync_basalam_bulk_update_products',
            'sync_basalam_update_all_products',
            'sync_basalam_update_single_product',
        ];

        foreach ($jobTypes as $jobType) {
            $jobManager->deleteJob(['job_type' => $jobType, 'status' => 'pending']);
            $jobManager->deleteJob(['job_type' => $jobType, 'status' => 'processing']);
        }

        $this->deleteAllBatches();
        $this->clearAllLocks();
    }

    private function deleteAllBatches()
    {
        global $wpdb;

        $patterns = [
            'sync_basalam_bulk_update_products_task_%_batch_%',
            'sync_basalam_update_single_product_%_batch_%',
            'sync_basalam_%_batch_%',
        ];

        foreach ($patterns as $pattern) {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $pattern
                )
            );
        }
    }

    private function clearAllLocks()
    {
        global $wpdb;

        $patterns = [
            '_transient_sync_basalam_bulk_update_products_task_%_lock',
            '_transient_UpdateSingleProduct_%_lock',
            '_transient_sync_basalam_%_lock',
        ];

        foreach ($patterns as $pattern) {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $pattern
                )
            );
        }
    }
}
