<?php

namespace SyncBasalam\Logger;

use SyncBasalam\Utilities\DateConverter;

defined('ABSPATH') || exit;

class WooLogger implements LoggerInterface
{
    public static function getLogs()
    {
        if (!current_user_can('manage_options')) {
            return [
                'error' => 'شما دسترسی لازم برای مشاهده لاگ‌ها را ندارید.',
            ];
        }

        $uploadDir = wp_upload_dir();
        $logDir = trailingslashit($uploadDir['basedir']) . 'wc-logs/';

        $logFiles = glob($logDir . 'basalam-sync-plugin*.log');

        if (empty($logFiles)) {
            return [
                'error' => 'هیچ لاگی یافت نشد.',
            ];
        }

        usort($logFiles, fn($a, $b) => filemtime($b) <=> filemtime($a));

        $logFilesToRead   = array_slice($logFiles, 0, 3);
        $logFilesToDelete = array_slice($logFiles, 3);

        foreach ($logFilesToDelete as $oldFile) {
            @unlink($oldFile);
        }

        $allLogs = [];

        foreach ($logFilesToRead as $logFile) {
            if (!is_readable($logFile)) continue;

            $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            foreach (array_reverse($lines) as $line) {
                if (!preg_match(
                    '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}) (INFO|WARNING|ERROR|DEBUG|ALERT) (.*?)( CONTEXT: (.*))?$/',
                    $line,
                    $matches
                )) {
                    continue;
                }

                $jalaliDate = DateConverter::utcToJalaliDateTime($matches[1]);
                if ($jalaliDate === null) continue;

                $context = isset($matches[5]) ? json_decode($matches[5], true) : null;

                $allLogs[] = [
                    'date'    => $jalaliDate,
                    'level'   => $matches[2],
                    'message' => $matches[3],
                    'context' => $context,
                ];
            }
        }

        $logsByLevel = [
            'info'    => [],
            'warning' => [],
            'error'   => [],
            'debug'   => [],
            'alert'   => [],
        ];

        foreach ($allLogs as $log) {
            $logsByLevel[strtolower($log['level'])][] = $log;
        }

        return [
            'logs_by_level' => $logsByLevel,
            'current_tab'   => $_GET['tab'] ?? 'info',
            'current_page'  => isset($_GET['paged']) ? max(1, intval(wp_unslash($_GET['paged']))) : 1,
            'per_page'      => 10,
        ];
    }

    public function log($level, $message, $context = [])
    {
        $logger = wc_get_logger();

        $context = array_merge(
            is_array($context) ? $context : [],
            ['source' => 'basalam-sync-plugin']
        );

        $logger->log($level, $message, $context);
    }

    public function info($message, $context = [])    { $this->log('info', $message, $context); }
    public function debug($message, $context = [])   { $this->log('debug', $message, $context); }
    public function warning($message, $context = []) { $this->log('warning', $message, $context); }
    public function error($message, $context = [])   { $this->log('error', $message, $context); }
    public function alert($message, $context = [])   { $this->log('alert', $message, $context); }
}
