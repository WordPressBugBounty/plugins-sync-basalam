<?php
if (! defined('ABSPATH')) exit;
/**
 * Plugin Logger Class
 */
class Sync_basalam_Logger
{
    private static $instance = [];
    private $logger;

    private function __construct($channel)
    {
        if ($channel == "woo" && class_exists('WC_Logger')) {
            $this->logger = new sync_basalam_WooLogger();
        } else {
            $this->logger = new sync_basalam_ErrorLogger();
        }
    }

    public static function getInstance($channel = "woo")
    {
        if (!isset(self::$instance[$channel])) {
            self::$instance[$channel] = new self($channel);
        }
        return self::$instance[$channel];
    }

    public static function channel($channel)
    {
        return self::getInstance($channel)->logger;
    }

    public static function log($level, $message, $context = [])
    {
        self::getInstance()->logger->log($level, $message, $context);
    }

    public static function info($message, $context = [])
    {
        self::getInstance()->logger->info($message, $context);
    }

    public static function debug($message, $context = [])
    {
        self::getInstance()->logger->debug($message, $context);
    }

    public static function warning($message, $context = [])
    {
        self::getInstance()->logger->warning($message, $context);
    }

    public static function error($message, $context = [])
    {
        self::getInstance()->logger->error($message, $context);
    }

    public static function alert($message, $context = [])
    {
        self::getInstance()->logger->alert($message, $context);
    }
    public static function get_logs()
    {
        return self::getInstance()->logger->get_logs();
    }
}
