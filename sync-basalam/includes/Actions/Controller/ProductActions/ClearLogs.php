<?php

namespace SyncBasalam\Actions\Controller\ProductActions;

use SyncBasalam\Actions\Controller\ActionController;

defined('ABSPATH') || exit;

class ClearLogs extends ActionController
{
    public function __invoke()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => 'شما دسترسی لازم برای این عملیات را ندارید.',
            ], 403);
        }

        $uploadDir = wp_upload_dir();
        $logDir = trailingslashit($uploadDir['basedir']) . 'wc-logs/';

        if (!is_dir($logDir)) {
            wp_send_json_error([
                'message' => 'پوشه لاگ‌ها یافت نشد.',
            ], 404);
        }

        // Check if the directory is writable
        if (!is_writable($logDir)) {
            wp_send_json_error([
                'message' => 'پوشه لاگ‌ها قابل نوشتن نیست.',
            ], 403);
        }

        $files = glob($logDir . 'basalam-sync-plugin*.log');
        $deletedCount = 0;

        if (empty($files)) {
            wp_send_json_success([
                'message' => 'هیچ فایل لاگی برای حذف وجود نداشت.',
            ]);
        }

        foreach ($files as $file) {
            if (file_exists($file)) {
                if (unlink($file)) {
                    $deletedCount++;
                }
            }
        }

        if ($deletedCount > 0) {
            wp_send_json_success([
                'message' => sprintf('%d فایل لاگ با موفقیت حذف شد.', $deletedCount),
            ]);
        } else {
            wp_send_json_error([
                'message' => 'خطا در حذف فایل‌های لاگ. ممکن است مشکل دسترسی وجود داشته باشد.',
            ], 500);
        }
    }
}
