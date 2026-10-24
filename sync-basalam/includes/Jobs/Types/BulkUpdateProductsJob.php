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
use SyncBasalam\Services\Products\ProductOwnership;
use SyncBasalam\Services\Products\ProductSkuRetry;
use SyncBasalam\Services\Products\ProductPriceStockRetry;
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
        return 3;
    }

    public function canRun(): bool
    {
        $lastRequestAt = (float) get_option(self::LAST_REQUEST_AT_OPTION, 0);

        // Missing variation mappings create single-product fallback jobs.
        // Delayed retries must finish before another page can add more work.
        return (microtime(true) - $lastRequestAt) >= self::REQUEST_INTERVAL_SECONDS
            && $this->areAllSingleJobsCompleted('sync_basalam_update_single_product');
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
                return $this->success(['completed' => true, 'message' => 'All products bulk updated']);
            }

            $builder = new ProductDataBuilder(null, $this->factory);
            $productsData = [];
            $productIdsByRemote = [];

            $conflicts = ProductConnection::conflictMap($productIds);

            foreach ($productIds as $productId) {
                // محصولی که اتصالش با محصول دیگری مشترک است نباید در باسلام بروزرسانی شود.
                if (isset($conflicts[$productId])) {
                    ProductConnection::reportConflict((int) $productId, $conflicts[$productId]);
                    continue;
                }

                try {
                    if (!(new ProductOwnership($this->apiService))->prepareQueuedUpdate((int) $productId, $vendorId)) continue;
                    $productData = $builder->reset()
                        ->setStrategy($this->factory->createStrategy('quick_update'))
                        ->fromWooProduct($productId)
                        ->build();

                    if (!empty($productData)) {
                        if ($productData['type'] === 'variable') {
                            $hasIncompleteVariants = false;

                            foreach (($productData['variants'] ?? []) as $variant) {
                                if (empty($variant['id'])) {
                                    $hasIncompleteVariants = true;
                                    break;
                                }
                            }

                            if ($hasIncompleteVariants) {
                                if ($this->currentProcessingJobExists()
                                    && !$this->hasProductJobInProgress((int) $productId, 'sync_basalam_update_single_product')) {
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
                        $productIdsByRemote[(string) $productData['id']] = (int) $productId;
                    }
                } catch (RetryableException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    continue;
                }
            }

            if (empty($productsData)) {
                $this->enqueueContinuation(max($productIds));
                return $this->success(['skipped' => true, 'message' => 'No products to update in this batch']);
            }

            update_option(self::LAST_REQUEST_AT_OPTION, microtime(true), false);
            $batchPayload = ['data' => $productsData];
            $res = ProductPriceStockRetry::send(
                fn(array $data) => $this->apiService->patch($url, $data),
                $batchPayload
            );
            $outcome = $this->settleBatchResponse($url, $batchPayload['data'], $res);
            $deferred = $this->enqueueRetryableItems($outcome['retryable'], $productIdsByRemote);
            $newLastId = max($productIds);
            $this->enqueueContinuation($newLastId);

            if ($outcome['failed']) {
                $message = $this->batchFailureMessage($outcome['failed']);
                Logger::error($message, ['operation' => 'بروزرسانی دسته جمعی محصولات']);
                $codes = array_unique(array_column($outcome['failed'], 'status_code'));
                $code = count($codes) === 1 ? (int) reset($codes) : 0;
                return $this->nonRetryable($message, $code, [
                    'last_id' => $newLastId, 'updated' => $outcome['updated'], 'failed' => $outcome['failed'], 'deferred' => $deferred,
                ]);
            }

            if ($deferred) {
                Logger::warning('تلاش مجدد برای محصولات ناموفق به صف تکی منتقل شد: ' . implode(', ', $deferred));
            } else {
                Logger::info('بروزرسانی دسته جمعی محصولات با موفقیت انجام شد.');
            }
            return $this->success(['last_id' => $newLastId, 'count' => $outcome['updated'], 'deferred' => $deferred]);
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

    /** HTTP 202 accepts the batch envelope; each requested product still needs an outcome. */
    private function settleBatchResponse(string $url, array $productsData, array $response): array
    {
        $this->assertBatchAccepted($response);
        $results = $this->batchResultMap($productsData, $response['body'] ?? null);
        $retryProducts = [];

        foreach ($productsData as $productData) {
            $result = $results[(string) $productData['id']];
            if (!$this->batchItemFailed($result) || !$this->batchItemAllowsFallback($result)) continue;
            $fallback = ProductPriceStockRetry::onlyPriceStock($productData);
            if ($fallback !== $productData) $retryProducts[] = $fallback;
        }

        if ($retryProducts) {
            // One bounded retry, only for rejected items. Successful products are not resent.
            try {
                $retryResponse = $this->apiService->patch($url, ['data' => $retryProducts]);
                $this->assertBatchAccepted($retryResponse);
                $results = array_replace($results, $this->batchResultMap($retryProducts, $retryResponse['body'] ?? null));
            } catch (\SyncBasalam\Jobs\Exceptions\JobException $exception) {
                // Only these items were being retried; keep confirmed successes.
                foreach ($retryProducts as $productData) {
                    $results[(string) $productData['id']] = [
                        'id' => $productData['id'], 'has_error' => true, 'status_code' => $exception->getCode() ?: ($exception->shouldRetry() ? 503 : 0),
                        'error_message' => $exception->getMessage(),
                    ];
                }
            }
        }

        $failed = [];
        $retryable = [];
        foreach ($results as $id => $result) {
            if (!$this->batchItemFailed($result)) continue;
            $status = $this->batchItemStatus($result);
            $failed[$id] = [
                'status_code' => $status,
                'message' => (string) ($result['error_message'] ?? $result['message'] ?? 'بروزرسانی محصول انجام نشد.'),
            ];
            if ($status >= 500 || in_array($status, [408, 425, 429], true)) {
                $retryable[$id] = $failed[$id];
                unset($failed[$id]);
            }
        }

        return ['updated' => count($results) - count($failed) - count($retryable), 'failed' => $failed, 'retryable' => $retryable];
    }

    /** Persist failed products independently so retry exhaustion cannot strand later pages. */
    private function enqueueRetryableItems(array $retryable, array $productIdsByRemote): array
    {
        if (!$retryable) return [];
        if (!$this->currentProcessingJobExists()) {
            throw RetryableException::temporary('ثبت تلاش مجدد محصولات به صف فعال نیاز دارد.');
        }
        $deferred = [];
        foreach ($retryable as $remoteId => $error) {
            $productId = $productIdsByRemote[(string) $remoteId] ?? 0;
            // This quick-update child is not interchangeable with a pending
            // custom/automatic update, which may omit price or stock entirely.
            $payload = ['product_id' => $productId, 'automatic' => false, 'price_stock_only' => true];
            if (!$productId || $this->jobManager->createJob(
                'sync_basalam_update_single_product', 'pending', json_encode($payload)
            ) === false) {
                throw RetryableException::temporary('ثبت تلاش مجدد محصول در صف ناموفق بود: ' . $remoteId);
            }
            $deferred[] = $productId;
        }
        // canRun() already waits for pending/processing single-product jobs.
        return $deferred;
    }

    private function assertBatchAccepted(array $response): void
    {
        $status = (int) ($response['status_code'] ?? 0);
        if ($status === 202) return;
        $message = 'بروزرسانی دسته جمعی محصولات ناموفق بود.';
        if ($status === 0 || $status >= 500 || in_array($status, [408, 425, 429], true)) {
            throw new RetryableException($message, $status);
        }
        throw new NonRetryableException($message, $status);
    }

    private function batchResultMap(array $productsData, $body): array
    {
        $expected = array_fill_keys(array_map('strval', array_column($productsData, 'id')), true);
        $results = [];
        $seen = [];
        foreach ($expected as $id => $_) {
            $results[$id] = ['id' => $id, 'has_error' => true, 'status_code' => 503,
                'error_message' => 'نتیجهٔ بروزرسانی دسته‌ای محصول دریافت نشد.'];
        }
        foreach ($this->decodeBatchResults($body) as $result) {
            if (!is_array($result) || !isset($result['id'])) continue;
            $id = (string) $result['id'];
            if (!isset($expected[$id])) continue;
            if (isset($seen[$id]) || (!array_key_exists('has_error', $result) && !$this->batchItemStatus($result))) {
                $results[$id] = ['id' => $id, 'has_error' => true, 'status_code' => 503,
                    'error_message' => 'نتیجهٔ بروزرسانی دسته‌ای محصول مشخص نیست.'];
            } else {
                $results[$id] = $result;
            }
            $seen[$id] = true;
        }
        return $results;
    }

    private function batchItemStatus(array $result): int
    {
        foreach ([$result['status_code'] ?? null, $result['status'] ?? null, $result['http_status'] ?? null,
            is_array($result['error'] ?? null) ? ($result['error']['status_code'] ?? $result['error']['status'] ?? null) : null] as $status) {
            if (is_numeric($status) && (int) $status >= 200 && (int) $status <= 599) return (int) $status;
        }
        return 0;
    }

    private function batchItemFailed(array $result): bool
    {
        return !empty($result['has_error']) || $this->batchItemStatus($result) >= 400
            || in_array($result['status'] ?? null, ['failed', 'error'], true);
    }

    private function batchItemAllowsFallback(array $result): bool
    {
        $status = $this->batchItemStatus($result);
        if ($status) return in_array($status, [400, 422], true);

        // Older batch responses expose only has_error/error_message. Do not
        // reinterpret authentication/ownership errors as field validation.
        $message = (string) ($result['error_message'] ?? $result['message'] ?? '');
        if (preg_match('/auth|permission|forbidden|credential|token|owner|دسترسی|توکن|مالک|تعلق|غرفه/iu', $message)) return false;
        return ProductSkuRetry::isDuplicateSkuError($result)
            || (bool) preg_match('/validation|invalid|\brequired\b|out of range|نامعتبر|اعتبارسنجی|اعتبار سنجی|الزامی/iu', $message);
    }

    private function batchFailureMessage(array $failed): string
    {
        $details = [];
        foreach ($failed as $id => $error) $details[] = $id . ': ' . $error['message'];
        return 'بروزرسانی برخی محصولات ناموفق بود: ' . implode('؛ ', $details);
    }

    private function decodeBatchResults($body): array
    {
        if (is_string($body)) $body = json_decode($body, true);
        if (!is_array($body)) return [];

        foreach (['data', 'results', 'items'] as $key) {
            if (isset($body[$key]) && is_array($body[$key])) return $body[$key];
        }

        foreach (array_keys($body) as $key) {
            if (!is_int($key)) return [];
        }

        return $body;
    }

    private function currentProcessingJobExists(): bool
    {
        return $this->jobManager->getJob([
            'job_type' => $this->getType(),
            'status' => 'processing',
        ]) !== null;
    }

    private function enqueueContinuation(int $lastId): void
    {
        if (!$this->currentProcessingJobExists()) return;
        if ($this->jobManager->createJob(
            $this->getType(), 'pending', json_encode(['last_updatable_product_id' => $lastId])
        ) === false) {
            throw RetryableException::temporary('ثبت ادامهٔ بروزرسانی دسته‌ای در صف ناموفق بود.');
        }
    }
}
