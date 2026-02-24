<?php

namespace SyncBasalam\Admin\Product\Services;

use SyncBasalam\JobManager;

defined('ABSPATH') || exit;

class ProductSyncService
{
    private const JOB_TYPE_CREATE_ALL = 'sync_basalam_create_all_products';
    private const JOB_TYPE_CREATE_SINGLE = 'sync_basalam_create_single_product';
    private const JOB_TYPE_UPDATE_SINGLE = 'sync_basalam_update_single_product';
    private const JOB_TYPE_AUTO_CONNECT = 'sync_basalam_auto_connect_products';

    private JobManager $jobManager;

    public function __construct()
    {
        $this->jobManager = JobManager::getInstance();
    }

    public function enqueueBulkCreate(bool $includeOutOfStock = false, int $postsPerPage = 100): array
    {
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
        foreach ($productIds as $productId) {
            if (!$this->isValidProductForCreate($productId)) {
                continue;
            }

            $basalamProductId = get_post_meta($productId, 'sync_basalam_product_id', true);
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
            $basalamProductId = get_post_meta($productId, 'sync_basalam_product_id', true);
            if (!empty($basalamProductId)) {
                $validIds[] = $productId;
            }
        }

        return $validIds;
    }
}
