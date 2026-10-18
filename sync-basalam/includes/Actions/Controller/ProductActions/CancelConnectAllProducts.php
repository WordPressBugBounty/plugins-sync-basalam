<?php

namespace SyncBasalam\Actions\Controller\ProductActions;

use SyncBasalam\Actions\Controller\ActionController;
use SyncBasalam\JobManager;
use SyncBasalam\Services\AsyncWorkerHealth;

defined('ABSPATH') || exit;

class CancelConnectAllProducts extends ActionController
{
    public function __invoke()
    {
        $jobManager = syncBasalamContainer()->get(JobManager::class);

        $jobManager->deleteJob(['job_type' => 'sync_basalam_connect_single_product']);
        $jobManager->deleteJob(['job_type' => 'sync_basalam_auto_connect_products']);
        (new AsyncWorkerHealth())->clearInterruption();
    }
}
