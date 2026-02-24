<?php

namespace SyncBasalam\Queue\Tasks;

use SyncBasalam\Queue\QueueAbstract;
use SyncBasalam\Services\ApiServiceManager;
use SyncBasalam\Services\FetchVersionDetail;

defined('ABSPATH') || exit;

class DailyCheckForceUpdate extends QueueAbstract
{
    public $apiservice;
    public $tableName;
    public $taskName;
    public $NEED_SCHEDULE = true;

    public function __construct()
    {
        parent::__construct();
        $this->apiservice = new ApiServiceManager();
        $this->taskName = $this->getHookName();
    }

    protected function getHookName()
    {
        return 'sync_basalam_fetch_version_detail';
    }

    public function handle($args = null)
    {
        $versionChecker = new FetchVersionDetail(syncbasalamplugin()->getVersion());
        $versionChecker->checkForceUpdate();
    }

    public function schedule()
    {
        return $this->queueManager->scheduleRecurringTask(86400);
    }
}
