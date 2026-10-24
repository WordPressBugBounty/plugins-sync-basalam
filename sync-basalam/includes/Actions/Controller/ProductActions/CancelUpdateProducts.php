<?php

namespace SyncBasalam\Actions\Controller\ProductActions;

use SyncBasalam\JobManager;
use SyncBasalam\Actions\Controller\ActionController;
use SyncBasalam\Services\AsyncWorkerHealth;
use SyncBasalam\Utilities\ProductOperationNotice;

defined('ABSPATH') || exit;

class CancelUpdateProducts extends ActionController
{
    public function __invoke()
    {
        $jobManager = syncBasalamContainer()->get(JobManager::class);

        $deletedCount = $jobManager->cancelProductJobs('update');
        if ($deletedCount === false) {
            wp_die('توقف عملیات کامل نشد؛ دوباره تلاش کنید.');
        }

        $this->deleteAllBatches();
        $this->clearAllLocks();
        (new AsyncWorkerHealth())->clearInterruption();
        ProductOperationNotice::push('update', (int) $deletedCount);
    }

    private function deleteAllBatches()
    {
        global $wpdb;

        $patterns = [
            'sync_basalam_bulk_update_products_task_%_batch_%',
            'sync_basalam_update_single_product_%_batch_%',
        ];

        foreach ($patterns as $pattern) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin option cleanup; no object cache for these operational queries.
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
        ];

        foreach ($patterns as $pattern) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin option cleanup; no object cache for these operational queries.
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $pattern
                )
            );
        }
    }
}
