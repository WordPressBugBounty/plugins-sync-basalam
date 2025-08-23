<?php

/**
 * WP Async Background Processing Class
 * 
 * A robust background processing solution for WordPress using WP-Cron
 * with async and non-blocking execution
 * 
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

abstract class WP_Async_Background_Process
{

    /**
     * @var string
     */
    protected $action = 'async_process';

    /**
     * @var string
     */
    protected $identifier;

    /**
     * @var array
     */
    protected $data = array();

    /**
     * @var int
     */
    protected $time_limit = 20; // seconds

    /**
     * @var int
     */
    protected $batch_size = 20;

    /**
     * @var string
     */
    protected $cron_hook;

    /**
     * @var string
     */
    protected $cron_interval = 'every_5_minutes';

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->identifier = $this->get_identifier();
        $this->cron_hook = $this->identifier . '_cron';

        // Add custom cron interval
        add_filter('cron_schedules', array($this, 'add_cron_interval'));

        // Hook into WordPress
        add_action('wp_ajax_' . $this->identifier, array($this, 'handle_async_request'));
        add_action('wp_ajax_nopriv_' . $this->identifier, array($this, 'handle_async_request'));
        add_action($this->cron_hook, array($this, 'handle_cron_healthcheck'));

        // Initialize
        $this->init();
    }

    /**
     * Initialize
     */
    protected function init()
    {
        // Override in child class if needed
    }

    /**
     * Get unique identifier
     * 
     * @return string
     */
    protected function get_identifier()
    {
        return $this->action . '_' . substr(md5(get_class($this)), 0, 8);
    }

    /**
     * Add custom cron interval
     * 
     * @param array $schedules
     * @return array
     */
    public function add_cron_interval($schedules)
    {
        $schedules['every_5_minutes'] = array(
            'interval' => 300,
            'display' => __('Every 5 Minutes')
        );

        $schedules['every_minute'] = array(
            'interval' => 60,
            'display' => __('Every Minute')
        );

        return $schedules;
    }

    /**
     * Push item to queue
     * 
     * @param mixed $item
     * @return $this
     */
    public function push($item)
    {
        $this->data[] = $item;
        return $this;
    }

    /**
     * Save queue
     * 
     * @return $this
     */
    public function save()
    {
        $key = $this->get_batch_key();

        if (!empty($this->data)) {
            update_option($key, $this->data, false);
        }

        $this->data = array();

        return $this;
    }

    /**
     * Dispatch the async request
     * 
     * @return bool|WP_Error
     */
    public function dispatch()
    {
        // Schedule the cron event
        if (!wp_next_scheduled($this->cron_hook)) {
            wp_schedule_event(time(), $this->cron_interval, $this->cron_hook);
        }
        // Trigger immediate async request
        return $this->trigger_async_request();
    }

    /**
     * Trigger async request
     * 
     * @return bool|WP_Error
     */
    protected function trigger_async_request()
    {
        $url = add_query_arg(
            array(
                'action' => $this->identifier,
                'nonce'  => wp_create_nonce($this->identifier)
            ),
            admin_url('admin-ajax.php')
        );

        $args = array(
            'timeout'   => 0.01,
            'blocking'  => false,
            'body'      => array(
                'action' => $this->identifier,
                'nonce'  => wp_create_nonce($this->identifier)
            ),
            'cookies'   => $_COOKIE,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'headers'   => array(
                'X-WP-Async-Request' => $this->identifier
            )
        );

        // error_log("ASYNC: Triggering async request to {$url}");

        $result = wp_remote_post(esc_url_raw($url), $args);

        if (is_wp_error($result)) {
            // error_log("ASYNC: wp_remote_post failed - " . $result->get_error_message());
            return $this->trigger_alternative_async();
        }

        // error_log("ASYNC: wp_remote_post returned without blocking");
        return $result;
    }


    /**
     * Alternative async trigger using wp_schedule_single_event
     * 
     * @return bool
     */
    protected function trigger_alternative_async()
    {
        // Schedule immediate execution
        wp_schedule_single_event(time(), $this->identifier . '_immediate');
        add_action($this->identifier . '_immediate', array($this, 'handle_async_request'));

        // Spawn cron if not already running
        spawn_cron();

        return true;
    }

    /**
     * Handle async request
     */
    public function handle_async_request()
    {
        // error_log("ASYNC: handle_async_request() started");

        if (!isset($_REQUEST['nonce']) || !wp_verify_nonce($_REQUEST['nonce'], $this->identifier)) {
            // error_log("ASYNC: Nonce verification failed");
            if (!defined('DOING_CRON') || !DOING_CRON) {
                wp_die();
            }
        } else {
            // error_log("ASYNC: Nonce verification passed");
        }

        session_write_close();

        if (!$this->is_processing()) {
            // error_log("ASYNC: Not processing yet, starting handle()");
            $this->handle();
        } else {
            // error_log("ASYNC: Already processing, skipping");
        }

        // error_log("ASYNC: handle_async_request() finished");
        wp_die();
    }



    /**
     * Handle cron healthcheck
     */
    public function handle_cron_healthcheck()
    {
        if ($this->is_queue_empty()) {
            // No more items, clear the cron
            $this->clear_scheduled_event();
            return;
        }

        // Trigger async processing
        $this->trigger_async_request();
    }

    /**
     * Handle the processing
     */
    protected function handle()
    {
        // error_log("ASYNC: handle() started");
        $this->lock_process();

        $batch = $this->get_batch();
        // error_log("ASYNC: Batch size = " . count($batch));

        if (empty($batch)) {
            $this->unlock_process();
            // error_log("ASYNC: No batch found, stopping");
            return;
        }

        $start_time = time();

        foreach ($batch as $key => $item) {
            if ($this->time_exceeded($start_time)) {
                // error_log("ASYNC: Time limit exceeded");
                break;
            }

            if ($this->memory_exceeded()) {
                // error_log("ASYNC: Memory limit exceeded");
                break;
            }

            // error_log("ASYNC: Processing item " . json_encode($item));
            $item = $this->task($item);

            if (false === $item) {
                unset($batch[$key]);
                // error_log("ASYNC: Item completed and removed");
            } else {
                $batch[$key] = $item;
                // error_log("ASYNC: Item updated");
            }
        }

        if (!empty($batch)) {
            // error_log("ASYNC: Updating batch");
            $this->update_batch($batch);
        } else {
            // error_log("ASYNC: Deleting batch");
            $this->delete_batch();
        }

        $this->unlock_process();

        if (!$this->is_queue_empty()) {
            // error_log("ASYNC: Queue not empty, dispatching next batch");
            $this->dispatch();
        } else {
            // error_log("ASYNC: Queue empty, completing process");
            $this->complete();
            $this->clear_scheduled_event();
        }
    }

    /**
     * Task to perform
     * 
     * @param mixed $item
     * @return mixed False to remove, item to keep
     */
    abstract protected function task($item);

    /**
     * Complete processing
     */
    protected function complete()
    {
        // Override in child class
    }

    /**
     * Get batch
     * 
     * @return array
     */
    protected function get_batch()
    {
        global $wpdb;

        $table = $wpdb->options;
        $key_pattern = $this->identifier . '_batch_%';

        $query = $wpdb->prepare("
            SELECT option_name, option_value
            FROM {$table}
            WHERE option_name LIKE %s
            ORDER BY option_name ASC
            LIMIT 1
        ", $key_pattern);

        $batch = $wpdb->get_row($query);

        if (empty($batch)) {
            return array();
        }

        $batch_data = maybe_unserialize($batch->option_value);

        if (!is_array($batch_data)) {
            return array();
        }

        // Return limited batch size
        return array_slice($batch_data, 0, $this->batch_size, true);
    }

    /**
     * Update batch
     * 
     * @param array $batch
     */
    protected function update_batch($batch)
    {
        $batches = $this->get_batches();

        if (!empty($batches)) {
            $first_batch = array_shift($batches);
            update_option($first_batch->option_name, $batch, false);
        }
    }

    /**
     * Delete batch
     */
    protected function delete_batch()
    {
        $batches = $this->get_batches();

        if (!empty($batches)) {
            $first_batch = array_shift($batches);
            delete_option($first_batch->option_name);
        }
    }

    /**
     * Get all batches
     * 
     * @return array
     */
    protected function get_batches()
    {
        global $wpdb;

        $table = $wpdb->options;
        $key_pattern = $this->identifier . '_batch_%';

        return $wpdb->get_results(
            $wpdb->prepare("
                SELECT option_name
                FROM {$table}
                WHERE option_name LIKE %s
                ORDER BY option_name ASC
            ", $key_pattern)
        );
    }

    /**
     * Get batch key
     * 
     * @return string
     */
    protected function get_batch_key()
    {
        $key = $this->identifier . '_batch_' . md5(microtime() . mt_rand());

        // Ensure unique key
        $count = 1;
        while (get_option($key) !== false) {
            $key = $this->identifier . '_batch_' . md5(microtime() . mt_rand() . $count);
            $count++;
        }

        return $key;
    }

    /**
     * Check if queue is empty
     * 
     * @return bool
     */
    protected function is_queue_empty()
    {
        $batches = $this->get_batches();
        return empty($batches);
    }

    /**
     * Lock process
     * 
     * @return bool
     */
    protected function lock_process()
    {
        $lock_key = $this->identifier . '_lock';
        $lock_duration = 60; // 1 minute lock

        // Try to acquire lock
        $lock = get_transient($lock_key);

        if ($lock) {
            return false;
        }

        set_transient($lock_key, microtime(true), $lock_duration);

        return true;
    }

    /**
     * Unlock process
     */
    protected function unlock_process()
    {
        delete_transient($this->identifier . '_lock');
    }

    /**
     * Check if processing
     * 
     * @return bool
     */
    protected function is_processing()
    {
        return (bool) get_transient($this->identifier . '_lock');
    }

    /**
     * Check time limit
     * 
     * @param int $start_time
     * @return bool
     */
    protected function time_exceeded($start_time)
    {
        $time_limit = ini_get('max_execution_time');

        // Use custom time limit if max_execution_time is 0 (unlimited)
        if (empty($time_limit) || $time_limit > $this->time_limit) {
            $time_limit = $this->time_limit;
        }

        // Leave 5 seconds buffer
        $time_limit = $time_limit - 5;

        return (time() - $start_time) > $time_limit;
    }

    /**
     * Check memory limit
     * 
     * @return bool
     */
    protected function memory_exceeded()
    {
        $memory_limit = ini_get('memory_limit');

        if ($memory_limit == '-1') {
            return false;
        }

        $memory_limit = $this->convert_to_bytes($memory_limit);
        $current_memory = memory_get_usage(true);

        // Leave 10MB buffer
        $buffer = 10 * 1024 * 1024;

        return ($current_memory + $buffer) > $memory_limit;
    }

    /**
     * Convert to bytes
     * 
     * @param string $value
     * @return int
     */
    protected function convert_to_bytes($value)
    {
        $value = strtolower(trim($value));
        $bytes = (int) $value;

        if (strpos($value, 'g') !== false) {
            $bytes *= 1024 * 1024 * 1024;
        } elseif (strpos($value, 'm') !== false) {
            $bytes *= 1024 * 1024;
        } elseif (strpos($value, 'k') !== false) {
            $bytes *= 1024;
        }

        return $bytes;
    }

    /**
     * Clear scheduled event
     */
    protected function clear_scheduled_event()
    {
        $timestamp = wp_next_scheduled($this->cron_hook);

        if ($timestamp) {
            wp_unschedule_event($timestamp, $this->cron_hook);
        }
    }

    /**
     * Cancel all processing
     */
    public function cancel()
    {
        // Delete all batches
        $batches = $this->get_batches();

        foreach ($batches as $batch) {
            delete_option($batch->option_name);
        }

        // Clear cron
        $this->clear_scheduled_event();

        // Unlock process
        $this->unlock_process();
    }

    /**
     * Check if the background process is active
     * 
     * @return bool
     */
    public function is_active()
    {
        // Check if process is currently locked (actively running)
        if (get_transient($this->identifier . '_lock')) {
            return true;
        }
        
        // Check if there are any batches waiting to be processed
        $batches = $this->get_batches();
        
        // Check if cron is scheduled
        $has_scheduled_cron = wp_next_scheduled($this->cron_hook) !== false;
        
        // Process is active if there are batches or cron is scheduled
        return !empty($batches) || $has_scheduled_cron;
    }
}