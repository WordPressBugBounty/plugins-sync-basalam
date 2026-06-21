<?php

namespace SyncBasalam\Services\Products;

use SyncBasalam\Admin\Product\Data\Services\CategoryService;
use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Jobs\Exceptions\NonRetryableException;
use SyncBasalam\Logger\Logger;

defined('ABSPATH') || exit;

/**
 * Enforces the Basalam per-category maximum preparation days while product data is collected.
 *
 * When the requested preparation days exceed the category limit:
 *  - setting "yes" (default): the value is capped to the limit and returned.
 *  - setting "no": the product is skipped (a log is written and a NonRetryableException thrown).
 */
class PreparationDaysGuard
{
    private $categoryPreparationService;
    private $categoryService;

    public function __construct($categoryPreparationService = null, $categoryService = null)
    {
        $this->categoryPreparationService = $categoryPreparationService
            ?: syncBasalamContainer()->get(CategoryPreparationService::class);
        $this->categoryService = $categoryService ?: new CategoryService();
    }

    /**
     * @return int The (possibly capped) preparation days.
     * @throws NonRetryableException When the product must be skipped.
     */
    public function enforce(int $preparationDays, $product): int
    {
        $categoryId = $this->resolveCategoryId($product);
        if (empty($categoryId)) return $preparationDays;

        $maxPreparation = $this->categoryPreparationService->getMaxPreparationDays($categoryId);
        if ($maxPreparation === null) return $preparationDays;

        if ($preparationDays <= $maxPreparation) return $preparationDays;

        $shouldCap = syncBasalamSettings()->getSettings(SettingsConfig::CAP_PREPARATION_TO_CATEGORY_MAX) !== 'no';

        if ($shouldCap) {
            return $maxPreparation;
        }

        $message = sprintf(
            'زمان آماده‌سازی محصول (%d روز) بیشتر از حداکثر مجاز دسته‌بندی باسلام (%d روز) است و طبق تنظیمات، محصول ارسال نشد.',
            $preparationDays,
            $maxPreparation
        );

        throw NonRetryableException::invalidData($message);
    }

    private function resolveCategoryId($product): ?int
    {
        if (!is_object($product)) return null;

        $categoryId = $this->categoryService->getPrimaryCategoryId($product);

        return $categoryId ?: null;
    }
}
