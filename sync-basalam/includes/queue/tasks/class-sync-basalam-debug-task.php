<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_debug_Task extends sync_basalam_AbstractTask
{
    protected function get_hook_name()
    {
        return 'sync_basalam_plugin_debug';
    }

    public function handle($args)
    {
        $apiservice = new Sync_basalam_External_API_Service();
        $url = 'https://basalam.com';

        $start = microtime(true);

        $response = $apiservice->send_get_request($url, []);

        $elapsedMs = (int) round((microtime(true) - $start) * 1000);

        $success = false;
        $status_code = null;
        $error_message = null;

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
        } else {
            $status_code = $response['status_code'];
            $success = $status_code >= 200 && $status_code < 400;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'sync_basalam_debug_logs';

        $wpdb->insert(
            $table_name,
            [
                'request_url' => $url,
                'status_code' => $status_code,
                'success' => $success ? 1 : 0,
                'response_time_ms' => $elapsedMs,
                'error_message' => $error_message,
                'created_at' => current_time('mysql'),
            ],
            [
                '%s', // request_url
                '%d', // status_code
                '%d', // success
                '%d', // response_time_ms
                '%s', // error_message
                '%s', // created_at
            ]
        );
    }

    public function schedule()
    {
        return $this->queue_manager->schedule_recurring_task(60, []);
    }
}
