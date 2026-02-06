<?php

namespace SyncBasalam\Admin\Product\Data\Services;

use SyncBasalam\Admin\Product\Category\CategoryMapping;
use SyncBasalam\Services\Products\GetCategoryId;

defined('ABSPATH') || exit;

class CategoryService
{
    public function getPrimaryCategoryId($product): ?int
    {
        $categoryIds = $this->getCategoryIds($product);
        return !empty($categoryIds) ? end($categoryIds) : null;
    }

    public function getCategoryIds($product): array
    {
        $mappedCategories = $this->getMappedCategories($product);
        if ($mappedCategories) return $mappedCategories;

        $productTitle = mb_substr($product->get_name(), 0, 120);
        $detectedCategories = GetCategoryId::getCategoryIdFromBasalam(urlencode($productTitle), 'multi');

        return $detectedCategories ?: [];
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
