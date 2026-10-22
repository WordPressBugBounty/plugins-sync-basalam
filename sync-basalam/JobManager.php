<?php

namespace SyncBasalam;

defined('ABSPATH') || exit;

class JobManager
{
    private $jobManagerTableName;
    private $executingJob;

    private const PRODUCT_JOB_GROUPS = [
        'create' => ['sync_basalam_create_single_product', 'sync_basalam_create_all_products'],
        'update' => ['sync_basalam_bulk_update_products', 'sync_basalam_update_all_products', 'sync_basalam_update_single_product'],
    ];

    private const PRODUCT_ROOT_JOB_TYPES = [
        'create' => ['sync_basalam_create_all_products'],
        'update' => ['sync_basalam_bulk_update_products', 'sync_basalam_update_all_products'],
    ];

    private const ALLOWED_COLUMNS = [
        'id', 'job_type', 'status', 'payload',
        'attempts', 'max_attempts', 'retry_after',
        'started_at', 'created_at', 'failed_at', 'error_message',
    ];

    function __construct()
    {
        global $wpdb;
        $this->jobManagerTableName = $wpdb->prefix . 'sync_basalam_job_manager';
    }

    public function createJob($jobType, $status = 'pending', $payload = null, $maxAttempts = 3)
    {
        global $wpdb;

        // Queue payloads historically accepted JSON strings, scalar cursors
        // and null. Normalize the value before inspecting it so the admission
        // check cannot throw a TypeError for a legacy integer cursor (the quick
        // update action uses 0 as its initial cursor).
        $storedPayload = $this->normalizePayload($payload);

        $result = $this->withProductQueueLock($this->productJobGroup($jobType), function () use ($jobType, $status, $storedPayload, $maxAttempts) {
            return $this->insertJobUnlocked($jobType, $status, $storedPayload, $maxAttempts);
        });

        if ($result !== false) {
            do_action('sync_basalam_job_created', $jobType, $status, $payload);
        }

        return $result;
    }

    /**
     * Atomically enqueue a manual or scheduled product operation root.
     *
     * The caller's preflight check is not enough: another request can move a
     * root from pending to processing between that check and the insert. The
     * same short advisory lock used for queue writes makes the active-root
     * check and insert one operation.
     *
     * @return string created|exists|failed
     */
    public function createProductRootJob($jobType, $status = 'pending', $payload = null, $maxAttempts = 3): string
    {
        $group = $this->productJobGroup($jobType);
        if ($group === null || !in_array($jobType, self::PRODUCT_ROOT_JOB_TYPES[$group], true)) {
            return 'failed';
        }

        $storedPayload = $this->normalizePayload($payload);
        $result = $this->withProductQueueLock($group, function () use ($jobType, $status, $storedPayload, $maxAttempts, $group) {
            $activeRootCount = $this->getCountJobs([
                'job_type' => self::PRODUCT_ROOT_JOB_TYPES[$group],
                'status' => ['pending', 'processing'],
            ]);

            if ($activeRootCount > 0) return 'exists';

            $inserted = $this->insertJobUnlocked($jobType, $status, $storedPayload, $maxAttempts);
            return $inserted === false ? 'failed' : 'created';
        });

        if ($result === 'created') {
            do_action('sync_basalam_job_created', $jobType, $status, $payload);
            return 'created';
        }

        return in_array($result, ['exists', 'failed'], true) ? $result : 'failed';
    }

    /** Keep every queue row JSON-decodable while preserving legacy cursors. */
    private function normalizePayload($payload): ?string
    {
        if ($payload === null || $payload === '') return null;
        if (is_string($payload)) return $payload;

        $encoded = function_exists('wp_json_encode')
            ? wp_json_encode($payload)
            : json_encode($payload);

        return $encoded === false ? null : $encoded;
    }

    private function insertJobUnlocked($jobType, $status, ?string $storedPayload, $maxAttempts)
    {
        global $wpdb;

        $group = $this->productJobGroup($jobType);
        $decodedPayload = json_decode($storedPayload ?? '{}', true);
        if (!is_array($decodedPayload)) $decodedPayload = [];
        if ($group !== null && !\SyncBasalam\Admin\Settings\SettingsManager::allowsProductJob($group, $decodedPayload)) {
            return false;
        }

        // An old worker must not enqueue children or a continuation after
        // its exact parent was cancelled, even if a new operation started.
        if ($this->executingJob
            && $group !== null
            && $group === $this->productJobGroup($this->executingJob->job_type)
            && !$this->activeJobExists($this->executingJob->id)) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom queue table.
        return $wpdb->insert($this->jobManagerTableName, [
            'job_type' => $jobType,
            'status' => $status,
            'payload' => $storedPayload,
            'attempts' => 0,
            'max_attempts' => $maxAttempts,
            'created_at' => time(),
        ]);
    }

    /** Keep the parent identity available to every enqueue during execution. */
    public function runForJob(object $job, callable $callback): bool
    {
        $previousJob = $this->executingJob;
        $this->executingJob = $job;
        try {
            if (!$this->activeJobExists($job->id)) return true;
            return $callback();
        } finally {
            $this->executingJob = $previousJob;
        }
    }

    public function cancelProductJobs(string $operation)
    {
        if (!isset(self::PRODUCT_JOB_GROUPS[$operation])) {
            throw new \InvalidArgumentException('Unknown product operation.');
        }
        return $this->withProductQueueLock($operation, function () use ($operation) {
            global $wpdb;
            // Disable independent producers before clearing the queue. Admission
            // rechecks persisted settings under this same lock, including for
            // requests that read enabled settings before the stop.
            if (!\SyncBasalam\Admin\Settings\SettingsManager::stopProductSync($operation)) return false;
            $types = self::PRODUCT_JOB_GROUPS[$operation];
            $placeholders = implode(', ', array_fill(0, count($types), '%s'));
            // One deletion covers the parent and all children under the same
            // short lock used by enqueue/retry; no network call holds this lock.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Internal table and placeholder list; values prepared.
            return $wpdb->query($wpdb->prepare(
                "DELETE FROM {$this->jobManagerTableName} WHERE job_type IN ($placeholders) AND status IN ('pending', 'processing')",
                $types
            ));
        });
    }

    private function activeJobExists(int $id): bool
    {
        $job = $this->getJob(['id' => $id]);
        return $job && in_array($job->status, ['pending', 'processing'], true);
    }

    private function productJobGroup(string $jobType): ?string
    {
        foreach (self::PRODUCT_JOB_GROUPS as $group => $types) {
            if (in_array($jobType, $types, true)) return $group;
        }
        return null;
    }

    private function withProductQueueLock(?string $group, callable $callback)
    {
        if ($group === null) return $callback();
        global $wpdb;
        // Include the database too: table prefixes repeat across shared hosts.
        $name = \SyncBasalam\Jobs\LockManager::name('queue:' . $group, $this->jobManagerTableName);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Short queue mutation advisory lock.
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, 5)) !== '1') return false;
        try {
            return $callback();
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Release only our queue mutation lock.
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }

    public function getNextEligibleJob(string $jobType): ?object
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; identifier from $wpdb->prefix, not user input.
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->jobManagerTableName}
             WHERE job_type = %s
               AND status = 'pending'
               AND (retry_after IS NULL OR retry_after <= %d)
             ORDER BY id ASC
             LIMIT 1",
            $jobType,
            time()
        ));
    }

    public function hasAnyProcessingJob(): bool
    {
        return $this->getCountJobs(['status' => 'processing']) > 0;
    }

    public function getProductUpdateStatus(): array
    {
        $quickJob = $this->getJob(['job_type' => 'sync_basalam_bulk_update_products', 'status' => 'processing'])
            ?: $this->getJob(['job_type' => 'sync_basalam_bulk_update_products', 'status' => 'pending']);
        $fullJob = $this->getJob(['job_type' => 'sync_basalam_update_all_products', 'status' => 'processing'])
            ?: $this->getJob(['job_type' => 'sync_basalam_update_all_products', 'status' => 'pending']);
        $singleCount = $this->getCountJobs([
            'job_type' => 'sync_basalam_update_single_product',
            'status' => ['pending', 'processing'],
        ]);

        return [
            'active' => (bool) ($quickJob || $fullJob || $singleCount > 0),
            'type' => $quickJob ? 'quick' : (($fullJob || $singleCount > 0) ? 'full' : ''),
            'status' => $quickJob ? $quickJob->status : ($fullJob ? $fullJob->status : ''),
            'count' => $singleCount,
        ];
    }

    public function getProductCreateStatus(): array
    {
        $parent = $this->getJob(['job_type' => 'sync_basalam_create_all_products', 'status' => 'processing'])
            ?: $this->getJob(['job_type' => 'sync_basalam_create_all_products', 'status' => 'pending']);
        $count = $this->getCountJobs([
            'job_type' => 'sync_basalam_create_single_product',
            'status' => ['pending', 'processing'],
        ]);

        return ['active' => (bool) ($parent || $count > 0), 'count' => $count];
    }

    public function hasPendingOrStaleProcessingJobs(int $staleProcessingTimeoutSeconds = 120): bool
    {
        global $wpdb;

        $now = time();
        $staleBefore = $now - $staleProcessingTimeoutSeconds;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; identifier from $wpdb->prefix, not user input.
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$this->jobManagerTableName}
             WHERE (
                 status = 'pending'
                 AND (retry_after IS NULL OR retry_after <= %d)
             ) OR (
                 status = 'processing'
                 AND started_at IS NOT NULL
                 AND started_at < %d
             )
             LIMIT 1",
            $now,
            $staleBefore
        ));

        return (string) $result === '1';
    }

    public function getStaleProcessingJob(int $timeoutSeconds = 120): ?object
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom queue table; identifier from the WordPress prefix, values prepared.
        return $wpdb->get_row($wpdb->prepare(
            "SELECT id, started_at FROM {$this->jobManagerTableName}
             WHERE status = 'processing'
               AND started_at IS NOT NULL
               AND started_at < %d
             ORDER BY started_at ASC, id ASC LIMIT 1",
            time() - $timeoutSeconds
        ));
    }

    public function getJob($where = array())
    {
        global $wpdb;

        if (empty($where)) return null;

        $conditions = [];
        $values     = [];

        foreach ($where as $column => $value) {
            if (!in_array($column, self::ALLOWED_COLUMNS, true)) {
                throw new \InvalidArgumentException(esc_html("Invalid column: {$column}"));
            }
            $conditions[] = "{$column} = %s";
            $values[]     = $value;
        }

        $sql = "SELECT * FROM {$this->jobManagerTableName} WHERE " . implode(" AND ", $conditions) . " LIMIT 1";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; identifiers from $wpdb->prefix and whitelisted column list, not user input; values are prepared.
        return $wpdb->get_row($wpdb->prepare($sql, $values));
    }

    public function getCountJobs($where = array())
    {
        global $wpdb;

        if (empty($where)) return 0;

        $conditions = [];
        $values     = [];

        foreach ($where as $column => $value) {
            if (!in_array($column, self::ALLOWED_COLUMNS, true)) {
                throw new \InvalidArgumentException(esc_html("Invalid column: {$column}"));
            }
            if (is_array($value)) {
                $placeholders = array_fill(0, count($value), '%s');
                $conditions[] = "{$column} IN (" . implode(',', $placeholders) . ")";
                $values = array_merge($values, $value);
            } else {
                $conditions[] = "{$column} = %s";
                $values[]     = $value;
            }
        }

        $sql = "SELECT COUNT(*) FROM {$this->jobManagerTableName} WHERE " . implode(" AND ", $conditions);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; identifiers from $wpdb->prefix and whitelisted column list, not user input; values are prepared.
        return (int) $wpdb->get_var($wpdb->prepare($sql, $values));
    }

    public function updateJob($jobData, $where = array())
    {
        global $wpdb;

        if (empty($where) || empty($jobData)) return false;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; no object cache for these operational queries.
        return $wpdb->update($this->jobManagerTableName, $jobData, $where);
    }

    public function deleteJob($where = array())
    {
        global $wpdb;

        if (empty($where)) return false;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; no object cache for these operational queries.
        return $wpdb->delete($this->jobManagerTableName, $where);
    }

    public function ConvertStaleProcessingJobs($timeoutSeconds = 120)
    {
        global $wpdb;

        $timeoutTimestamp = time() - $timeoutSeconds;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; identifier from $wpdb->prefix, not user input.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->jobManagerTableName}
                SET status = CASE
                        WHEN attempts + 1 >= max_attempts THEN 'failed'
                        ELSE 'pending'
                    END,
                    attempts = attempts + 1,
                    started_at = NULL,
                    failed_at = CASE
                        WHEN attempts + 1 >= max_attempts THEN %d
                        ELSE failed_at
                    END
                WHERE status = 'processing'
                AND started_at IS NOT NULL
                AND started_at < %d",
                time(),
                $timeoutTimestamp
            )
        );
    }

    public function hasProductJobInProgress(int $productId, string $jobType): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; identifier from $wpdb->prefix, not user input.
        $jobs = $wpdb->get_results($wpdb->prepare(
            "SELECT payload FROM {$this->jobManagerTableName}
            WHERE job_type = %s
            AND (status = %s OR status = %s)",
            $jobType,
            'pending',
            'processing'
        ));

        if (empty($jobs)) {
            return false;
        }

        foreach ($jobs as $job) {
            $payload = json_decode($job->payload, true);
            $jobProductId = $payload['product_id'] ?? $payload;

            if (intval($jobProductId) === intval($productId)) {
                return true;
            }
        }

        return false;
    }

    public function retryJob(int $jobId, ?string $errorMessage = null): bool
    {
        $job = $this->getJob(['id' => $jobId]);
        if (!$job) return false;
        // Re-read inside the lock: cancellation may win after the first read.
        return $this->withProductQueueLock($this->productJobGroup($job->job_type), function () use ($jobId, $errorMessage) {
            return $this->retryExistingJob($jobId, $errorMessage);
        });
    }

    private function retryExistingJob(int $jobId, ?string $errorMessage = null): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; identifier from $wpdb->prefix, not user input.
        $job = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->jobManagerTableName} WHERE id = %d",
            $jobId
        ));

        if (!$job) return false;

        $newAttempts = intval($job->attempts) + 1;

        $errorMessages = [];
        if (!empty($job->error_message)) {
            $decoded = json_decode($job->error_message, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) $errorMessages = $decoded;
        }

        if ($errorMessage) $errorMessages[$newAttempts] = $errorMessage;

        $encodedErrors = json_encode($errorMessages, JSON_UNESCAPED_UNICODE);

        if ($newAttempts >= intval($job->max_attempts)) {
            $this->updateJob(
                [
                    'status'        => 'failed',
                    'error_message' => $encodedErrors,
                    'failed_at'     => time(),
                    'started_at'    => 0,
                    'attempts'      => $newAttempts,
                ],
                ['id' => $jobId]
            );
            return false;
        }

        // Progressive exponential backoff: 30s, 60s, 120s, 240s, ...
        $delaySeconds = 30 * (int) pow(2, $newAttempts - 1);
        $retryAfter   = time() + $delaySeconds;

        // Atomic DELETE + INSERT inside a transaction so a crash can't lose the job.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control for atomic job requeue; no object cache applicable.
        $wpdb->query('START TRANSACTION');
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; no object cache for these operational queries.
            $wpdb->delete($this->jobManagerTableName, ['id' => $jobId]);

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; no object cache for these operational queries.
            $wpdb->insert(
                $this->jobManagerTableName,
                [
                    'job_type'      => $job->job_type,
                    'status'        => 'pending',
                    'payload'       => $job->payload,
                    'attempts'      => $newAttempts,
                    'max_attempts'  => $job->max_attempts,
                    'error_message' => $encodedErrors,
                    'created_at'    => $job->created_at,
                    'retry_after'   => $retryAfter,
                    'started_at'    => 0,
                ]
            );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control for atomic job requeue; no object cache applicable.
            $wpdb->query('COMMIT');
        } catch (\Exception $e) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control for atomic job requeue; no object cache applicable.
            $wpdb->query('ROLLBACK');
            throw $e;
        }

        return true;
    }

    public function failJob(int $jobId, ?string $errorMessage = null): bool
    {
        return $this->updateJob(
            [
                'status' => 'failed',
                'error_message' => $errorMessage,
                'failed_at' => time(),
                'started_at' => 0,
            ],
            ['id' => $jobId]
        );
    }

}
