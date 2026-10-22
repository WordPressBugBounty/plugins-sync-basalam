<?php

namespace SyncBasalam\Actions\Controller\ProductActions;

use SyncBasalam\JobManager;
use SyncBasalam\Actions\Controller\ActionController;
use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Admin\Settings\SettingsManager;
use SyncBasalam\Services\VendorSyncPolicy;

defined('ABSPATH') || exit;

class UpdateAllProducts extends ActionController
{
    public function __invoke()
    {
        $updateType = isset($_POST['update_type']) ? sanitize_text_field(wp_unslash($_POST['update_type'])) : null;
        if (!$updateType) {
            return wp_send_json_error(['message' => 'نوع بروزرسانی نامعتبر است.'], 400);
        }

        $vendorSyncPolicy = syncBasalamContainer()->get(VendorSyncPolicy::class);
        if (!$vendorSyncPolicy->canUpdate()) {
            return wp_send_json_error(['message' => $vendorSyncPolicy->getRestrictionMessage(false)], 409);
        }

        if ($vendorSyncPolicy->shouldRestrictUpdateFields(false)) {
            $updateType = 'quick';
        } elseif (!SettingsManager::isProductUpdateSelectionValid()) {
            return wp_send_json_error(['message' => SettingsConfig::CUSTOM_PRODUCT_UPDATE_REQUIRED_MESSAGE], 422);
        }

        $jobManager = syncBasalamContainer()->get(JobManager::class);

        if ($updateType === 'full') {
            $result = $jobManager->createProductRootJob(
                'sync_basalam_update_all_products',
                'pending',
                wp_json_encode(['last_updatable_product_id' => 0])
            );

            if ($result === 'exists') {
                return wp_send_json_error(['message' => 'در حال حاضر یک عملیات بروزرسانی کامل در صف انتظار است.'], 409);
            }

            if ($result !== 'created') {
                return wp_send_json_error(['message' => 'خطا در ایجاد فرایند بروزرسانی.'], 500);
            }

            wp_send_json_success([
                'message' => 'بروزرسانی تمام اطلاعات محصول با موفقیت آغاز شد.',
            ], 200);
        } else {
            $result = $jobManager->createProductRootJob(
                'sync_basalam_bulk_update_products',
                'pending',
                wp_json_encode(['last_updatable_product_id' => 0])
            );

            if ($result === 'exists') {
                return wp_send_json_error(['message' => 'در حال حاضر یک عملیات بروزرسانی در صف انتظار است.'], 409);
            }

            if ($result !== 'created') {
                return wp_send_json_error(['message' => 'خطا در ایجاد فرایند بروزرسانی فوری محصولات.'], 500);
            }

            wp_send_json_success(['message' => 'فرایند بروزرسانی قیمت و موجودی با موفقیت آغاز شد.'], 200);
        }
    }
}
