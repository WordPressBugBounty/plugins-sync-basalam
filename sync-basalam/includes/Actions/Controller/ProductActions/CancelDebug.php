<?php

namespace SyncBasalam\Actions\Controller\ProductActions;

use SyncBasalam\Queue\QueueManager;
use SyncBasalam\Actions\Controller\ActionController;

defined('ABSPATH') || exit;

class CancelDebug extends ActionController
{
    public function __invoke()
    {
        QueueManager::cancelAllTasksGroup('sync_basalam_plugin_debug');
    }
}
