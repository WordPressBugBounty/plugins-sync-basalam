<?php

namespace SyncBasalam\Jobs\Types;

use SyncBasalam\Jobs\AbstractJobType;
use SyncBasalam\Admin\ProductService;
use SyncBasalam\Services\ApiServiceManager;
use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Admin\Product\ProductDataFactory;
use SyncBasalam\Admin\Product\Data\ProductDataBuilder;
use SyncBasalam\Logger\Logger;

defined('ABSPATH') || exit;

class BulkUpdateProductsJob extends AbstractJobType
{
    public function getType(): string
    {
        return 'sync_basalam_bulk_update_products';
    }

    public function getPriority(): int
    {
        return 1;
    }

    public function execute(array $payload): void
    {
        $lastId = $payload['last_updatable_product_id'] ?? 0;

        Logger::alert('شروع بروزرسانی دسته‌ای محصولات از آیدی: ' . $lastId);

        $apiService = new ApiServiceManager();
        $vendorId = syncBasalamSettings()->getSettings(SettingsConfig::VENDOR_ID);
        $url = "https://openapi.basalam.com/v1/vendors/$vendorId/products/batch-updates?continue_on_error=true";

        $batchData = [
            'posts_per_page' => 10,
            'last_updatable_product_id' => $lastId,
        ];

        $productIds = ProductService::getUpdatableProducts($batchData);

        if (!$productIds) {
            Logger::info('بروزرسانی دسته‌ای: همه محصولات بروزرسانی شدند.');
            $this->jobManager->deleteJob(['job_type' => 'sync_basalam_bulk_update_products']);
            return;
        }

        $factory = new ProductDataFactory();
        $builder = new ProductDataBuilder(null, $factory);
        $productsData = [];

        foreach ($productIds as $productId) {
            try {
                $productData = $builder->reset()
                    ->setStrategy($factory->createStrategy('quick_update'))
                    ->fromWooProduct($productId)
                    ->build();

                if (!empty($productData)) {
                    $productsData[] = $productData;
                }

            } catch (\Throwable $e) {
                continue;
            }
        }

        $res = $apiService->sendPatchRequest($url, ['data' => $productsData]);

        if ($res['status_code'] == 202) Logger::info('بروزرسانی دسته جمعی محصولات با موفقیت انجام شد.');
        else Logger::error('خطا در بروزرسانی دسته جمعی محصولات: ' . json_encode($res, JSON_UNESCAPED_UNICODE));

        $newLastId = max($productIds);

        $this->jobManager->createJob(
            'sync_basalam_bulk_update_products',
            'pending',
            json_encode(['last_updatable_product_id' => $newLastId])
        );
    }
}
