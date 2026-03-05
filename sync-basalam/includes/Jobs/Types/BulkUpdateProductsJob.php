<?php

namespace SyncBasalam\Jobs\Types;

use SyncBasalam\Jobs\AbstractJobType;
use SyncBasalam\Jobs\JobResult;
use SyncBasalam\Jobs\Exceptions\RetryableException;
use SyncBasalam\Jobs\Exceptions\NonRetryableException;
use SyncBasalam\Admin\ProductService;
use SyncBasalam\Config\Endpoints;
use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Admin\Product\Data\ProductDataBuilder;
use SyncBasalam\Logger\Logger;

defined('ABSPATH') || exit;

class BulkUpdateProductsJob extends AbstractJobType
{
    private $apiService;
    private $factory;
    private $settingsAccessor;

    public function __construct(
        $jobManager,
        $apiService,
        $factory,
        $settingsAccessor
    ) {
        parent::__construct($jobManager);
        $this->apiService = $apiService;
        $this->factory = $factory;
        $this->settingsAccessor = $settingsAccessor;
    }

    public function getType(): string
    {
        return 'sync_basalam_bulk_update_products';
    }

    public function getPriority(): int
    {
        return 1;
    }

    public function execute(array $payload): JobResult
    {
        $lastId = $payload['last_updatable_product_id'] ?? 0;

        Logger::alert('شروع بروزرسانی دسته‌ای محصولات از آیدی: ' . $lastId);

        try {
            $vendorId = $this->settingsAccessor->getSettings(SettingsConfig::VENDOR_ID);
            $url = sprintf(Endpoints::PRODUCT_BATCH_UPDATE, $vendorId);

            $batchData = [
                'posts_per_page' => 10,
                'last_updatable_product_id' => $lastId,
            ];

            $productIds = ProductService::getUpdatableProducts($batchData);

            if (!$productIds) {
                Logger::info('بروزرسانی دسته‌ای: همه محصولات بروزرسانی شدند.');
                $this->jobManager->deleteJob(['job_type' => 'sync_basalam_bulk_update_products']);
                return $this->success(['completed' => true, 'message' => 'All products bulk updated']);
            }

            $builder = new ProductDataBuilder(null, $this->factory);
            $productsData = [];

            foreach ($productIds as $productId) {
                try {
                    $productData = $builder->reset()
                        ->setStrategy($this->factory->createStrategy('quick_update'))
                        ->fromWooProduct($productId)
                        ->build();

                    if (!empty($productData)) {
                        if ($productData['type'] === 'variable') {
                            $hasIncompleteVariants = false;

                            foreach ($productData['variants'] as $variant) {
                                if (empty($variant['id'])) {
                                    $hasIncompleteVariants = true;
                                    break;
                                }
                            }

                            if ($hasIncompleteVariants) {
                                $this->jobManager->createJob(
                                    'sync_basalam_update_single_product',
                                    'pending',
                                    $productId,
                                );
                                continue;
                            }
                        }
                        unset($productData['type']);
                        $productsData[] = $productData;
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }

            if (empty($productsData)) return $this->success(['skipped' => true, 'message' => 'No products to update in this batch']);

            $res = $this->apiService->patch($url, ['data' => $productsData]);

            if ($res['status_code'] == 202) {
                Logger::info('بروزرسانی دسته جمعی محصولات با موفقیت انجام شد.');
            }

            $newLastId = max($productIds);

            $this->jobManager->createJob(
                'sync_basalam_bulk_update_products',
                'pending',
                json_encode(['last_updatable_product_id' => $newLastId])
            );

            return $this->success(['last_id' => $newLastId, 'count' => count($productsData)]);
        } catch (RetryableException $e) {
            Logger::error("خطا در بروزرسانی دسته جمعی محصولات: " . $e->getMessage(), [
                'operation' => 'بروزرسانی دسته جمعی محصولات',
            ]);
            throw $e;
        } catch (NonRetryableException $e) {
            Logger::error("خطا در بروزرسانی دسته جمعی محصولات: " . $e->getMessage(), [
                'operation' => 'بروزرسانی دسته جمعی محصولات',
            ]);
            throw $e;
        } catch (\Exception $e) {
            Logger::error("خطا در بروزرسانی دسته جمعی محصولات: " . $e->getMessage(), [
                'operation' => 'بروزرسانی دسته جمعی محصولات',
            ]);
            throw $e;
        }
    }
}
