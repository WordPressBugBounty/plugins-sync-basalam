<?php

namespace SyncBasalam;

defined('ABSPATH') || exit;

abstract class AsyncBackgroundProcess
{
    protected $action = 'async_process';

    protected $identifier;

    protected $data = array();

    protected $timeLimit = 30;

    protected $batchSize = 20;

    protected $cronHook;

    protected $cronInterval = 'every_5_minutes';

    public function __construct()
    {
        $this->identifier = $this->getIdentifier();
        $this->cronHook = $this->identifier . '_cron';

        add_filter('cron_schedules', array($this, 'addCronInterval'));

        add_action('wp_ajax_' . $this->identifier, array($this, 'handleAsyncRequest'));
        add_action('wp_ajax_nopriv_' . $this->identifier, array($this, 'handleAsyncRequest'));
        add_action($this->cronHook, array($this, 'handleCronHealthcheck'));

        $this->init();
    }

    protected function init() {}

    protected function getIdentifier()
    {
        return $this->action . '_' . substr(md5(get_class($this)), 0, 8);
    }

    public function addCronInterval($schedules)
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
        $key = $this->getBatchKey();

        if (!empty($this->data)) {
            update_option($key, $this->data, false);
        }

        $this->data = array();

        return $this;
    }

    public function dispatch()
    {

        if (!wp_next_scheduled($this->cronHook)) {
            wp_schedule_event(time(), $this->cronInterval, $this->cronHook);
        }

        return $this->triggerAsyncRequest();
    }

    protected function triggerAsyncRequest()
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

        if (is_wp_error($result)) return $this->triggerAlternativeAsync();

        return $result;
    }

    protected function triggerAlternativeAsync()
    {
        wp_schedule_single_event(time(), $this->identifier . '_immediate');
        add_action($this->identifier . '_immediate', array($this, 'handleAsyncRequest'));

        spawn_cron();

        return true;
    }

    public function handleAsyncRequest()
    {
        session_write_close();
        if (!$this->isProcessing()) {
            $this->handle();
        }
    }

    public function handleCronHealthcheck()
    {
        if ($this->isQueueEmpty()) {

            $this->clearScheduledEvent();
            return;
        }

        $this->triggerAsyncRequest();
    }

    protected function handle()
    {

        $this->lockProcess();

        $batch = $this->getBatch();

        if (empty($batch)) {
            $this->unlockProcess();
            return;
        }

        $startTime = time();

        foreach ($batch as $key => $item) {
            if ($this->timeExceeded($startTime)) {
                break;
            }

            if ($this->memoryExceeded()) {
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

            $this->updateBatch($batch);
        } else {

            $this->deleteBatch();
        }

        $this->unlockProcess();

        if (!$this->isQueueEmpty()) {

            $this->dispatch();
        } else {

            $this->complete();
            $this->clearScheduledEvent();
        }
    }

    abstract protected function task($item);

    protected function complete() {}

    protected function getBatch()
    {
        global $wpdb;

        $table = $wpdb->options;
        $keyPattern = $this->identifier . '_batch_%';

        $query = $wpdb->prepare("
            SELECT option_name, option_value
            FROM {$table}
            WHERE option_name LIKE %s
            ORDER BY option_name ASC
            LIMIT 1
        ", $keyPattern);

        $batch = $wpdb->get_row($query);

        if (empty($batch)) return array();

        $batchData = maybe_unserialize($batch->option_value);

        if (!is_array($batchData)) return array();

        return array_slice($batchData, 0, $this->batchSize, true);
    }

    protected function updateBatch($batch)
    {
        $batches = $this->getBatches();

        if (!empty($batches)) {
            $firstBatch = array_shift($batches);
            update_option($firstBatch->option_name, $batch, false);
        }
    }

    protected function deleteBatch()
    {
        $batches = $this->getBatches();

        if (!empty($batches)) {
            $firstBatch = array_shift($batches);
            delete_option($firstBatch->option_name);
        }
    }

    protected function getBatches()
    {
        global $wpdb;

        $table = $wpdb->options;
        $keyPattern = $this->identifier . '_batch_%';

        return $wpdb->get_results(
            $wpdb->prepare("
                SELECT option_name
                FROM {$table}
                WHERE option_name LIKE %s
                ORDER BY option_name ASC
            ", $keyPattern)
        );
    }

    protected function getBatchKey()
    {
        $key = $this->identifier . '_batch_' . md5(microtime() . mt_rand());

        $count = 1;
        while (get_option($key) !== false) {
            $key = $this->identifier . '_batch_' . md5(microtime() . mt_rand() . $count);
            $count++;
        }

        return $key;
    }

    protected function isQueueEmpty()
    {
        $batches = $this->getBatches();
        return empty($batches);
    }

    protected function lockProcess()
    {
        $lockKey = $this->identifier . '_lock';
        $lockDuration = 60;

        $lock = get_transient($lockKey);

        if ($lock) return false;

        set_transient($lockKey, microtime(true), $lockDuration);

        return true;
    }

    protected function unlockProcess()
    {
        delete_transient($this->identifier . '_lock');
    }

    protected function isProcessing()
    {
        return (bool) get_transient($this->identifier . '_lock');
    }

    protected function timeExceeded($startTime)
    {
        $timeLimit = ini_get('max_execution_time');

        if (empty($timeLimit) || $timeLimit > $this->timeLimit) {
            $timeLimit = $this->timeLimit;
        }

        $timeLimit = $timeLimit - 5;

        return (time() - $startTime) > $timeLimit;
    }

    protected function memoryExceeded()
    {
        $memoryLimit = ini_get('memory_limit');

        if ($memoryLimit == '-1') return false;

        $memoryLimit = $this->convertToBytes($memoryLimit);
        $currentMemory = memory_get_usage(true);

        $buffer = 10 * 1024 * 1024;

        return ($currentMemory + $buffer) > $memoryLimit;
    }

    protected function convertToBytes($value)
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

    protected function clearScheduledEvent()
    {
        $timestamp = wp_next_scheduled($this->cronHook);

        if ($timestamp) {
            wp_unschedule_event($timestamp, $this->cronHook);
        }
    }

    public function cancel()
    {
        $batches = $this->getBatches();

        foreach ($batches as $batch) {
            delete_option($batch->option_name);
        }

        $this->clearScheduledEvent();

        $this->unlockProcess();
    }

    public function isActive()
    {
        if (get_transient($this->identifier . '_lock')) return true;

        $batches = $this->getBatches();

        $hasScheduledCron = wp_next_scheduled($this->cronHook) !== false;

        return !empty($batches) || $hasScheduledCron;
    }

    public function countBatches()
    {
        $batches = $this->getBatches();
        return count($batches);
    }
}
