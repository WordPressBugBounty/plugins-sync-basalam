<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_WooLogger implements sync_basalam_Logger_Interface
{
    public static function get_logs()
    {
        if (!current_user_can('manage_options')) {
            return [
                'error' => 'شما دسترسی لازم برای مشاهده لاگ‌ها را ندارید.'
            ];
        }

        $upload_dir = wp_upload_dir();
        $log_dir = trailingslashit($upload_dir['basedir']) . 'wc-logs/';

        $log_files = glob($log_dir . 'basalam-sync-plugin*.log');
        if (empty($log_files)) {
            return [
                'error' => 'هیچ لاگی یافت نشد.'
            ];
        }

        rsort($log_files);
        $all_logs = [];

        foreach ($log_files as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach (array_reverse($lines) as $line) {
                if (preg_match('/^(.*?) (INFO|WARNING|ERROR|DEBUG|ALERT) (.*?)( CONTEXT: (.*))?$/', $line, $matches)) {
                    $tehran_datetime = Sync_basalam_Date_Converter::utc_to_tehran($matches[1]);
                    $jalali_date = Sync_basalam_Date_Converter::gregorian_to_jalali(
                        $tehran_datetime->format('Y'),
                        $tehran_datetime->format('m'),
                        $tehran_datetime->format('d')
                    ) . ' - ' . $tehran_datetime->format('H:i:s');

                    $context = isset($matches[5]) ? json_decode($matches[5], true) : null;

                    $all_logs[] = [
                        'date' => $jalali_date,
                        'level' => $matches[2],
                        'message' => $matches[3],
                        'context' => $context,
                    ];
                }
            }
        }

        $logs_by_level = ['info' => [], 'warning' => [], 'error' => [], 'debug' => [], 'alert' => []];
        foreach ($all_logs as $log) {
            $logs_by_level[strtolower($log['level'])][] = $log;
        }

        return [
            'logs_by_level' => $logs_by_level,
            'current_tab' => sanitize_text_field(isset($_GET['tab'])) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'info',
            'current_page' => sanitize_text_field(isset($_GET['paged'])) ? max(1, intval(sanitize_text_field(wp_unslash($_GET['paged'])))) : 1,
            'per_page' => 10
        ];
    }

    public function log($level, $message, $context = [])
    {
        $logger = wc_get_logger();
        $context = array_merge($context, ['source' => "basalam-sync-plugin"]);
        $logger->log($level, $message, $context);
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
