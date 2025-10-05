<?php



if (!defined('ABSPATH')) {
    exit;
}

abstract class WP_Async_Background_Process
{


    protected $action = 'async_process';


    protected $identifier;


    protected $data = array();


    protected $time_limit = 20;


    protected $batch_size = 20;


    protected $cron_hook;


    protected $cron_interval = 'every_5_minutes';


    public function __construct()
    {
        $this->identifier = $this->get_identifier();
        $this->cron_hook = $this->identifier . '_cron';


        add_filter('cron_schedules', array($this, 'add_cron_interval'));


        add_action('wp_ajax_' . $this->identifier, array($this, 'handle_async_request'));
        add_action('wp_ajax_nopriv_' . $this->identifier, array($this, 'handle_async_request'));
        add_action($this->cron_hook, array($this, 'handle_cron_healthcheck'));


        $this->init();
    }


    protected function init() {}


    protected function get_identifier()
    {
        return $this->action . '_' . substr(md5(get_class($this)), 0, 8);
    }


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


    public function push($item)
    {
        $this->data[] = $item;
        return $this;
    }


    public function save()
    {
        $key = $this->get_batch_key();

        if (!empty($this->data)) {
            update_option($key, $this->data, false);
        }

        $this->data = array();

        return $this;
    }


    public function dispatch()
    {

        if (!wp_next_scheduled($this->cron_hook)) {
            wp_schedule_event(time(), $this->cron_interval, $this->cron_hook);
        }

        return $this->trigger_async_request();
    }


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



        $result = wp_remote_post(esc_url_raw($url), $args);

        if (is_wp_error($result)) {

            return $this->trigger_alternative_async();
        }

        return $result;
    }



    protected function trigger_alternative_async()
    {
        wp_schedule_single_event(time(), $this->identifier . '_immediate');
        add_action($this->identifier . '_immediate', array($this, 'handle_async_request'));

        spawn_cron();

        return true;
    }


    public function handle_async_request()
    {
        session_write_close();
        if (!$this->is_processing()) {
            $this->handle();
        }
    }

    public function handle_cron_healthcheck()
    {
        if ($this->is_queue_empty()) {

            $this->clear_scheduled_event();
            return;
        }


        $this->trigger_async_request();
    }


    protected function handle()
    {

        $this->lock_process();

        $batch = $this->get_batch();


        if (empty($batch)) {
            $this->unlock_process();

            return;
        }

        $start_time = time();

        foreach ($batch as $key => $item) {
            if ($this->time_exceeded($start_time)) {

                break;
            }

            if ($this->memory_exceeded()) {

                break;
            }


            $item = $this->task($item);

            if (false === $item) {
                unset($batch[$key]);
            } else {
                $batch[$key] = $item;
            }
        }

        if (!empty($batch)) {

            $this->update_batch($batch);
        } else {

            $this->delete_batch();
        }

        $this->unlock_process();

        if (!$this->is_queue_empty()) {

            $this->dispatch();
        } else {

            $this->complete();
            $this->clear_scheduled_event();
        }
    }


    abstract protected function task($item);


    protected function complete() {}


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


        return array_slice($batch_data, 0, $this->batch_size, true);
    }


    protected function update_batch($batch)
    {
        $batches = $this->get_batches();

        if (!empty($batches)) {
            $first_batch = array_shift($batches);
            update_option($first_batch->option_name, $batch, false);
        }
    }


    protected function delete_batch()
    {
        $batches = $this->get_batches();

        if (!empty($batches)) {
            $first_batch = array_shift($batches);
            delete_option($first_batch->option_name);
        }
    }


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


    protected function get_batch_key()
    {
        $key = $this->identifier . '_batch_' . md5(microtime() . mt_rand());


        $count = 1;
        while (get_option($key) !== false) {
            $key = $this->identifier . '_batch_' . md5(microtime() . mt_rand() . $count);
            $count++;
        }

        return $key;
    }


    protected function is_queue_empty()
    {
        $batches = $this->get_batches();
        return empty($batches);
    }


    protected function lock_process()
    {
        $lock_key = $this->identifier . '_lock';
        $lock_duration = 60;


        $lock = get_transient($lock_key);

        if ($lock) {
            return false;
        }

        set_transient($lock_key, microtime(true), $lock_duration);

        return true;
    }


    protected function unlock_process()
    {
        delete_transient($this->identifier . '_lock');
    }


    protected function is_processing()
    {
        return (bool) get_transient($this->identifier . '_lock');
    }


    protected function time_exceeded($start_time)
    {
        $time_limit = ini_get('max_execution_time');


        if (empty($time_limit) || $time_limit > $this->time_limit) {
            $time_limit = $this->time_limit;
        }


        $time_limit = $time_limit - 5;

        return (time() - $start_time) > $time_limit;
    }


    protected function memory_exceeded()
    {
        $memory_limit = ini_get('memory_limit');

        if ($memory_limit == '-1') {
            return false;
        }

        $memory_limit = $this->convert_to_bytes($memory_limit);
        $current_memory = memory_get_usage(true);


        $buffer = 10 * 1024 * 1024;

        return ($current_memory + $buffer) > $memory_limit;
    }


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


    protected function clear_scheduled_event()
    {
        $timestamp = wp_next_scheduled($this->cron_hook);

        if ($timestamp) {
            wp_unschedule_event($timestamp, $this->cron_hook);
        }
    }


    public function cancel()
    {

        $batches = $this->get_batches();

        foreach ($batches as $batch) {
            delete_option($batch->option_name);
        }


        $this->clear_scheduled_event();


        $this->unlock_process();
    }


    public function is_active()
    {

        if (get_transient($this->identifier . '_lock')) {
            return true;
        }


        $batches = $this->get_batches();


        $has_scheduled_cron = wp_next_scheduled($this->cron_hook) !== false;


        return !empty($batches) || $has_scheduled_cron;
    }

    public function count_batches()
    {
        $batches = $this->get_batches();
        return count($batches);
    }
}
