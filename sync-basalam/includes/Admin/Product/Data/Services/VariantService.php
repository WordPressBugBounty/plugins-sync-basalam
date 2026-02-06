<?php

namespace SyncBasalam\Admin\Product\Data\Services;

use SyncBasalam\Admin\Settings\SettingsConfig;

defined('ABSPATH') || exit;

class VariantService
{
    private PriceService $priceService;
    private array $settings;

    public function __construct()
    {
        $this->priceService = new PriceService();
        $this->settings = syncBasalamSettings()->getSettings();
    }

    public function getVariants($product): array
    {
        if (!$product instanceof \WC_Product_Variable) return [];

        $variants = [];
        $variationIds = $product->get_children();

        foreach ($variationIds as $variationId) {
            $variant = $this->createVariant($variationId, $product);
            if ($variant) $variants[] = $variant;
        }

        return $variants;
    }

    private function createVariant(int $variationId, $parentProduct): ?array
    {
        $variation = wc_get_product($variationId);
        if (!$variation) return null;

        $price = $this->priceService->calculateFinalPrice($variation);
        if (!$price) return null;

        $basalamVariantId = get_post_meta($variationId, 'sync_basalam_variation_id', true);

        $variantData = [
            'primary_price' => $price,
            'stock' => $this->getVariantStock($variation),
            'properties' => $this->getVariantProperties($variation, $parentProduct),
        ];

        // Add Basalam variant ID if it exists
        if (!empty($basalamVariantId)) {
            $variantData['id'] = $basalamVariantId;
        }

        return $variantData;
    }

    private function getVariantStock($variation): int
    {
        $defaultStock = $this->settings[SettingsConfig::DEFAULT_STOCK_QUANTITY];
        $safeStock = $this->settings[SettingsConfig::SAFE_STOCK];
        $stock = $variation->get_stock_quantity();
        $stockStatus = $variation->get_stock_status();

        $calculatedStock = $stockStatus === 'instock' ? $stock ?? $defaultStock : 0;

        if ($safeStock > 0 && $calculatedStock <= $safeStock) return 0;

        return $calculatedStock;
    }

    private function getVariantProperties($variation, $parentProduct): array
    {
        $properties = [];
        $variationData = $variation->get_variation_attributes();

        foreach ($variationData as $attributeName => $attributeValue) {
            $taxonomyName = str_replace('attribute_', '', $attributeName);
            $attributeLabel = str_replace(['pa_', '-'], ' ', wc_attribute_label($taxonomyName, $parentProduct));

            $valueName = rawurldecode($attributeValue);
            if (taxonomy_exists($taxonomyName)) {
                $term = get_term_by('slug', $attributeValue, $taxonomyName);
                if ($term && !is_wp_error($term)) {
                    $valueName = $term->name;
                }
            }

            $properties[] = [
                'property' => $attributeLabel,
                'value' => str_replace('-', ' ', mb_convert_encoding($valueName, 'UTF-8', 'auto')),
            ];
        }

        return $properties;
    }
}
