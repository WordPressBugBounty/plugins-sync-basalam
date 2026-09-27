<?php

namespace SyncBasalam\Actions\Controller\ProductActions;

use SyncBasalam\Actions\Controller\ActionController;
use SyncBasalam\JobManager;

defined('ABSPATH') || exit;

class GetUpdateQueueStatus extends ActionController
{
    public function __invoke()
    {
        $jobManager = syncBasalamContainer()->get(JobManager::class);
        wp_send_json_success($jobManager->getProductUpdateStatus());
    }
}
