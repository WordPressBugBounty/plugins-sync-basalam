<?php

namespace SyncBasalam\Services\Products\Discount;

defined('ABSPATH') || exit;
class DiscountTaskModel
{
    private $wpdb;
    private $tableName;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->tableName = $wpdb->prefix . 'sync_basalam_discount_tasks';
    }

    public function create(array $data)
    {
        $productId = $data['product_id'] ?? null;
        $variationId = $data['variation_id'] ?? null;
        $discountPercent = $data['discount_percent'] ?? 0;
        $activeDays = $data['active_days'] ?? 7;
        $action = $data['action'];
        $scheduledAt = $data['scheduled_at'] ?? current_time('mysql');
        $status = $data['status'] ?? self::STATUS_PENDING;

        $result = $this->wpdb->insert(
            $this->tableName,
            [
                'product_id'       => $productId,
                'variation_id'     => $variationId,
                'discount_percent' => $discountPercent,
                'active_days'      => $activeDays,
                'action'           => $action,
                'status'           => $status,
                'scheduled_at'     => $scheduledAt,
                'created_at'       => current_time('mysql'),
            ],
            ['%s', '%s', '%f', '%d', '%s', '%s', '%s', '%s']
        );

        return $result ? $this->wpdb->insert_id : false;
    }

    public function getPendingTasks()
    {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->tableName}
             WHERE status = %s 
             AND scheduled_at <= %s 
             ORDER BY scheduled_at ASC",
            self::STATUS_PENDING,
            current_time('mysql')
        );

        return $this->wpdb->get_results($sql);
    }

    public function getTasksByDiscountPercent($discountPercent)
    {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->tableName}
             WHERE status = %s
             AND discount_percent = %f
             AND scheduled_at <= %s
             ORDER BY created_at ASC",
            self::STATUS_PENDING,
            $discountPercent,
            current_time('mysql')
        );

        return $this->wpdb->get_results($sql);
    }

    public function getGroupedPendingTasks()
    {
        $sql = $this->wpdb->prepare(
            "SELECT discount_percent, active_days, action, COUNT(*) as count,
                    GROUP_CONCAT(DISTINCT id) as task_ids,
                    GROUP_CONCAT(DISTINCT product_id) as product_ids,
                    GROUP_CONCAT(DISTINCT variation_id) as variation_ids
             FROM {$this->tableName} 
             WHERE status = %s 
             AND scheduled_at <= %s 
             GROUP BY discount_percent, active_days, action
             ORDER BY action ASC, discount_percent ASC",
            self::STATUS_PENDING,
            current_time('mysql')
        );

        return $this->wpdb->get_results($sql);
    }

    public function getRunnableTasks()
    {
        $sql = $this->wpdb->prepare(
            "SELECT discount_percent, active_days, action, COUNT(*) as count,
                    GROUP_CONCAT(DISTINCT id) as task_ids,
                    GROUP_CONCAT(DISTINCT product_id) as product_ids,
                    GROUP_CONCAT(DISTINCT variation_id) as variation_ids
             FROM {$this->tableName} 
             WHERE status = %s 
             AND scheduled_at <= %s 
             GROUP BY discount_percent, active_days, action
             ORDER BY action ASC, scheduled_at ASC, discount_percent ASC
             LIMIT 1",
            self::STATUS_PENDING,
            current_time('mysql')
        );

        return $this->wpdb->get_row($sql);
    }

    public function updateStatus($id, $status, $errorMessage = null)
    {
        $data = [
            'status' => $status,
        ];

        $format = ['%s'];

        if ($status === self::STATUS_COMPLETED || $status === self::STATUS_FAILED) {
            $data['processed_at'] = current_time('mysql');
            $format[] = '%s';
        }

        if ($errorMessage) {
            $data['error_message'] = $errorMessage;
            $format[] = '%s';
        }

        return $this->wpdb->update(
            $this->tableName,
            $data,
            ['id' => $id],
            $format,
            ['%d']
        );
    }

    public function updateMultipleStatus($ids, $status, $errorMessage = null)
    {
        if (empty($ids)) return false;

        $idsPlaceholders = implode(',', array_fill(0, count($ids), '%d'));

        $dataParts = ["status = %s"];
        $prepareValues = array_merge([$status], $ids);

        if ($status === self::STATUS_COMPLETED || $status === self::STATUS_FAILED) {
            $dataParts[] = "processed_at = %s";
            array_splice($prepareValues, 1, 0, current_time('mysql'));
        }

        if ($errorMessage) {
            $dataParts[] = "error_message = %s";
            array_splice($prepareValues, -count($ids), 0, $errorMessage);
        }

        $sql = $this->wpdb->prepare(
            "UPDATE {$this->tableName}
             SET " . implode(', ', $dataParts) . "
             WHERE id IN ($idsPlaceholders)",
            $prepareValues
        );

        return $this->wpdb->query($sql);
    }

    public function deleteMultipleTasks($ids)
    {
        if (empty($ids)) return false;

        $idsPlaceholders = implode(',', array_fill(0, count($ids), '%d'));

        $sql = $this->wpdb->prepare(
            "DELETE FROM {$this->tableName}
             WHERE id IN ($idsPlaceholders)",
            $ids
        );

        return $this->wpdb->query($sql);
    }

    public function getTaskById($id)
    {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->tableName}WHERE id = %d",
            $id
        );

        return $this->wpdb->get_row($sql);
    }

    public function getTasksCountByStatus($status = null)
    {
        if ($status) {
            $sql = $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->tableName} WHERE status = %s",
                $status
            );
        } else {
            $sql = "SELECT COUNT(*) FROM {$this->tableName}";
        }

        return $this->wpdb->get_var($sql);
    }
}
