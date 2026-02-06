<?php

namespace SyncBasalam\Admin\Product;

use SyncBasalam\Admin\Product\Data\Handlers\SimpleProductHandler;
use SyncBasalam\Admin\Product\Data\Handlers\VariableProductHandler;
use SyncBasalam\Admin\Product\Data\Handlers\ProductDataHandlerInterface;
use SyncBasalam\Admin\Product\Data\Strategies\DataStrategyInterface;
use SyncBasalam\Admin\Product\Data\Strategies\CreateProductStrategy;
use SyncBasalam\Admin\Product\Data\Strategies\UpdateProductStrategy;
use SyncBasalam\Admin\Product\Data\Strategies\QuickUpdateProductStrategy;
use SyncBasalam\Admin\Product\Data\Strategies\CustomUpdateProductStrategy;

defined('ABSPATH') || exit;

class ProductDataFactory
{
    public function createHandler($product): ProductDataHandlerInterface
    {
        switch ($product->get_type()) {
            case 'simple':
                return new SimpleProductHandler();
            case 'variable':
                return new VariableProductHandler();
            default:
                return new SimpleProductHandler();
        }
    }

    public function createStrategy(string $type): DataStrategyInterface
    {
        switch ($type) {
            case 'create':
                return new CreateProductStrategy();
            case 'update':
                return new UpdateProductStrategy();
            case 'quick_update':
                return new QuickUpdateProductStrategy();
            case 'custom_update':
                return new CustomUpdateProductStrategy();
            default:
                throw new \InvalidArgumentException("Unknown strategy type: {$type}");
        }
    }
}
