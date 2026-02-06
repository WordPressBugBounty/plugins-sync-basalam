<?php

namespace SyncBasalam;

defined('ABSPATH') || exit;

class JobManager
{
    private static $instance = null;
    private $jobManagerTableName;

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    function __construct()
    {
        global $wpdb;
        $this->jobManagerTableName = $wpdb->prefix . 'sync_basalam_job_manager';
    }

    public function createJob($jobType, $status = 'pending', $payload = null)
    {
        global $wpdb;

        $wpdb->insert(
            $this->jobManagerTableName,
            array(
                'job_type'      => $jobType,
                'status'        => $status,
                'payload'       => $payload,
                'created_at'    => time(),
            )
        );

        return $wpdb;
    }

    public function getJob($where = array())
    {
        global $wpdb;

        if (empty($where)) return null;

        $conditions = [];
        $values     = [];

        foreach ($where as $column => $value) {
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

    /**
     * Check if a product job is already in progress
     *
     * @param int $productId
     * @param string $jobType
     * @return bool
     */
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
}
