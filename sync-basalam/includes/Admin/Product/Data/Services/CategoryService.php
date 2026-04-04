<?php

namespace SyncBasalam\Admin\Product\Data\Services;

use SyncBasalam\Admin\Product\Category\CategoryMapping;
use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Services\Products\GetCategoryId;

defined('ABSPATH') || exit;

class CategoryService
{
    private static array $resolvedCategoryCache = [];

    public function getPrimaryCategoryId($product): ?int
    {
        $categoryIds = $this->getCategoryIds($product);
        if (empty($categoryIds)) return null;

        $lastCategoryId = end($categoryIds);
        return is_numeric($lastCategoryId) ? intval($lastCategoryId) : null;
    }

    public function getCategoryIds($product): array
    {
        $productId = $this->resolveProductId($product);

        if ($productId !== null && array_key_exists($productId, self::$resolvedCategoryCache)) {
            return self::$resolvedCategoryCache[$productId];
        }

        $mappedCategories = $this->normalizeCategoryIds($this->getMappedCategories($product));
        if (!empty($mappedCategories)) {
            return $this->storeCache($productId, $mappedCategories);
        }

        $productTitle = $product->get_name();

        $prefix = syncBasalamSettings()->getSettings(SettingsConfig::PRODUCT_PREFIX_TITLE);
        $suffix = syncBasalamSettings()->getSettings(SettingsConfig::PRODUCT_SUFFIX_TITLE);

        if (!empty($prefix)) $productTitle = $prefix . ' ' . $productTitle;
        if (!empty($suffix)) $productTitle = $productTitle . ' ' . $suffix;

        $productTitle = mb_substr($productTitle, 0, 120);
        $detectedCategories = GetCategoryId::getCategoryIdFromBasalam(urlencode($productTitle), 'multi');

        $detectedCategories = $this->normalizeCategoryIds(is_array($detectedCategories) ? $detectedCategories : []);

        return $this->storeCache($productId, $detectedCategories);
    }

    private function storeCache(?int $productId, array $categoryIds): array
    {
        if ($productId !== null) {
            self::$resolvedCategoryCache[$productId] = $categoryIds;
        }

        return $categoryIds;
    }

    private function resolveProductId($product): ?int
    {
        if (!is_object($product) || !method_exists($product, 'get_id')) return null;

        $productId = $product->get_id();
        if (!is_numeric($productId)) return null;

        return intval($productId);
    }

    private function normalizeCategoryIds(array $categoryIds): array
    {
        $normalized = [];

        foreach ($categoryIds as $categoryId) {
            if (!is_numeric($categoryId)) continue;

            $categoryId = intval($categoryId);
            if ($categoryId > 0) $normalized[] = $categoryId;
        }

        return array_values(array_unique($normalized));
    }

    private function getMappedCategories($product): array
    {
        $wooCategories = $product->get_category_ids();

        if (empty($wooCategories)) return [];

        foreach ($wooCategories as $wooCategoryId) {
            $mappedCategory = CategoryMapping::getBasalamCategoryForWooCategory($wooCategoryId);

            if ($mappedCategory && !empty($mappedCategory->basalam_category_ids)) return $mappedCategory->basalam_category_ids;
        }

        return [];
    }
}
