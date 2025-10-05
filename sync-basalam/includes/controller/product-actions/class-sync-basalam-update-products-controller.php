<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Update_Products extends Sync_BasalamController
{
    public function __invoke()
    {
        $update_type = isset($_POST['update_type']) ? $_POST['update_type'] : 'quick';
        $job_manager = new SyncBasalamJobManager();

        if ($update_type === 'full') {
            $existing_job = $job_manager->get_job([
                'job_type' => 'sync_basalam_full_update_products',
                'status' => 'pending'
            ]);

            if ($existing_job) {
                return wp_send_json_error(['message' => 'در حال حاضر یک عملیات بروزرسانی کامل در صف انتظار است.'], 409);
            }

            
            delete_option('last_offset_full_update_products');

            
            $initial_data = json_encode([
                'offset' => 0
            ]);

            $response = $job_manager->create_job(
                'sync_basalam_full_update_products',
                'pending',
                $initial_data
            );

            if (!$response) {
                return wp_send_json_error(['message' => 'خطا در ایجاد فرایند بروزرسانی.'], 500);
            }

            wp_send_json_success(['message' =>
             'بروزرسانی تمام اطلاعات محصول با موفقیت آغاز شد.'
            ], 200);
        } else {
            
            $existing_job = $job_manager->get_job([
                'job_type' => 'sync_basalam_update_all_products',
                'status' => 'pending'
            ]);

            if ($existing_job) {
                return wp_send_json_error(['message' => 'در حال حاضر یک عملیات بروزرسانی در صف انتظار است.'], 409);
            }

            
            delete_option('last_offset_update_products');

            $response = $job_manager->create_job(
                'sync_basalam_update_all_products',
                'pending',
                0,
            );

            if (!$response) {
                return wp_send_json_error(['message' => 'خطا در ایجاد فرایند بروزرسانی فوری محصولات.'], 500);
            }

            wp_send_json_success(['message' => 'فرایند بروزرسانی قیمت و موجودی با موفقیت آغاز شد.'], 200);
        }
    }
}
