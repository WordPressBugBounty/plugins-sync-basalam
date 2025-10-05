<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Clear_Logs_Controller extends Sync_BasalamController
{
    public function __invoke()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => 'شما دسترسی لازم برای این عملیات را ندارید.'
            ], 403);
        }

        $upload_dir = wp_upload_dir();
        $log_dir = trailingslashit($upload_dir['basedir']) . 'wc-logs/';

        if (!is_dir($log_dir)) {
            wp_send_json_error([
                'message' => 'پوشه لاگ‌ها یافت نشد.'
            ], 404);
        }

        $files = glob($log_dir . 'basalam-sync-plugin*.log');
        $deleted_count = 0;

        if ($files) {
            foreach ($files as $file) {
                if (wp_delete_file($file)) {
                    $deleted_count++;
                }
            }
        }

        if ($deleted_count > 0) {
            wp_send_json_success([
                'message' => sprintf('%d فایل لاگ با موفقیت حذف شد.', $deleted_count)
            ]);
        } else {
            wp_send_json_error([
                'message' => 'هیچ فایل لاگی برای حذف یافت نشد.'
            ], 404);
        }
    }
} 