<?php
if (! defined('ABSPATH')) exit;


class Sync_basalam_ErrorLogger implements sync_basalam_Logger_Interface
{

    public function log($level, $message, $context = [])
    {
        error_log("[basalam-sync-plugin][" . strtoupper($level) . "] " . $message);
    }

    public function info($message, $context)
    {
        $this->log("info", $message, $context);
    }

    public function debug($message, $context)
    {
        $this->log("debug", $message, $context);
    }

    public function warning($message, $context)
    {
        $this->log("warning", $message, $context);
    }

    public function error($message, $context)
    {
        $this->log("error", $message, $context);
    }

    public function alert($message, $context)
    {
        $this->log("alert", $message, $context);
    }
}
