<?php
if (! defined('ABSPATH')) exit;
abstract class Sync_basalam_AbstractTask
{
    protected $queue_manager;

    public function __construct()
    {
        $this->queue_manager = new sync_basalam_QueueManager($this->get_hook_name());
    }

    public function register_hooks()
    {
        add_action($this->get_hook_name(), [$this, 'handle'], 10, 1);
    }

    protected function get_last_run_timestamp()
    {
        return intval(get_option($this->get_hook_name() . '_last_run', time()));
    }

    abstract public function handle($args);

    abstract protected function get_hook_name();
}
