<?php

namespace SyncBasalam\Admin\Product\Operations;

use SyncBasalam\Admin\Product\Operations\AbstractProductOperation;
use SyncBasalam\Services\Products\UpdateSingleProductService;
use SyncBasalam\Admin\Product\Data\ProductDataFacade;
use SyncBasalam\Logger\Logger;
use SyncBasalam\Services\Products\Discount\DiscountTaskProcessor;
use SyncBasalam\Jobs\Exceptions\NonRetryableException;
use SyncBasalam\Services\VendorSyncPolicy;
use SyncBasalam\Services\Products\ProductPriceStockRetry;

defined('ABSPATH') || exit;

class UpdateProduct extends AbstractProductOperation
{
    private $updateProductService;
    private $discountProcessor;

    public function __construct(
        $updateProductService = null,
        $discountProcessor = null
    )
    {
        parent::__construct();
        $this->updateProductService = $updateProductService ?: syncBasalamContainer()->get(UpdateSingleProductService::class);
        $this->discountProcessor = $discountProcessor ?: syncBasalamContainer()->get(DiscountTaskProcessor::class);
    }


    protected function run(int $productId, array $args = []): array
    {
        $vendorSyncPolicy = syncBasalamContainer()->get(VendorSyncPolicy::class);
        if (!$vendorSyncPolicy->canUpdate()) {
            throw NonRetryableException::invalidData($vendorSyncPolicy->getRestrictionMessage(false));
        }

        $categoryIds = $args['category_ids'] ?? null;

        $productData = null;
        try {
            $productData = ProductDataFacade::get($productId, $categoryIds);
            // This operation owns the one fallback, including preparation failures.
            $updateResult = $this->updateProductService->updateProductInBasalam($productData, $productId, false);
        } catch (\Throwable $error) {
            $requiredOnly = is_array($productData)
                && ProductPriceStockRetry::onlyPriceStock($productData) === $productData;
            $accessFailure = $productData !== null && in_array((int) $error->getCode(), [401, 403, 404], true);
            if ($requiredOnly || $accessFailure) {
                if ($error instanceof \Exception) throw $error;
                throw new \RuntimeException($error->getMessage(), (int) $error->getCode(), $error);
            }

            Logger::warning('بروزرسانی کامل انجام نشد؛ فقط قیمت و موجودی تازه ارسال می‌شود.', [
                'product_id' => $productId,
                'failed_stage' => $productData === null ? 'preparation' : 'full_update',
                'error_class' => get_class($error),
                'error_code' => $error->getCode(),
            ]);
            try {
                $updateResult = $this->updateProductService->updatePriceStockInBasalam($productId);
            } catch (\Throwable $fallbackError) {
                // Do not turn an unsuccessful required-field update into success.
                if ($fallbackError instanceof \Exception) throw $fallbackError;
                throw new \RuntimeException($fallbackError->getMessage(), (int) $fallbackError->getCode(), $fallbackError);
            }
        }

        if ($updateResult['success']) $this->discountProcessor->handleProductDiscount($productId);

        return $updateResult;
    }

    public function validate(int $productId): bool
    {
        if (!parent::validate($productId)) return false;

        $validation = $this->validator->validateBasalamConnection($productId);
        if (!$validation['valid']) {
            $this->validator->logValidationResult($productId, $validation, $this->getOperationNameFromClass());
            return false;
        }

        return true;
    }
}
