<?php

namespace SyncBasalam\Queue\Tasks;

use SyncBasalam\Queue\QueueAbstract;

defined('ABSPATH') || exit;
class ClearLogFiles extends QueueAbstract
{
    protected function getHookName()
    {
        return 'sync_basalam_plugin_clear_log_files';
    }

    public function handle($args = null)
    {
        $uploadDir = wp_upload_dir();
        $logDir = trailingslashit($uploadDir['basedir']) . 'wc-logs/';

        if (is_dir($logDir)) {
            $files = glob($logDir . '/basalam-sync-plugin-*.log');

            if ($files) {
                $now = time();
                $daysAgo = 1 * 24 * 60 * 60;

                foreach ($files as $file) {
                    if ($now - filemtime($file) >= $daysAgo) {
                        wp_delete_file($file);
                    }
                }
            }
        }
    }

    public function schedule()
    {
        return $this->queueManager->scheduleRecurringTask(604800);
    }

    public function registerHooks()
    {

        $clearLogsExist = \WC()->queue()->search([
            'hook'     => 'sync_basalam_plugin_clear_log_files',
            'status'   => 'pending',
            'per_page' => 1,
        ]);

        if (!$clearLogsExist) {
            $this->schedule(null);
        }
        parent::registerHooks();
    }
}
