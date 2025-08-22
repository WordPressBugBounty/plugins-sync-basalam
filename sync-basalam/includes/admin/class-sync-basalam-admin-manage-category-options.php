<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Manage_Category_Options
{
    private $table_name;
    private $db;

    public function __construct($wpdb)
    {
        $this->db = $wpdb;
        $this->table_name = $wpdb->prefix . 'sync_basalam_map_options';
    }

    public function add($woo_name, $sync_basalam_name)
    {
        if (empty($woo_name) || empty($sync_basalam_name)) {
            return false;
        }

        $existing = $this->db->get_var(
            $this->db->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE woo_name = %s",
                $woo_name
            )
        );

        if ($existing > 0) {
            return [
                'success' => false,
                'message' => 'ویژگی ووکامرس قبلا ثبت شده است.',
                'status_code' => 400
            ];
        }

        $result = $this->db->insert(
            $this->table_name,
            [
                'woo_name'   => $woo_name,
                'sync_basalam_name'  => $sync_basalam_name,
            ],
            ['%s', '%s']
        );

        if ($result) {
            return [
                'success' => true,
                'message' => 'ویژگی با موفقیت ثبت شد.',
                'status_code' => 200
            ];
        } else {

            return [
                'success' => false,
                'message' => 'ثبت ویژگی با مشکل مواجه شد.',
                'status_code' => 500
            ];
        }
    }

    public function get_all(): array
    {
        $results = $this->db->get_results("SELECT * FROM {$this->table_name}", ARRAY_A);
        return $results ?: [];
    }

    public function exists(string $woo_name): bool
    {
        if (empty($woo_name)) {
            return false;
        }

        $row = $this->db->get_row(
            $this->db->prepare("SELECT 1 FROM {$this->table_name} WHERE woo_name = %s LIMIT 1", $woo_name)
        );

        return !is_null($row);
    }
    public function delete(string $woo_name, string $sync_basalam_name): array
    {
        if (empty($woo_name) || empty($sync_basalam_name)) {
            return [
                'success' => false,
                'message' => 'مقادیر ارسالی نامعتبر هستند.',
                'status_code' => 400
            ];
        }

        $deleted = $this->db->delete(
            $this->table_name,
            [
                'woo_name' => $woo_name,
                'sync_basalam_name' => $sync_basalam_name,
            ],
            ['%s', '%s']
        );

        if ($deleted !== false && $deleted > 0) {
            return [
                'success' => true,
                'message' => 'ویژگی با موفقیت حذف شد.',
                'status_code' => 200
            ];
        } else {
            return [
                'success' => false,
                'message' => 'ویژگی حذف نشد یا قبلاً حذف شده است.',
                'status_code' => 404
            ];
        }
    }
}
