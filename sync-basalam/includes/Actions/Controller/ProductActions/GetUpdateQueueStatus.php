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
        $status = $jobManager->getProductUpdateStatus();
        $status['create'] = $jobManager->getProductCreateStatus();
        $health = get_option('sync_basalam_async_worker_health', []);
        $status['last_returned_at'] = is_array($health) ? (int) ($health['last_returned_at'] ?? 0) : 0;
        wp_send_json_success($status);
    }
}
