<?php

namespace SyncBasalam\Actions\Controller\ProductActions;

use SyncBasalam\JobManager;
use SyncBasalam\Actions\Controller\ActionController;
use SyncBasalam\Services\AsyncWorkerHealth;

defined('ABSPATH') || exit;

class CancelCreateProducts extends ActionController
{
    public function __invoke()
    {
        $jobManager = syncBasalamContainer()->get(JobManager::class);

        if ($jobManager->cancelProductJobs('create') === false) {
            wp_die('توقف عملیات کامل نشد؛ دوباره تلاش کنید.');
        }

        delete_option('sync_basalam_last_creatable_product_id');
        (new AsyncWorkerHealth())->clearInterruption();
    }
}
