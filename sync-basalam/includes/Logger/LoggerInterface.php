<?php

namespace SyncBasalam\Logger;

defined('ABSPATH') || exit;

interface LoggerInterface
{
    public function log($level, $message, $context = []);

    public function debug($message, $context);

    public function info($message, $context);

    public function warning($message, $context);

    public function error($message, $context);

    public function alert($message, $context);
}
