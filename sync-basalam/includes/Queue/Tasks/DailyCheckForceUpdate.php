<?php

namespace SyncBasalam\Queue\Tasks;

use SyncBasalam\Logger\Logger;
use SyncBasalam\Queue\QueueAbstract;
use SyncBasalam\Services\FetchVersionDetail;

defined('ABSPATH') || exit;

class DailyCheckForceUpdate extends QueueAbstract
{
    public $tableName;
    public $taskName;
    public $NEED_SCHEDULE = true;

    public function __construct()
    {
        parent::__construct();
        $this->taskName = $this->getHookName();
    }

    protected function getHookName()
    {
        return 'sync_basalam_fetch_version_detail';
    }

    public function handle($args = null)
    {
        try {
            $versionChecker = new FetchVersionDetail(syncbasalamplugin()->getVersion());
            $versionChecker->checkForceUpdate();
        } catch (\Exception $e) {
            Logger::error('خطا در اجرای تسک بررسی نسخه: ' . $e->getMessage());
        }
    }

    public function schedule()
    {
        return $this->queueManager->scheduleRecurringTask(86400);
    }
}
