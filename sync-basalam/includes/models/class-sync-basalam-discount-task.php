<?php
defined('ABSPATH') || exit;

class Sync_Basalam_Discount_Task
{
    private $wpdb;
    private $table_name;

    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    const ACTION_APPLY = 'apply';
    const ACTION_REMOVE = 'remove';

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'sync_basalam_discount_tasks';
    }

    public function create($data)
    {
        // Support both array input and individual parameters
        if (is_array($data)) {
            $product_id = $data['product_id'] ?? null;
            $variation_id = $data['variation_id'] ?? null;
            $discount_percent = $data['discount_percent'] ?? 0;
            $active_days = $data['active_days'] ?? 7;
            $action = $data['action'] ?? self::ACTION_APPLY;
            $scheduled_at = $data['scheduled_at'] ?? null;
            $status = $data['status'] ?? self::STATUS_PENDING;
        } else {
            // Legacy support for old method signature
            $product_id = $data;
            $variation_id = func_get_arg(3) ?? null;
            $discount_percent = func_get_arg(1) ?? 0;
            $active_days = func_get_arg(2) ?? 7;
            $action = self::ACTION_APPLY;
            $scheduled_at = func_get_arg(4) ?? null;
            $status = self::STATUS_PENDING;
        }

        if (!$scheduled_at) {
            $scheduled_at = current_time('mysql');
        }

        $result = $this->wpdb->insert(
            $this->table_name,
            array(
                'product_id' => $product_id,
                'variation_id' => $variation_id,
                'discount_percent' => $discount_percent,
                'active_days' => $active_days,
                'action' => $action,
                'status' => $status,
                'scheduled_at' => $scheduled_at,
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%f', '%d', '%s', '%s', '%s', '%s')
        );

        return $result ? $this->wpdb->insert_id : false;
    }

    public function get_pending_tasks()
    {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE status = %s 
             AND scheduled_at <= %s 
             ORDER BY scheduled_at ASC",
            self::STATUS_PENDING,
            current_time('mysql')
        );

        return $this->wpdb->get_results($sql);
    }

    public function get_tasks_by_discount_percent($discount_percent)
    {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE status = %s 
             AND discount_percent = %f 
             AND scheduled_at <= %s 
             ORDER BY created_at ASC",
            self::STATUS_PENDING,
            $discount_percent,
            current_time('mysql')
        );

        return $this->wpdb->get_results($sql);
    }

    public function get_grouped_pending_tasks()
    {
        $sql = $this->wpdb->prepare(
            "SELECT discount_percent, active_days, action, COUNT(*) as count,
                    GROUP_CONCAT(DISTINCT id) as task_ids,
                    GROUP_CONCAT(DISTINCT product_id) as product_ids,
                    GROUP_CONCAT(DISTINCT variation_id) as variation_ids
             FROM {$this->table_name} 
             WHERE status = %s 
             AND scheduled_at <= %s 
             GROUP BY discount_percent, active_days, action
             ORDER BY action ASC, discount_percent ASC",
            self::STATUS_PENDING,
            current_time('mysql')
        );

        return $this->wpdb->get_results($sql);
    }

    /**
     * Get the first group of pending tasks with same discount_percent and active_days
     * This ensures we process one group at a time for better API efficiency
     */
    public function get_first_pending_task_group()
    {
        $sql = $this->wpdb->prepare(
            "SELECT discount_percent, active_days, action, COUNT(*) as count,
                    GROUP_CONCAT(DISTINCT id) as task_ids,
                    GROUP_CONCAT(DISTINCT product_id) as product_ids,
                    GROUP_CONCAT(DISTINCT variation_id) as variation_ids
             FROM {$this->table_name} 
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

    public function update_status($id, $status, $error_message = null)
    {
        $data = array(
            'status' => $status
        );

        $format = array('%s');

        if ($status === self::STATUS_COMPLETED || $status === self::STATUS_FAILED) {
            $data['processed_at'] = current_time('mysql');
            $format[] = '%s';
        }

        if ($error_message) {
            $data['error_message'] = $error_message;
            $format[] = '%s';
        }

        return $this->wpdb->update(
            $this->table_name,
            $data,
            array('id' => $id),
            $format,
            array('%d')
        );
    }

    public function update_multiple_status($ids, $status, $error_message = null)
    {
        if (empty($ids)) {
            return false;
        }

        $ids_placeholders = implode(',', array_fill(0, count($ids), '%d'));
        
        $data_parts = array("status = %s");
        $prepare_values = array_merge(array($status), $ids);

        if ($status === self::STATUS_COMPLETED || $status === self::STATUS_FAILED) {
            $data_parts[] = "processed_at = %s";
            array_splice($prepare_values, 1, 0, current_time('mysql'));
        }

        if ($error_message) {
            $data_parts[] = "error_message = %s";
            array_splice($prepare_values, -count($ids), 0, $error_message);
        }

        $sql = $this->wpdb->prepare(
            "UPDATE {$this->table_name} 
             SET " . implode(', ', $data_parts) . "
             WHERE id IN ($ids_placeholders)",
            $prepare_values
        );

        return $this->wpdb->query($sql);
    }

    public function delete_old_completed_tasks($days = 30)
    {
        $sql = $this->wpdb->prepare(
            "DELETE FROM {$this->table_name} 
             WHERE status IN (%s, %s) 
             AND processed_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            $days
        );

        return $this->wpdb->query($sql);
    }

    public function get_task_by_id($id)
    {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $id
        );

        return $this->wpdb->get_row($sql);
    }

    public function get_tasks_count_by_status($status = null)
    {
        if ($status) {
            $sql = $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE status = %s",
                $status
            );
        } else {
            $sql = "SELECT COUNT(*) FROM {$this->table_name}";
        }

        return $this->wpdb->get_var($sql);
    }
}