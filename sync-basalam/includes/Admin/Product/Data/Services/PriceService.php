<?php

namespace SyncBasalam\Admin\Product\Data\Services;

use SyncBasalam\Admin\Product\elements\SingleProduct\PriceChangeField;
use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Logger\Logger;
use SyncBasalam\Services\Products\FetchCommission;
use SyncBasalam\Utilities\ProductMetaKey;
use SyncBasalam\Utilities\PriceAdjustment;

defined('ABSPATH') || exit;

class PriceService
{
    public function calculateFinalPrice($product): ?int
    {
        $price = $this->getBasePrice($product);
        if (!$price) return null;

        return $this->applyPriceCalculations($price, $product);
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

    private function applyPriceCalculations(float $price, $product): ?int
    {
        $priceChangeValue = $this->getPriceChangeValue($product);
        $roundMode = syncBasalamSettings()->getSettings(SettingsConfig::ROUND_PRICE);
        $currency = get_woocommerce_currency();

        $finalPrice = $this->convertToRial($price, $currency);
        if (!$finalPrice) return null;


        if ($priceChangeValue !== '' && $priceChangeValue !== '0') $finalPrice = $this->applyPriceChange($finalPrice, $priceChangeValue, $product);

        if ($roundMode && $roundMode != 'none') $finalPrice = $this->applyRounding($finalPrice, $roundMode);

        if ($finalPrice < 1000) return null;

        return intval($finalPrice);
    }

    private function getPriceChangeValue($product): string
    {
        $productId = method_exists($product, 'get_id') ? intval($product->get_id()) : 0;

        if ($productId > 0) {
            $productValue = PriceAdjustment::normalize($this->getProductPriceChangeMeta($productId));

            if ($productValue !== null) return $productValue;

            if (method_exists($product, 'get_parent_id')) {
                $parentId = intval($product->get_parent_id());
                $parentValue = $parentId > 0
                    ? PriceAdjustment::normalize($this->getProductPriceChangeMeta($parentId))
                    : null;

                if ($parentValue !== null) return $parentValue;
            }
        }

        return (string) (PriceAdjustment::normalize(syncBasalamSettings()->getSettings(SettingsConfig::PRICE_CHANGE_VALUE)) ?? '0');
    }

    private function getProductPriceChangeMeta(int $productId): string
    {
        return (string) get_post_meta($productId, PriceChangeField::META_KEY, true);
    }

    private function convertToRial(float $price, string $currency)
    {
        if ($currency === 'IRT') return $price * 10;
        if ($currency === 'IRHT') return $price * 10000;
        if ($currency === 'IRHR') return $price * 1000;

        return $price;
    }

    private function applyPriceChange(float $price, string $priceChangeValue, $product = null): float
    {
        if (PriceAdjustment::isCommission($priceChangeValue)) return $this->applyCommissionCalculation($price, $product);

        $value = intval($priceChangeValue);

        if (PriceAdjustment::isPercent($value)) return $price + ($price * ($value / 100));

        return $price + ($value * 10);
    }

    private function applyCommissionCalculation(float $price, $product = null): float
    {
        $basalamProductId = $this->getBasalamProductId($product);
        $categoryIds = [];

        if ($basalamProductId !== null) {
            $commissionPercent = FetchCommission::fetchProductCommission($basalamProductId);
        } else {
            $categoryIds = $this->getCategoryIds($product);
            $commissionPercent = FetchCommission::fetchCategoryCommission($categoryIds);
        }

        $commissionPercent = floatval($commissionPercent);

        if ($commissionPercent <= 0 || $commissionPercent >= 100) return $price;

        $multiplier = 1 / (1 - ($commissionPercent / 100));
        $maxMultiplier = 1 + (PriceAdjustment::MAX_PERCENT / 100);

        if ($multiplier > $maxMultiplier) {
            Logger::warning('کارمزد دسته‌بندی باسلام خارج از بازه منطقی بود و افزایش قیمت به سقف مجاز محدود شد.', [
                'category_ids'     => $categoryIds,
                'commission_percent' => $commissionPercent,
                'max_percent'      => PriceAdjustment::MAX_PERCENT,
            ]);

            $multiplier = $maxMultiplier;
        }

        return $price * $multiplier;
    }

    /**
     * Resolve the remote product id used by the product commission endpoint.
     * Variations inherit the connection from their parent product.
     */
    private function getBasalamProductId($product): ?int
    {
        if (!is_object($product)) return null;

        $postIds = [];

        if (method_exists($product, 'get_parent_id')) {
            $parentId = intval($product->get_parent_id());
            if ($parentId > 0) {
                $postIds[] = $parentId;
            }
        }

        if (empty($postIds) && method_exists($product, 'get_id')) {
            $productId = intval($product->get_id());
            if ($productId > 0) $postIds[] = $productId;
        }

        $metaKey = ProductMetaKey::basalamProductId();
        foreach (array_unique($postIds) as $postId) {
            $basalamProductId = get_post_meta($postId, $metaKey, true);

            if (is_numeric($basalamProductId) && intval($basalamProductId) > 0) {
                return intval($basalamProductId);
            }
        }

        return null;
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
