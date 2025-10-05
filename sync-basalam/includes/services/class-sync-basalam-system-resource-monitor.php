<?php
if (!defined('ABSPATH')) exit;

class Sync_Basalam_System_Resource_Monitor
{
    private static $instance = null;

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get memory limit in bytes
     */
    private function get_memory_limit()
    {
        $memory_limit = ini_get('memory_limit');

        if ($memory_limit == -1) {
            return PHP_INT_MAX; // Unlimited
        }

        $unit = strtoupper(substr($memory_limit, -1));
        $value = (int) $memory_limit;

        switch ($unit) {
            case 'G':
                $value *= 1024;
            case 'M':
                $value *= 1024;
            case 'K':
                $value *= 1024;
        }

        return $value;
    }

    /**
     * Get current memory usage in bytes
     */
    private function get_memory_usage()
    {
        return memory_get_usage(true);
    }

    /**
     * Get available memory in bytes
     */
    private function get_available_memory()
    {
        $limit = $this->get_memory_limit();
        $usage = $this->get_memory_usage();
        return max(0, $limit - $usage);
    }

    /**
     * Get memory score (0-100)
     */
    private function get_memory_score()
    {
        $limit = $this->get_memory_limit();
        $available = $this->get_available_memory();

        if ($limit == PHP_INT_MAX) {
            return 100; // Unlimited memory
        }

        $usage_percent = (($limit - $available) / $limit) * 100;

        // Higher available memory = higher score
        if ($usage_percent < 30) {
            return 100; // Less than 30% used = excellent
        } elseif ($usage_percent < 50) {
            return 80;  // 30-50% used = good
        } elseif ($usage_percent < 70) {
            return 60;  // 50-70% used = moderate
        } elseif ($usage_percent < 85) {
            return 40;  // 70-85% used = limited
        } else {
            return 20;  // Above 85% used = critical
        }
    }

    /**
     * Get max execution time score (0-100)
     */
    private function get_execution_time_score()
    {
        $max_execution_time = ini_get('max_execution_time');

        if ($max_execution_time == 0) {
            return 100; // Unlimited execution time
        }

        // Score based on execution time limits
        if ($max_execution_time >= 300) {
            return 100; // 5+ minutes
        } elseif ($max_execution_time >= 120) {
            return 80;  // 2-5 minutes
        } elseif ($max_execution_time >= 60) {
            return 60;  // 1-2 minutes
        } elseif ($max_execution_time >= 30) {
            return 40;  // 30-60 seconds
        } else {
            return 20;  // Less than 30 seconds
        }
    }

    /**
     * Get PHP version score (0-100)
     */
    private function get_php_version_score()
    {
        $version = PHP_VERSION_ID;

        if ($version >= 80000) {
            return 100; // PHP 8.0+
        } elseif ($version >= 70400) {
            return 90;  // PHP 7.4+
        } elseif ($version >= 70000) {
            return 70;  // PHP 7.0-7.3
        } else {
            return 40;  // PHP 5.x or older
        }
    }

    /**
     * Get server load score (0-100) - Cross-platform
     */
    private function get_server_load_score()
    {
        // Try to get server load (works on Linux/Unix)
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if ($load && is_array($load)) {
                $load_1min = $load[0];

                // Estimate CPU cores (fallback to 2 if unknown)
                $cpu_cores = $this->estimate_cpu_cores();

                // Calculate load per core
                $load_per_core = $load_1min / $cpu_cores;

                if ($load_per_core < 0.5) {
                    return 100; // Very low load
                } elseif ($load_per_core < 1.0) {
                    return 80;  // Low load
                } elseif ($load_per_core < 2.0) {
                    return 60;  // Moderate load
                } elseif ($load_per_core < 3.0) {
                    return 40;  // High load
                } else {
                    return 20;  // Very high load
                }
            }
        }

        // Fallback: Return moderate score if we can't determine load
        return 60;
    }

    /**
     * Estimate CPU cores using various methods
     */
    private function estimate_cpu_cores()
    {
        // Try WP_CONTENT_DIR for file operations (safe on all platforms)
        $cores = 2; // Default fallback

        // Method 1: Check if nproc info is cached
        $cached_cores = get_transient('sync_basalam_cpu_cores');
        if ($cached_cores !== false) {
            return (int) $cached_cores;
        }

        // Method 2: Try to detect from WordPress environment
        if (defined('WP_MEMORY_LIMIT')) {
            $wp_memory = wp_convert_hr_to_bytes(WP_MEMORY_LIMIT);
            // Estimate cores based on memory (rough approximation)
            // Typical: 512MB = 1 core, 1GB = 2 cores, 2GB = 4 cores, etc.
            if ($wp_memory >= 4 * 1024 * 1024 * 1024) {
                $cores = 8; // 4GB+ likely has 8+ cores
            } elseif ($wp_memory >= 2 * 1024 * 1024 * 1024) {
                $cores = 4; // 2GB+ likely has 4+ cores
            } elseif ($wp_memory >= 1024 * 1024 * 1024) {
                $cores = 2; // 1GB+ likely has 2+ cores
            }
        }

        // Method 3: Estimate from memory_limit
        $memory_limit = $this->get_memory_limit();
        if ($memory_limit >= 4 * 1024 * 1024 * 1024) {
            $cores = 8;
        } elseif ($memory_limit >= 2 * 1024 * 1024 * 1024) {
            $cores = 4;
        } elseif ($memory_limit >= 512 * 1024 * 1024) {
            $cores = 2;
        }

        // Cache the result for 1 hour
        set_transient('sync_basalam_cpu_cores', $cores, HOUR_IN_SECONDS);

        return $cores;
    }

    /**
     * Get network bandwidth score (0-100)
     */
    private function get_network_score()
    {
        // Try to measure response time to a reliable endpoint
        $start_time = microtime(true);
        $response = wp_remote_get('https://core.basalam.com/', [
            'timeout' => 5,
            'sslverify' => false
        ]);
        $response_time = microtime(true) - $start_time;

        if (is_wp_error($response)) {
            return 50; // Unknown, return moderate
        }

        // Score based on response time (in seconds)
        if ($response_time < 0.1) {
            return 100; // Very fast (< 100ms)
        } elseif ($response_time < 0.3) {
            return 80;  // Fast (100-300ms)
        } elseif ($response_time < 0.7) {
            return 60;  // Moderate (300-700ms)
        } elseif ($response_time < 1.5) {
            return 40;  // Slow (700ms-1.5s)
        } else {
            return 20;  // Very slow (> 1.5s)
        }
    }

    /**
     * Calculate optimal tasks per minute based on system resources
     */
    public function calculate_optimal_tasks_per_minute()
    {
        $scores = [
            'memory' => $this->get_memory_score(),
            'execution_time' => $this->get_execution_time_score(),
            'php_version' => $this->get_php_version_score(),
            'server_load' => $this->get_server_load_score(),
            'network' => $this->get_network_score()
        ];

        $weights = [
            'memory' => 0.25,
            'execution_time' => 0.20,
            'server_load' => 0.20,
            'network' => 0.30,
            'php_version' => 0.05
        ];

        $weighted_score = 0;
        foreach ($scores as $key => $score) {
            $weighted_score += $score * $weights[$key];
        }

        // Convert score (0-100) to tasks per minute (1-60)
        // Score 0-20: 1-5 tasks/min (very limited resources)
        // Score 20-40: 5-15 tasks/min (limited resources)
        // Score 40-60: 15-30 tasks/min (moderate resources)
        // Score 60-80: 30-45 tasks/min (good resources)
        // Score 80-100: 45-60 tasks/min (excellent resources)

        if ($weighted_score < 20) {
            $tasks_per_minute = 1 + (int)(($weighted_score / 20) * 4);
        } elseif ($weighted_score < 40) {
            $tasks_per_minute = 5 + (int)((($weighted_score - 20) / 20) * 10);
        } elseif ($weighted_score < 60) {
            $tasks_per_minute = 10 + (int)((($weighted_score - 40) / 20) * 15);
        } elseif ($weighted_score < 80) {
            $tasks_per_minute = 20 + (int)((($weighted_score - 60) / 20) * 15);
        } else {
            $tasks_per_minute = 30 + (int)((($weighted_score - 80) / 20) * 15);
        }

        return max(1, min(60, $tasks_per_minute));
    }

    /**
     * Calculate optimal batch size for bulk operations (20-200)
     */
    public function calculate_optimal_batch_size($min = 20, $max = 200)
    {
        $scores = [
            'memory' => $this->get_memory_score(),
            'execution_time' => $this->get_execution_time_score(),
            'php_version' => $this->get_php_version_score(),
            'server_load' => $this->get_server_load_score(),
        ];

        $weights = [
            'memory' => 0.40,
            'execution_time' => 0.30,
            'server_load' => 0.25,
            'php_version' => 0.05
        ];

        $weighted_score = 0;
        foreach ($scores as $key => $score) {
            $weighted_score += $score * $weights[$key];
        }

        // Map score (0-100) to batch size (min-max)
        // Score 0-20: min size (very limited resources)
        // Score 20-40: min + 20% range (limited resources)
        // Score 40-60: min + 40% range (moderate resources)
        // Score 60-80: min + 60% range (good resources)
        // Score 80-100: min + 80-100% range (excellent resources)

        $range = $max - $min;
        $batch_size = $min + (int)(($weighted_score / 100) * $range);

        return max($min, min($max, $batch_size));
    }

    /**
     * Get detailed system information for debugging
     */
    public function get_system_info()
    {
        return [
            'optimal_tasks_per_minute' => $this->calculate_optimal_tasks_per_minute(),
            'optimal_batch_size' => $this->calculate_optimal_batch_size()
        ];
    }
}
