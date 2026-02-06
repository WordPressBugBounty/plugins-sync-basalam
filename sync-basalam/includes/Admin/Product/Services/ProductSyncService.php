<?php

namespace SyncBasalam\Admin\Product\Services;

use SyncBasalam\JobManager;
use SyncBasalam\Logger\Logger;
use SyncBasalam\Queue\Tasks\CreateProduct;
use SyncBasalam\Queue\Tasks\UpdateProduct;
use SyncBasalam\Admin\Settings\SettingsConfig;

defined('ABSPATH') || exit;

class ProductSyncService
{
    private const JOB_TYPE_CREATE_ALL = 'sync_basalam_create_all_products';
    private const JOB_TYPE_CREATE_SINGLE = 'sync_basalam_create_single_product';
    private const JOB_TYPE_UPDATE_BULK = 'sync_basalam_bulk_update_products';
    private const JOB_TYPE_UPDATE_SINGLE = 'sync_basalam_update_single_product';
    private const JOB_TYPE_AUTO_CONNECT = 'sync_basalam_auto_connect_products';

    private JobManager $jobManager;
    private string $operationType;

    public function __construct()
    {
        $this->jobManager = JobManager::getInstance();
        $this->operationType = syncBasalamSettings()->getSettings(SettingsConfig::PRODUCT_OPERATION_TYPE);
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
        if ($this->operationType === 'immediate') {
            $this->enqueueForImmediateCreate($productIds);
        } else {
            $this->enqueueForScheduledCreate($productIds);
        }
    }

    public function enqueueSelectedForUpdate(array $productIds): void
    {
        $validProductIds = $this->filterValidProductsForUpdate($productIds);

        if ($this->operationType === 'immediate') {
            $this->enqueueForImmediateUpdate($validProductIds);
        } else {
            $this->enqueueForScheduledUpdate($validProductIds);
        }
    }

    public function enqueueAutoConnect(int $page = 1): void
    {
        $this->jobManager->createJob(
            self::JOB_TYPE_AUTO_CONNECT,
            'pending',
            json_encode(['page' => $page])
        );
    }

    private function enqueueForImmediateCreate(array $productIds): void
    {
        $queue = new CreateProduct();

        foreach ($productIds as $productId) {
            if (!$this->isValidProductForCreate($productId)) {
                continue;
            }

            $basalamProductId = get_post_meta($productId, 'sync_basalam_product_id', true);
            if (empty($basalamProductId)) {
                update_post_meta($productId, 'sync_basalam_product_sync_status', 'pending');
                $queue->push(['type' => 'create_product', 'id' => $productId]);
            }
        }

        try {
            $queue->save();
            $queue->dispatch();
        } catch (\Throwable $th) {
            $this->handleImmediateCreateError($th, $productIds);
        }
    }

    private function enqueueForScheduledCreate(array $productIds): void
    {
        foreach ($productIds as $productId) {
            if (!$this->isValidProductForCreate($productId)) {
                continue;
            }

            $basalamProductId = get_post_meta($productId, 'sync_basalam_product_id', true);
            if (empty($basalamProductId)) {
                update_post_meta($productId, 'sync_basalam_product_sync_status', 'pending');
                $this->jobManager->createJob(
                    self::JOB_TYPE_CREATE_SINGLE,
                    'pending',
                    json_encode(['product_id' => $productId])
                );
            }
        }
    }

    private function enqueueForImmediateUpdate(array $productIds): void
    {
        $queue = new UpdateProduct();

        foreach ($productIds as $productId) {
            $basalamProductId = get_post_meta($productId, 'sync_basalam_product_id', true);
            if (empty($basalamProductId)) {
                continue;
            }

            if (!$this->jobManager->hasProductJobInProgress($productId, self::JOB_TYPE_UPDATE_SINGLE)) {
                update_post_meta($productId, 'sync_basalam_product_sync_status', 'pending');
                $queue->push(['type' => 'update_product', 'id' => $productId]);
            }
        }

        try {
            $queue->save();
            $queue->dispatch();
        } catch (\Throwable $th) {
            $this->handleImmediateUpdateError($th, $productIds);
        }
    }

    private function enqueueForScheduledUpdate(array $productIds): void
    {
        foreach ($productIds as $productId) {
            $basalamProductId = get_post_meta($productId, 'sync_basalam_product_id', true);
            if (empty($basalamProductId)) {
                continue;
            }

            if (!$this->jobManager->hasProductJobInProgress($productId, self::JOB_TYPE_UPDATE_SINGLE)) {
                update_post_meta($productId, 'sync_basalam_product_sync_status', 'pending');
                $this->jobManager->createJob(
                    self::JOB_TYPE_UPDATE_SINGLE,
                    'pending',
                    json_encode(['product_id' => $productId])
                );
            }
        }
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

    private function handleImmediateCreateError(\Throwable $throwable, array $productIds): void
    {
        foreach ($productIds as $productId) {
            update_post_meta($productId, 'sync_basalam_product_sync_status', 'no');
        }

        Logger::error("خطا در ایجاد محصول فوری: " . $throwable->getMessage(), [
            'product_ids' => $productIds,
            'عملیات'     => 'ایجاد فوری محصولات انتخابی',
        ]);
    }

    private function handleImmediateUpdateError(\Throwable $throwable, array $productIds): void
    {
        foreach ($productIds as $productId) {
            update_post_meta($productId, 'sync_basalam_product_sync_status', 'no');
        }

        Logger::error("خطا در بروزرسانی محصول فوری: " . $throwable->getMessage(), [
            'product_ids' => $productIds,
            'عملیات'     => 'بروزرسانی فوری محصولات انتخابی',
        ]);
    }
}
