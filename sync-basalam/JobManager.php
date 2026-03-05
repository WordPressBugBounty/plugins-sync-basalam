<?php

namespace SyncBasalam;

defined('ABSPATH') || exit;

class JobManager
{
    private $jobManagerTableName;

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

        return $wpdb->insert(
            $this->jobManagerTableName,
            array(
                'job_type'      => $jobType,
                'status'        => $status,
                'payload'       => $payload,
                'attempts'      => 0,
                'max_attempts'  => $maxAttempts,
                'created_at'    => time(),
            )
        );
    }

    public function getNextEligibleJob(string $jobType): ?object
    {
        global $wpdb;

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

    public function getJob($where = array())
    {
        global $wpdb;

        if (empty($where)) return null;

        $conditions = [];
        $values     = [];

        foreach ($where as $column => $value) {
            if (!in_array($column, self::ALLOWED_COLUMNS, true)) {
                throw new \InvalidArgumentException("Invalid column: {$column}");
            }
            $conditions[] = "{$column} = %s";
            $values[]     = $value;
        }

        $sql = "SELECT * FROM {$this->jobManagerTableName} WHERE " . implode(" AND ", $conditions) . " LIMIT 1";

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
                throw new \InvalidArgumentException("Invalid column: {$column}");
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

        return (int) $wpdb->get_var($wpdb->prepare($sql, $values));
    }

    public function updateJob($jobData, $where = array())
    {
        global $wpdb;

        if (empty($where) || empty($jobData)) return false;

        return $wpdb->update($this->jobManagerTableName, $jobData, $where);
    }

    public function deleteJob($where = array())
    {
        global $wpdb;

        if (empty($where)) return false;

        return $wpdb->delete($this->jobManagerTableName, $where);
    }

    public function deleteStaleProcessingJobs($timeoutSeconds = 120)
    {
        global $wpdb;

        $timeoutTimestamp = time() - $timeoutSeconds;

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->jobManagerTableName}
                SET status = 'pending', started_at = NULL
                WHERE status = 'processing'
                AND job_type = 'sync_basalam_bulk_update_products'
                AND started_at IS NOT NULL
                AND started_at < %d",
                $timeoutTimestamp
            )
        );

        $sql = $wpdb->prepare(
            "DELETE FROM {$this->jobManagerTableName}
            WHERE status = %s
            AND started_at IS NOT NULL
            AND started_at < %d",
            'processing',
            $timeoutTimestamp
        );

        return $wpdb->query($sql);
    }

    public function hasProductJobInProgress(int $productId, string $jobType): bool
    {
        global $wpdb;

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
        global $wpdb;

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
        $wpdb->query('START TRANSACTION');
        try {
            $wpdb->delete($this->jobManagerTableName, ['id' => $jobId]);

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

            $wpdb->query('COMMIT');
        } catch (\Exception $e) {
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
