<?php

/**
 * Manual benchmark for the async jobs runner.
 *
 * Run from a PHP/WordPress-compatible CLI image with a MariaDB service named
 * sync-basalam-bench-db. This file intentionally is not named *Test.php so it
 * is not picked up by PHPUnit.
 */

namespace SyncBasalam\Jobs {
    defined('ABSPATH') || define('ABSPATH', '/var/www/html/');
    require dirname(__DIR__) . '/includes/Jobs/JobType.php';
}

namespace SyncBasalam\Admin {
    class Settings
    {
        public static function getEffectiveTasksPerMinute()
        {
            return 1000000000;
        }
    }
}

namespace SyncBasalam\Benchmark {
    class JobType implements \SyncBasalam\Jobs\JobType
    {
        public function getType(): string
        {
            return 'benchmark';
        }

        public function getPriority(): int
        {
            return 1;
        }

        public function execute(array $payload)
        {
            return null;
        }

        public function canRun(): bool
        {
            return true;
        }
    }

    class DiscountScheduler
    {
        public function process(): void {}
    }

    class HttpBlockService
    {
        public function SyncBasalamHttpBlock()
        {
            return false;
        }
    }
}

namespace {
    defined('ABSPATH') || define('ABSPATH', '/var/www/html/');
    define('WP_DEBUG', false);

    $GLOBALS['bench_current_filter'] = '';
    $GLOBALS['bench_doing_ajax'] = false;
    $GLOBALS['bench_fastcgi_boundary'] = null;

    function apply_filters($hook, $value)
    {
        if ($hook === 'query') {
            return preg_replace('/\{[a-f0-9]{64}\}/', '%', $value);
        }

        return $value;
    }

    function add_filter($hook, $callback, $priority = 10, $acceptedArgs = 1) {}

    function has_filter($hook, $callback = false)
    {
        return false;
    }

    function is_wp_error($value)
    {
        return false;
    }

    function mbstring_binary_safe_encoding() {}

    function reset_mbstring_encoding() {}

    function add_action($hook, $callback, $priority = 10, $acceptedArgs = 1) {}

    function do_action($hook, ...$args) {}

    function current_filter()
    {
        return $GLOBALS['bench_current_filter'];
    }

    function fastcgi_finish_request()
    {
        $GLOBALS['bench_fastcgi_boundary'] = hrtime(true);

        return true;
    }

    function wp_doing_ajax()
    {
        return $GLOBALS['bench_doing_ajax'];
    }

    function wp_unslash($value)
    {
        return $value;
    }

    function sanitize_key($key)
    {
        return strtolower((string) $key);
    }

    function admin_url($path = '')
    {
        return 'https://bench.invalid/wp-admin/' . ltrim($path, '/');
    }

    function add_query_arg($key, $value, $url)
    {
        return $url . '?' . $key . '=' . rawurlencode((string) $value);
    }

    function esc_url_raw($url)
    {
        return $url;
    }

    function wp_create_nonce($action)
    {
        return 'benchmark-nonce';
    }

    function wp_remote_post($url, $args = [])
    {
        return ['response' => ['code' => 200]];
    }

    function get_option($name, $default = false)
    {
        global $wpdb;

        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $name
        ));

        if ($value === null) {
            return $default;
        }

        $decoded = @unserialize($value);

        return $decoded === false && $value !== 'b:0;' ? $value : $decoded;
    }

    function update_option($name, $value, $autoload = null)
    {
        global $wpdb;

        $encoded = serialize($value);
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $name
        ));

        if ($exists === null) {
            return $wpdb->insert(
                $wpdb->options,
                [
                    'option_name' => $name,
                    'option_value' => $encoded,
                    'autoload' => $autoload === false ? 'no' : 'yes',
                ]
            ) !== false;
        }

        return $wpdb->update(
            $wpdb->options,
            ['option_value' => $encoded],
            ['option_name' => $name]
        ) !== false;
    }

    function delete_option($name)
    {
        global $wpdb;

        return $wpdb->delete($wpdb->options, ['option_name' => $name]) !== false;
    }

    function get_transient($name)
    {
        $timeout = get_option('_transient_timeout_' . $name, false);

        if ($timeout !== false && (int) $timeout < time()) {
            delete_option('_transient_' . $name);
            delete_option('_transient_timeout_' . $name);

            return false;
        }

        return get_option('_transient_' . $name, false);
    }

    function set_transient($name, $value, $expiration = 0)
    {
        update_option('_transient_' . $name, $value, false);

        if ($expiration > 0) {
            update_option('_transient_timeout_' . $name, time() + (int) $expiration, false);
        }

        return true;
    }

    require '/var/www/html/wp-includes/class-wpdb.php';
    require dirname(__DIR__) . '/JobManager.php';
    require dirname(__DIR__) . '/includes/Services/Api/CircuitBreaker.php';
    require dirname(__DIR__) . '/includes/Jobs/JobResult.php';
    require dirname(__DIR__) . '/includes/Jobs/Exceptions/JobException.php';
    require dirname(__DIR__) . '/includes/Jobs/Exceptions/RetryableException.php';
    require dirname(__DIR__) . '/includes/Jobs/Exceptions/NonRetryableException.php';
    require dirname(__DIR__) . '/includes/Jobs/LockManager.php';
    require dirname(__DIR__) . '/includes/Jobs/JobRegistry.php';
    require dirname(__DIR__) . '/includes/Jobs/JobExecutor.php';
    require dirname(__DIR__) . '/JobsRunner.php';

    $wpdb = new \wpdb('bench', 'bench', 'bench', 'sync-basalam-bench-db');
    $wpdb->prefix = 'wp_';
    $wpdb->options = 'wp_options';
    $wpdb->query('CREATE TABLE IF NOT EXISTS wp_options (option_id bigint unsigned NOT NULL AUTO_INCREMENT, option_name varchar(191) NOT NULL, option_value longtext NOT NULL, autoload varchar(20) NOT NULL DEFAULT \'yes\', PRIMARY KEY (option_id), UNIQUE KEY option_name (option_name)) ENGINE=InnoDB');
    $wpdb->query('CREATE TABLE IF NOT EXISTS wp_sync_basalam_job_manager (id bigint unsigned NOT NULL AUTO_INCREMENT, job_type varchar(191) NOT NULL, status varchar(30) NOT NULL, payload longtext NULL, attempts int NOT NULL DEFAULT 0, max_attempts int NOT NULL DEFAULT 3, retry_after int NULL, started_at int NULL, created_at int NOT NULL, failed_at int NULL, error_message longtext NULL, PRIMARY KEY (id), KEY job_type_status (job_type, status)) ENGINE=InnoDB');

    $jobManager = new \SyncBasalam\JobManager();
    $jobType = new \SyncBasalam\Benchmark\JobType();
    $registry = new \SyncBasalam\Jobs\JobRegistry([$jobType]);
    $executor = new \SyncBasalam\Jobs\JobExecutor(
        $jobManager,
        new \SyncBasalam\Jobs\LockManager(),
        $registry
    );
    $runner = new \SyncBasalam\JobsRunner(
        $jobManager,
        $executor,
        new \SyncBasalam\Benchmark\DiscountScheduler(),
        new \SyncBasalam\Benchmark\HttpBlockService()
    );
    $runBatch = new \ReflectionMethod($runner, 'runAsyncBatch');
    $runBatch->setAccessible(true);

    function seed_jobs(int $count): void
    {
        global $wpdb;

        $wpdb->query('DELETE FROM wp_sync_basalam_job_manager');
        $wpdb->query("DELETE FROM wp_options WHERE option_name IN ('sync_basalam_jobs_runner_last_run', '_transient_sync_basalam_jobs_runner_async_dispatch_lock', '_transient_timeout_sync_basalam_jobs_runner_async_dispatch_lock')");

        for ($i = 0; $i < $count; $i++) {
            $wpdb->insert(
                'wp_sync_basalam_job_manager',
                [
                    'job_type' => 'benchmark',
                    'status' => 'pending',
                    'payload' => '{}',
                    'attempts' => 0,
                    'max_attempts' => 3,
                    'created_at' => time(),
                ]
            );
        }
    }

    function reset_dispatch_state(): void
    {
        delete_option('_transient_sync_basalam_jobs_runner_async_dispatch_lock');
        delete_option('_transient_timeout_sync_basalam_jobs_runner_async_dispatch_lock');
    }

    function leave_unbuffered_result_open(): void
    {
        global $wpdb;

        $GLOBALS['bench_unbuffered_result'] = mysqli_query(
            $wpdb->dbh,
            'SELECT 1 AS value UNION ALL SELECT 2 AS value',
            MYSQLI_USE_RESULT
        );

        if (!($GLOBALS['bench_unbuffered_result'] instanceof \mysqli_result)) {
            throw new \RuntimeException('Could not create the unbuffered benchmark result.');
        }
    }

    function stats(string $label, int $iterations, callable $fn): void
    {
        for ($i = 0; $i < min(10, $iterations); $i++) {
            $fn();
        }

        $samples = [];
        $boundarySamples = [];

        for ($i = 0; $i < $iterations; $i++) {
            $GLOBALS['bench_fastcgi_boundary'] = null;
            $start = hrtime(true);
            $fn();
            $end = hrtime(true);
            $samples[] = ($end - $start) / 1000.0;

            if ($GLOBALS['bench_fastcgi_boundary'] !== null) {
                $boundarySamples[] = ($GLOBALS['bench_fastcgi_boundary'] - $start) / 1000.0;
            }
        }

        sort($samples);
        sort($boundarySamples);

        $sum = array_sum($samples);
        $percentile = static function (array $values, float $p) {
            if (!$values) {
                return null;
            }

            return $values[(int) floor((count($values) - 1) * $p)];
        };

        $mean = $sum / $iterations;
        $line = sprintf(
            "%-34s n=%5d mean=%9.2f us p50=%9.2f p95=%9.2f p99=%9.2f min=%9.2f max=%9.2f ops/s=%9.1f",
            $label,
            $iterations,
            $mean,
            $percentile($samples, 0.50),
            $percentile($samples, 0.95),
            $percentile($samples, 0.99),
            $samples[0],
            $samples[count($samples) - 1],
            1000000.0 / $mean
        );

        if ($boundarySamples) {
            $boundary = $percentile($boundarySamples, 0.50);
            $line .= sprintf(
                ' response-boundary-p50=%9.2f us post-boundary-p50=%9.2f us',
                $boundary,
                $percentile($samples, 0.50) - $boundary
            );
        }

        echo $line, PHP_EOL;
    }

    function run_batch(int $count, \ReflectionMethod $runBatch, \SyncBasalam\JobsRunner $runner): void
    {
        global $wpdb;

        seed_jobs($count);
        $processed = $runBatch->invoke($runner);
        $remaining = (int) $wpdb->get_var('SELECT COUNT(*) FROM wp_sync_basalam_job_manager');

        if ($processed !== $count || $remaining !== 0) {
            throw new \RuntimeException("Batch verification failed: processed={$processed}, remaining={$remaining}");
        }
    }

    echo "JOB EXECUTION (real JobExecutor + real wpdb/MariaDB)\n";

    foreach ([[1, 1000], [5, 500], [20, 200], [100, 50], [250, 20]] as [$count, $iterations]) {
        stats("async batch {$count} jobs", $iterations, function () use ($count, $runBatch, $runner) {
            run_batch($count, $runBatch, $runner);
        });
    }

    echo "DISPATCH PATHS (real wpdb/MariaDB)\n";

    $GLOBALS['bench_doing_ajax'] = false;
    $GLOBALS['bench_request'] = [];
    $GLOBALS['bench_current_filter'] = '';
    seed_jobs(0);

    stats('frontend request / no shutdown work', 5000, function () use ($runner) {
        reset_dispatch_state();
        $runner->maybeDispatchAsyncRequest();
    });

    stats('admin request / no shutdown work', 5000, function () use ($runner) {
        reset_dispatch_state();
        $runner->maybeDispatchAsyncRequest();
    });

    $GLOBALS['bench_current_filter'] = 'shutdown';

    stats('shutdown request / healthy connection', 2000, function () use ($runner) {
        reset_dispatch_state();
        $runner->maybeDispatchAsyncRequest();
    });

    stats('shutdown / pending unbuffered result', 500, function () use ($runner) {
        reset_dispatch_state();
        leave_unbuffered_result_open();
        try {
            $runner->maybeDispatchAsyncRequest();

            global $wpdb;
            if ($wpdb->get_var('SELECT 1') !== '1') {
                throw new \RuntimeException('Database connection was not repaired after error 2014.');
            }
        } finally {
            unset($GLOBALS['bench_unbuffered_result']);
        }
    });

    $GLOBALS['bench_current_filter'] = '';
    $GLOBALS['bench_doing_ajax'] = true;
    $_REQUEST['action'] = 'sync_basalam_run_jobs_async';

    stats('async worker self-dispatch guard', 10000, function () use ($runner) {
        $runner->maybeDispatchAsyncRequest();
    });

    echo "DONE\n";
}
