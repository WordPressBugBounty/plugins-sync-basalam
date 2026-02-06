<?php

namespace SyncBasalam\Queue;

defined('ABSPATH') || exit;
abstract class QueueAbstract
{
    protected $queueManager;
    public $NEED_SCHEDULE = false;

    public function __construct()
    {
        $this->queueManager = new QueueManager($this->getHookName());
    }

    public function registerHooks()
    {
        add_action($this->getHookName(), [$this, 'handle'], 10, 1);
    }

    protected function getLastRunTimestamp()
    {
        return intval(get_option($this->getHookName() . '_last_run', time()));
    }

    abstract public function handle($args);

    abstract protected function getHookName();
}
