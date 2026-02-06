<?php

namespace SyncBasalam\Admin\Product\Operations;

use SyncBasalam\Admin\Product\Operations\AbstractProductOperation;
use SyncBasalam\Services\Products\CreateSingleProductService;
use SyncBasalam\Services\Products\Discount\DiscountTaskProcessor;
use SyncBasalam\Logger\Logger;
use SyncBasalam\Admin\Product\Data\ProductDataFacade;

defined('ABSPATH') || exit;

class CreateProduct extends AbstractProductOperation
{
    private CreateSingleProductService $createProductService;
    private DiscountTaskProcessor $discountProcessor;

    public function __construct()
    {
        parent::__construct();
        $this->createProductService = new CreateSingleProductService();
        $this->discountProcessor = new DiscountTaskProcessor();
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

        $basalamProductId = get_post_meta($product_id, 'sync_basalam_product_id', true);
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
