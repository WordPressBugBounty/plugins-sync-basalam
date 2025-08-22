<?php
if (! defined('ABSPATH')) exit;

interface sync_basalam_Logger_Interface
{
    public function log($level, $message, $context = []);

    public function debug($message, $context);

    public function info($message, $context);

    public function warning($message, $context);

    public function error($message, $context);

    public function alert($message, $context);
}
