<?php

namespace SyncBasalam\Jobs\Types;

use SyncBasalam\Jobs\AbstractJobType;
use SyncBasalam\Jobs\JobResult;
use SyncBasalam\Jobs\Exceptions\RetryableException;
use SyncBasalam\Jobs\Exceptions\NonRetryableException;
use SyncBasalam\Admin\ProductService;
use SyncBasalam\Config\Endpoints;
use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Admin\Settings\SettingsManager;
use SyncBasalam\Admin\Product\Data\ProductDataBuilder;
use SyncBasalam\Services\Products\ProductConnection;
use SyncBasalam\Logger\Logger;
use SyncBasalam\Services\VendorSyncPolicy;

defined('ABSPATH') || exit;

class BulkUpdateProductsJob extends AbstractJobType
{
    private const LAST_REQUEST_AT_OPTION = 'sync_basalam_bulk_update_products_last_run';
    private const REQUEST_INTERVAL_SECONDS = 12;

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

    public function canRun(): bool
    {
        $lastRequestAt = (float) get_option(self::LAST_REQUEST_AT_OPTION, 0);

        return (microtime(true) - $lastRequestAt) >= self::REQUEST_INTERVAL_SECONDS;
    }

    public function execute(array $payload): JobResult
    {
        $vendorSyncPolicy = syncBasalamContainer()->get(VendorSyncPolicy::class);
        if (!$vendorSyncPolicy->canUpdate()) {
            return $this->success([
                'skipped' => true,
                'reason' => $vendorSyncPolicy->getRestrictionMessage(false),
            ]);
        }

        if (!$vendorSyncPolicy->shouldRestrictUpdateFields(false) && !SettingsManager::isProductUpdateSelectionValid()) {
            throw NonRetryableException::invalidData(SettingsConfig::CUSTOM_PRODUCT_UPDATE_REQUIRED_MESSAGE);
        }

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

            $conflicts = ProductConnection::conflictMap($productIds);

            foreach ($productIds as $productId) {
                // محصولی که اتصالش با محصول دیگری مشترک است نباید در باسلام بروزرسانی شود.
                if (isset($conflicts[$productId])) {
                    ProductConnection::reportConflict((int) $productId, $conflicts[$productId]);
                    continue;
                }

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
                                if ($this->currentProcessingJobExists()) {
                                    $this->jobManager->createJob(
                                        'sync_basalam_update_single_product',
                                        'pending',
                                        $productId,
                                    );
                                }
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

            update_option(self::LAST_REQUEST_AT_OPTION, microtime(true), false);
            $res = $this->apiService->patch($url, ['data' => $productsData]);

            if ($res['status_code'] == 202) {
                Logger::info('بروزرسانی دسته جمعی محصولات با موفقیت انجام شد.');
            }

            $newLastId = max($productIds);

            if ($this->currentProcessingJobExists()) {
                $this->jobManager->createJob(
                    'sync_basalam_bulk_update_products',
                    'pending',
                    json_encode(['last_updatable_product_id' => $newLastId])
                );
            }

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

    private function currentProcessingJobExists(): bool
    {
        return $this->jobManager->getJob([
            'job_type' => $this->getType(),
            'status' => 'processing',
        ]) !== null;
    }
}
