<?php

namespace SyncBasalam\Admin\Product\Operations;

use SyncBasalam\Admin\Product\Operations\AbstractProductOperation;
use SyncBasalam\Services\Products\UpdateSingleProductService;
use SyncBasalam\Admin\Product\Data\ProductDataFacade;
use SyncBasalam\Logger\Logger;
use SyncBasalam\Services\Products\Discount\DiscountTaskProcessor;

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
        $categoryIds = $args['category_ids'] ?? null;

        $productData = ProductDataFacade::get($productId, $categoryIds);

        $updateResult = $this->updateProductService->updateProductInBasalam($productData, $productId);

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
