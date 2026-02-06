<?php

namespace SyncBasalam\Admin;

use SyncBasalam\Admin\Product\Services\ProductQueryService;
use SyncBasalam\Admin\Product\Services\ProductSyncService;
use SyncBasalam\Admin\Product\Services\ProductDisconnectService;

defined('ABSPATH') || exit;

class ProductService
{
    public static function enqueueAllProductsForCreation($includeOutOfStock = false, $postsPerPage = 100)
    {
        return (new ProductSyncService())->enqueueBulkCreate($includeOutOfStock, $postsPerPage);
    }

    public static function enqueueSelectedProductsForCreation($productIds): void
    {
        if (is_array($productIds)) (new ProductSyncService())->enqueueSelectedForCreate($productIds);
    }

    public static function getCreatableProducts($args = []): array
    {
        return (new ProductQueryService())->getCreatableProducts($args);
    }

    public static function enqueueSelectedProductsForUpdate($productIds): void
    {
        if (is_array($productIds)) (new ProductSyncService())->enqueueSelectedForUpdate($productIds);
    }

    public static function disconnectSelectedProducts($productIds): void
    {
        if (is_array($productIds)) (new ProductDisconnectService())->disconnectSelected($productIds);
    }

    public static function getUpdatableProducts($args = []): array
    {
        return (new ProductQueryService())->getUpdatableProducts($args);
    }

    public static function autoConnectAllProducts($page = 1): void
    {
        (new ProductSyncService())->enqueueAutoConnect($page);
    }
}
