<?php

namespace SyncBasalam\Admin\Product\Operations;

use SyncBasalam\Admin\Product\Operations\AbstractProductOperation;
use SyncBasalam\Services\Products\CreateSingleProductService;
use SyncBasalam\Services\Products\Discount\DiscountTaskProcessor;
use SyncBasalam\Logger\Logger;
use SyncBasalam\Admin\Product\Data\ProductDataFacade;
use SyncBasalam\Utilities\ProductMetaKey;

defined('ABSPATH') || exit;

class CreateProduct extends AbstractProductOperation
{
    private $createProductService;
    private $discountProcessor;

    public function __construct(
        $createProductService = null,
        $discountProcessor = null
    )
    {
        parent::__construct();
        $this->createProductService = $createProductService ?: syncBasalamContainer()->get(CreateSingleProductService::class);
        $this->discountProcessor = $discountProcessor ?: syncBasalamContainer()->get(DiscountTaskProcessor::class);
    }


    protected function run(int $product_id, array $args = []): array
    {
        $categoryIds = $args['category_ids'] ?? [];

        $productData = ProductDataFacade::get($product_id, $categoryIds);

        $createResult = $this->createProductService->createProductInBasalam($productData, $product_id);

        if ($createResult['success']) $this->discountProcessor->handleProductDiscount($product_id);

        return $createResult;
    }

    public function validate(int $product_id): bool
    {
        if (!parent::validate($product_id)) return false;

        $basalamProductId = get_post_meta($product_id, ProductMetaKey::basalamProductId(), true);
        if (!empty($basalamProductId)) {
            Logger::warning(sprintf('محصول %d از قبل به باسلام متصل است.', $product_id), [
                'product_id' => $product_id,
                'شناسه_محصول_باسلام' => $basalamProductId,
                'عملیات' => $this->getOperationNameFromClass()
            ]);
            return false;
        }

        return true;
    }
}
