<?php

namespace SyncBasalam\Admin\Product\Data;

use SyncBasalam\Admin\Product\Data\Validators\ValidatorChain;
use SyncBasalam\Admin\Product\Data\Validators\ProductExistenceValidator;
use SyncBasalam\Admin\Product\Data\Validators\ProductStatusValidator;
use SyncBasalam\Admin\Product\ProductDataFactory;

defined('ABSPATH') || exit;

class ProductDataFacade
{
    private static ?ProductDataBuilder $builder = null;
    private static ?ProductDataFactory $factory = null;
    private static ?ValidatorChain $validator = null;

    private static function initialize(): void
    {
        if (self::$builder === null) {
            self::$factory = new ProductDataFactory();
            self::$validator = new ValidatorChain();

            self::$validator->add(new ProductExistenceValidator())->add(new ProductStatusValidator());

            self::$builder = new ProductDataBuilder(self::$validator, self::$factory);
        }
    }

    public static function get(int $productId, ?array $categoryIds = null): array
    {
        self::initialize();

        $product = wc_get_product($productId);
        if (!$product) throw new \InvalidArgumentException("Product with ID {$productId} not found");

        $basalamProductId = get_post_meta($productId, 'sync_basalam_product_id', true);
        $isUpdate = !empty($basalamProductId);

        $syncFields = syncBasalamSettings()->getSettings(\SyncBasalam\Admin\Settings\SettingsConfig::SYNC_PRODUCT_FIELDS);

        $strategy = 'create';
        if ($isUpdate) {
            if ($syncFields === 'price_stock') $strategy = 'quick_update';
            elseif ($syncFields === 'custom') $strategy = 'custom_update';
            else $strategy = 'update';
        }

        return self::$builder
            ->reset()
            ->setStrategy(self::$factory->createStrategy($strategy))
            ->fromWooProduct($productId)
            ->withCategoryIds($categoryIds)
            ->build();
    }

    public static function validateProduct($product): void
    {
        self::initialize();
        self::$validator->validate($product);
    }
}
