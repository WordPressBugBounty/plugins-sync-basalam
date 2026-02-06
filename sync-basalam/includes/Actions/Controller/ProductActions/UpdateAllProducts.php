<?php

namespace SyncBasalam\Actions\Controller\ProductActions;

use SyncBasalam\JobManager;
use SyncBasalam\Actions\Controller\ActionController;

defined('ABSPATH') || exit;

class UpdateAllProducts extends ActionController
{
    public function __invoke()
    {
        $updateType = isset($_POST['update_type']) ? $_POST['update_type'] : 'quick';
        $jobManager = new JobManager();

        if ($updateType === 'full') {
            $existingJob = $jobManager->getJob([
                'job_type' => 'sync_basalam_update_all_products',
                'status'   => 'pending',
            ]);

            if ($existingJob) {
                return wp_send_json_error(['message' => 'در حال حاضر یک عملیات بروزرسانی کامل در صف انتظار است.'], 409);
            }

            $initialData = json_encode([
                'offset' => 0,
            ]);

            $response = $jobManager->createJob(
                'sync_basalam_update_all_products',
                'pending',
                $initialData
            );

            if (!$response) {
                return wp_send_json_error(['message' => 'خطا در ایجاد فرایند بروزرسانی.'], 500);
            }

            wp_send_json_success(['message' => 'بروزرسانی تمام اطلاعات محصول با موفقیت آغاز شد.',
            ], 200);
        } else {
            $existingJob = $jobManager->getJob([
                'job_type' => 'sync_basalam_bulk_update_products',
                'status'   => 'pending',
            ]);

            if ($existingJob) {
                return wp_send_json_error(['message' => 'در حال حاضر یک عملیات بروزرسانی در صف انتظار است.'], 409);
            }

            $response = $jobManager->createJob(
                'sync_basalam_bulk_update_products',
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
