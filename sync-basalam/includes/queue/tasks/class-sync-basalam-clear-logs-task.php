<?php
if (! defined('ABSPATH')) exit;
class Sync_basalam_Clear_Logs_Task extends sync_basalam_AbstractTask
{
    protected function get_hook_name()
    {
        return 'sync_basalam_plugin_clear_logs';
    }

    public function handle($args)
    {
        $upload_dir = wp_upload_dir();
        $log_dir = trailingslashit($upload_dir['basedir']) . 'wc-logs/';

        if (is_dir($log_dir)) {
            $files = glob($log_dir . '/basalam-sync-plugin-*.log');

            if ($files) {
                $now = time();
                $days_ago = 1 * 24 * 60 * 60;

                foreach ($files as $file) {
                    if ($now - filemtime($file) >= $days_ago) {
                        wp_delete_file($file);
                    }
                }
            }
        }
    }

    public function schedule($data, $delay = null)
    {
        if ($delay == null) {
            if ($this->get_last_run_timestamp() > time()) {
                $delay = $this->get_last_run_timestamp() - time() + 60;
            } else {
                $delay = 60;
            }
        }

        return $this->queue_manager->schedule_recurring_task(604800);
    }
}
