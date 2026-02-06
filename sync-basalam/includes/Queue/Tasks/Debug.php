<?php

namespace SyncBasalam\Queue\Tasks;

use SyncBasalam\Queue\QueueAbstract;
use SyncBasalam\Services\ApiServiceManager;

defined('ABSPATH') || exit;

class Debug extends QueueAbstract
{
    public $apiservice;
    public $tableName;
    private $db;

    public function __construct()
    {
        parent::__construct();
        global $wpdb;
        $this->db = $wpdb;
        $this->tableName = $this->db->prefix . 'sync_basalam_debug_logs';
        $this->apiservice = new ApiServiceManager();
    }

    protected function getHookName()
    {
        return 'sync_basalam_plugin_debug';
    }

    public function handle($args = null)
    {
        $url = 'https://basalam.com';

        $start = microtime(true);

        $response = $this->apiservice->sendGetRequest($url, []);

        $elapsedMs = (int) round((microtime(true) - $start) * 1000);

        $success = false;
        $statusCode = null;
        $errorMessage = null;

        if (is_wp_error($response)) {
            $errorMessage = $response['body'];
        } else {
            $statusCode = $response['status_code'];
            $success = $statusCode >= 200 && $statusCode < 400;
        }

        $this->db->insert(
            $this->tableName,
            [
                'request_url'      => $url,
                'status_code'      => $statusCode,
                'success'          => $success ? 1 : 0,
                'response_time_ms' => $elapsedMs,
                'error_message'    => $errorMessage,
                'created_at'       => current_time('mysql'),
            ],
            ['%s', '%d', '%d', '%d', '%s', '%s']
        );
    }

    public function schedule()
    {
        return $this->queueManager->scheduleRecurringTask(60);
    }
}
