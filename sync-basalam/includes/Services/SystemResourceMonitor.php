<?php

namespace SyncBasalam\Services;

defined('ABSPATH') || exit;

class SystemResourceMonitor
{
    private static $instance = null;

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function getMemoryLimit()
    {
        $memoryLimit = ini_get('memory_limit');

        if ($memoryLimit == -1) {
            return PHP_INT_MAX;
        }

        $unit = strtoupper(substr($memoryLimit, -1));
        $value = (int) $memoryLimit;

        switch ($unit) {
            case 'G':
                $value *= 1024;
                // no break
            case 'M':
                $value *= 1024;
                // no break
            case 'K':
                $value *= 1024;
        }

        return $value;
    }

    private function getMemoryUsage()
    {
        return memory_get_usage(true);
    }

    private function getAvailableMemory()
    {
        $limit = $this->getMemoryLimit();
        $usage = $this->getMemoryUsage();

        return max(0, $limit - $usage);
    }

    private function getMemoryScore()
    {
        $limit = $this->getMemoryLimit();
        $available = $this->getAvailableMemory();

        if ($limit == PHP_INT_MAX) return 100;

        $usagePercent = (($limit - $available) / $limit) * 100;

        if ($usagePercent < 30) return 100;
        elseif ($usagePercent < 50) return 80;
        elseif ($usagePercent < 70) return 60;
        elseif ($usagePercent < 85) return 40;
        else return 20;
    }

    private function getExecutionTimeScore()
    {
        $maxExecutionTime = ini_get('max_execution_time');

        if ($maxExecutionTime == 0) return 100;

        if ($maxExecutionTime >= 300) return 100;
        elseif ($maxExecutionTime >= 120) return 80;
        elseif ($maxExecutionTime >= 60) return 60;
        elseif ($maxExecutionTime >= 30) return 40;
        else return 20;
    }

    private function getPhpVersionScore()
    {
        $version = PHP_VERSION_ID;

        if ($version >= 80000) return 100;
        elseif ($version >= 70400) return 90;
        elseif ($version >= 70000) return 70;
        else return 40;
    }

    private function getServerLoadScore()
    {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if ($load && is_array($load)) {
                $load1min = $load[0];

                $cpuCores = $this->estimateCpuCores();

                $loadPerCore = $load1min / $cpuCores;

                if ($loadPerCore < 0.5) return 100;
                elseif ($loadPerCore < 1.0) return 80;
                elseif ($loadPerCore < 2.0) return 60;
                elseif ($loadPerCore < 3.0) return 40;
                else return 20;
            }
        }

        return 60;
    }

    private function estimateCpuCores()
    {
        $cores = 2;

        $cachedCores = get_transient('sync_basalam_cpu_cores');
        if ($cachedCores !== false) {
            return (int) $cachedCores;
        }

        if (defined('WP_MEMORY_LIMIT')) {
            $wpMemory = wp_convert_hr_to_bytes(WP_MEMORY_LIMIT);

            if ($wpMemory >= 4 * 1024 * 1024 * 1024) {
                $cores = 8;
            } elseif ($wpMemory >= 2 * 1024 * 1024 * 1024) {
                $cores = 4;
            } elseif ($wpMemory >= 1024 * 1024 * 1024) {
                $cores = 2;
            }
        }

        $memoryLimit = $this->getMemoryLimit();
        if ($memoryLimit >= 4 * 1024 * 1024 * 1024) {
            $cores = 8;
        } elseif ($memoryLimit >= 2 * 1024 * 1024 * 1024) {
            $cores = 4;
        } elseif ($memoryLimit >= 512 * 1024 * 1024) {
            $cores = 2;
        }

        set_transient('sync_basalam_cpu_cores', $cores, HOUR_IN_SECONDS);

        return $cores;
    }

    public function calculateOptimalTasksPerMinute()
    {
        $scores = [
            'memory'         => $this->getMemoryScore(),
            'execution_time' => $this->getExecutionTimeScore(),
            'php_version'    => $this->getPhpVersionScore(),
            'server_load'    => $this->getServerLoadScore(),
        ];

        $weights = [
            'memory'         => 0.25,
            'execution_time' => 0.20,
            'server_load'    => 0.20,
            'php_version'    => 0.05,
        ];

        $weightedScore = 0;
        foreach ($scores as $key => $score) {
            $weightedScore += $score * $weights[$key];
        }

        if ($weightedScore < 20) {
            $tasksPerMinute = 1 + (int) (($weightedScore / 20) * 4);
        } elseif ($weightedScore < 40) {
            $tasksPerMinute = 3 + (int) ((($weightedScore - 20) / 20) * 10);
        } elseif ($weightedScore < 60) {
            $tasksPerMinute = 7 + (int) ((($weightedScore - 40) / 20) * 15);
        } elseif ($weightedScore < 80) {
            $tasksPerMinute = 10 + (int) ((($weightedScore - 60) / 20) * 15);
        } else {
            $tasksPerMinute = 12 + (int) ((($weightedScore - 80) / 20) * 15);
        }

        return max(1, min(60, $tasksPerMinute));
    }

    public function getSystemInfo()
    {
        return [
            'optimal_tasks_per_minute' => $this->calculateOptimalTasksPerMinute(),
        ];
    }
}
