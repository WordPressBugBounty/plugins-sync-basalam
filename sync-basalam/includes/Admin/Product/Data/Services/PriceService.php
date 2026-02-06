<?php

namespace SyncBasalam\Admin\Product\Data\Services;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Services\Products\FetchCommission;

defined('ABSPATH') || exit;

class PriceService
{
    public function calculateFinalPrice($product): ?int
    {
        $price = $this->getBasePrice($product);
        if (!$price) return 999999;

        $categoryIds = $this->getCategoryIds($product);
        return $this->applyPriceCalculations($price, $categoryIds);
    }

    private function getBasePrice($product)
    {
        $priceField = syncBasalamSettings()->getSettings(SettingsConfig::PRODUCT_PRICE_FIELD);
        $regularPrice = $product->get_regular_price();
        $salePrice = $product->get_sale_price();

        if ($priceField === 'original_price' || $priceField === 'sale_strikethrough_price') {
            return $regularPrice && is_numeric($regularPrice) ? floatval($regularPrice) : null;
        }

        if ($priceField === 'sale_price') {
            if ($salePrice && is_numeric($salePrice)) return floatval($salePrice);
            return $regularPrice && is_numeric($regularPrice) ? floatval($regularPrice) : null;
        }

        return null;
    }

    private function applyPriceCalculations(float $price, array $categoryIds): ?int
    {
        $increaseValue = intval(syncBasalamSettings()->getSettings(SettingsConfig::INCREASE_PRICE_VALUE));
        $roundMode = syncBasalamSettings()->getSettings(SettingsConfig::ROUND_PRICE);
        $currency = get_woocommerce_currency();

        $finalPrice = $this->convertToRial($price, $currency);
        if (!$finalPrice) return null;

        if ($increaseValue) $finalPrice = $this->applyIncrease($finalPrice, $increaseValue, $categoryIds);
        if ($roundMode && $roundMode != 'none') $finalPrice = $this->applyRounding($finalPrice, $roundMode);

        if ($finalPrice < 1000) return null;

        return intval($finalPrice);
    }

    private function convertToRial(float $price, string $currency)
    {
        if ($currency === 'IRT') return $price * 10;
        if ($currency === 'IRHT') return $price * 10000;
        if ($currency === 'IRHR') return $price * 1000;

        return $price;
    }

    private function applyIncrease(float $price, int $increaseValue, array $categoryIds): float
    {
        if ($increaseValue === -1) return $this->applyCommissionCalculation($price, $categoryIds);

        if ($increaseValue <= 100) return $price + ($price * ($increaseValue / 100));

        return $price + ($increaseValue * 10);
    }

    private function applyCommissionCalculation(float $price, array $categoryIds): float
    {
        $categoryPercent = FetchCommission::fetchCategoryCommission($categoryIds);

        if ($categoryPercent > 0 && $categoryPercent < 100) return $price / (1 - ($categoryPercent / 100));
        else return $price;

    }

    private function applyRounding(float $price, $mode): float
    {
        if ($mode === 'up') return ceil($price / 10000) * 10000;

        if ($mode === 'down') return floor($price / 10000) * 10000;

        return $price;
    }

    private function getCategoryIds($product): array
    {
        $categoryService = new CategoryService();
        return $categoryService->getCategoryIds($product);
    }
}
