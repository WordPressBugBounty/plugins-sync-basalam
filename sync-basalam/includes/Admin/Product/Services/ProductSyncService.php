<?php

namespace SyncBasalam\Admin\Product\Services;

use SyncBasalam\JobManager;
use SyncBasalam\Admin\Settings\SettingsManager;
use SyncBasalam\Utilities\ProductMetaKey;
use SyncBasalam\Services\VendorSyncPolicy;

defined('ABSPATH') || exit;

class ProductSyncService
{
    private const JOB_TYPE_CREATE_ALL = 'sync_basalam_create_all_products';
    private const JOB_TYPE_CREATE_SINGLE = 'sync_basalam_create_single_product';
    private const JOB_TYPE_UPDATE_SINGLE = 'sync_basalam_update_single_product';
    private const JOB_TYPE_AUTO_CONNECT = 'sync_basalam_auto_connect_products';

    private $jobManager;

    public function __construct($jobManager = null)
    {
        $this->jobManager = $jobManager ?: syncBasalamContainer()->get(JobManager::class);
    }

    public function enqueueBulkCreate(bool $includeOutOfStock = false, int $postsPerPage = 100): array
    {
        $vendorSyncPolicy = syncBasalamContainer()->get(VendorSyncPolicy::class);
        if (!$vendorSyncPolicy->canCreate()) {
            return [
                'success' => false,
                'message' => $vendorSyncPolicy->getRestrictionMessage(false),
                'status_code' => 409,
            ];
        }

        $existingJob = $this->jobManager->getJob([
            'job_type' => self::JOB_TYPE_CREATE_ALL,
            'status'   => 'pending',
        ]);

        if ($existingJob) {
            return [
                'success'     => false,
                'message'     => 'در حال حاضر یک عملیات در صف انتظار است.',
                'status_code' => 409,
            ];
        }

        $initialData = json_encode([
            'posts_per_page'       => $postsPerPage,
            'include_out_of_stock' => $includeOutOfStock,
        ]);

        $this->jobManager->createJob(
            self::JOB_TYPE_CREATE_ALL,
            'pending',
            $initialData
        );

        return [
            'success'     => true,
            'message'     => 'محصولات با موفقیت به صف ایجاد افزوده شدند.',
            'status_code' => 200,
        ];
    }

    public function enqueueSelectedForCreate(array $productIds): void
    {
        if (!syncBasalamContainer()->get(VendorSyncPolicy::class)->canCreate()) return;

        foreach ($productIds as $productId) {
            if (!$this->isValidProductForCreate($productId)) {
                continue;
            }

            $basalamProductId = get_post_meta($productId, ProductMetaKey::basalamProductId(), true);
            if (empty($basalamProductId)) {
                $this->jobManager->createJob(
                    self::JOB_TYPE_CREATE_SINGLE,
                    'pending',
                    json_encode(['product_id' => $productId])
                );
            }
        }
    }

    public function enqueueSelectedForUpdate(array $productIds): void
    {
        $vendorSyncPolicy = syncBasalamContainer()->get(VendorSyncPolicy::class);
        if (!$vendorSyncPolicy->canUpdate()) return;
        if (!$vendorSyncPolicy->shouldRestrictUpdateFields(false) && !SettingsManager::isProductUpdateSelectionValid()) return;

        $validProductIds = $this->filterValidProductsForUpdate($productIds);

        foreach ($validProductIds as $productId) {
            if (!$this->jobManager->hasProductJobInProgress($productId, self::JOB_TYPE_UPDATE_SINGLE)) {
                $this->jobManager->createJob(
                    self::JOB_TYPE_UPDATE_SINGLE,
                    'pending',
                    json_encode(['product_id' => $productId])
                );
            }
        }
    }

    public function enqueueAutoConnect($cursor = null): void
    {
        $payload['cursor'] = $cursor;
        $data = $this->jobManager->createJob(
            self::JOB_TYPE_AUTO_CONNECT,
            'pending',
            json_encode($payload)
        );
    }

    private function isValidProductForCreate(int $productId): bool
    {
        $product = wc_get_product($productId);
        return $product && $product->get_status() === 'publish';
    }

    private function filterValidProductsForUpdate(array $productIds): array
    {
        $validIds = [];

        foreach ($productIds as $productId) {
            $basalamProductId = get_post_meta($productId, ProductMetaKey::basalamProductId(), true);
            if (!empty($basalamProductId)) {
                $validIds[] = $productId;
            }
        }

        return $validIds;
    }
}
