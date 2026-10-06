<?php

namespace SyncBasalam\Admin\Product\Data\Services;

use SyncBasalam\Admin\Settings\SettingsConfig;

defined('ABSPATH') || exit;

class VariantService
{
    private $priceService;
    private array $settings;

    public function __construct()
    {
        $this->priceService = new PriceService();
        $this->settings = syncBasalamSettings()->getSettings();
    }

    public function getVariants($product): array
    {
        if (!$product instanceof \WC_Product_Variable) return [];

        return array_column($this->getVariantEntries($product), 'data');
    }

    private function getVariantEntries($product): array
    {
        $entries = [];
        $seen = [];

        // Children follow WooCommerce's menu order: the first matching variation
        // owns a combination when a specific variation overlaps an "Any" one.
        foreach ($product->get_children() as $variationId) {
            $variation = wc_get_product($variationId);
            if (!$variation || $variation->get_status() !== 'publish') continue;

            $combinations = $this->expandVariationAttributes($variation, $product);
            $variant = $this->createVariant($variationId, $product);
            $mapping = get_post_meta($variationId, 'sync_basalam_variation_map', true);
            $isWildcard = $this->isWildcardVariation($variation, $product);

            foreach ($combinations as $attributes) {
                $keyAttributes = $attributes;
                ksort($keyAttributes);
                $key = wp_json_encode($keyAttributes);
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                if (!$variant) continue;

                $data = $variant;
                $data['properties'] = $this->getVariantProperties($variation, $product, $attributes);
                $propertyKey = $this->getPropertyKey($data['properties']);
                if (is_array($mapping)) {
                    unset($data['id']);
                    if (!empty($mapping[$propertyKey])) $data['id'] = $mapping[$propertyKey];
                } elseif ($isWildcard) {
                    // Rebuild legacy empty-property variants through a product PATCH.
                    unset($data['id']);
                }

                $entries[] = ['variation_id' => $variationId, 'data' => $data];
            }
        }

        return $entries;
    }

    private function expandVariationAttributes($variation, $product): array
    {
        $selected = $variation->get_variation_attributes();
        if (!$product->get_variation_attributes()) return [];
        $combinations = [[]];

        foreach ($product->get_variation_attributes() as $name => $options) {
            $attributeName = 'attribute_' . sanitize_title($name);
            $value = $selected[$attributeName] ?? '';
            $values = $value === '' ? $options : [$value];
            $values = array_values(array_unique(array_filter($values, static function ($option) {
                return trim((string) $option) !== '';
            })));

            $expanded = [];
            foreach ($combinations as $combination) {
                foreach ($values as $option) {
                    $expanded[] = array_merge($combination, [$attributeName => (string) $option]);
                }
            }
            $combinations = $expanded;
        }

        return $combinations;
    }

    private function isWildcardVariation($variation, $product): bool
    {
        $attributes = $variation->get_variation_attributes();
        foreach ($product->get_variation_attributes() as $name => $options) {
            if (($attributes['attribute_' . sanitize_title($name)] ?? '') === '') return true;
        }

        return false;
    }

    private function needsExpandedMapping($product): bool
    {
        if (!$product instanceof \WC_Product_Variable) return false;

        foreach ($product->get_children() as $variationId) {
            $variation = wc_get_product($variationId);
            if (!$variation) continue;
            if ($this->isWildcardVariation($variation, $product)) return true;
            if (get_post_meta($variationId, 'sync_basalam_variation_map', true)) return true;
        }

        return false;
    }

    /** Map every expanded remote variant back to its original WooCommerce variation. */
    public function syncExpandedVariationIds($product, array $variants): void
    {
        if (!$this->needsExpandedMapping($product)) return;

        $remoteIds = [];
        foreach ($variants as $variant) {
            if (!empty($variant['id'])) {
                $remoteIds[$this->getPropertyKey($variant['properties'] ?? [])] = $variant['id'];
            }
        }

        $mappings = [];
        foreach ($this->getVariantEntries($product) as $entry) {
            $key = $this->getPropertyKey($entry['data']['properties']);
            if (isset($remoteIds[$key])) $mappings[$entry['variation_id']][$key] = $remoteIds[$key];
        }

        foreach ($product->get_children() as $variationId) {
            delete_post_meta($variationId, 'sync_basalam_variation_id');
            delete_post_meta($variationId, 'sync_basalam_variation_map');
            if (empty($mappings[$variationId])) continue;

            update_post_meta($variationId, 'sync_basalam_variation_map', $mappings[$variationId]);
            // Separate rows keep the existing order lookup working for every id.
            foreach (array_unique($mappings[$variationId]) as $remoteId) {
                add_post_meta($variationId, 'sync_basalam_variation_id', $remoteId);
            }
        }
    }

    private function getPropertyKey(array $properties): string
    {
        $values = [];
        foreach ($properties as $property) {
            $name = $property['property'] ?? '';
            $value = $property['value'] ?? '';
            if (is_array($name)) $name = $name['title'] ?? $name['name'] ?? '';
            if (is_array($value)) $value = $value['title'] ?? $value['name'] ?? '';
            $values[$this->normalizeProperty($name)] = $this->normalizeProperty($value);
        }
        ksort($values);

        return wp_json_encode($values);
    }

    private function normalizeProperty(string $value): string
    {
        $value = mb_strtolower(trim(rawurldecode($value)), 'UTF-8');
        $value = str_replace(['ي', 'ك', '-', '_', '–', '—'], ['ی', 'ک', ' ', ' ', ' ', ' '], $value);

        return preg_replace('/\s+/u', ' ', $value);
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
            'stock' => $this->getVariantStock($variation, $parentProduct),
            'properties' => $this->getVariantProperties($variation, $parentProduct),
        ];

        $sku = trim((string) $variation->get_sku());
        if ($sku !== '') {
            $variantData['sku'] = $sku;
        }

        // Add Basalam variant ID if it exists
        if (!empty($basalamVariantId)) {
            $variantData['id'] = $basalamVariantId;
        }

        return $variantData;
    }

    private function getVariantStock($variation, $parentProduct): int
    {
        $defaultStock = $this->settings[SettingsConfig::DEFAULT_STOCK_QUANTITY];
        $safeStock = $this->settings[SettingsConfig::SAFE_STOCK];
        $stockSource = $this->settings[SettingsConfig::VARIABLE_PRODUCT_STOCK_SOURCE];

        [$stock, $stockStatus] = $this->resolveStockByPriority($stockSource, $variation, $parentProduct);

        $calculatedStock = $stockStatus === 'instock' ? $stock ?? $defaultStock : 0;

        if ($safeStock > 0 && $calculatedStock <= $safeStock) return 0;

        return $calculatedStock;
    }

    private function resolveStockByPriority(string $stockSource, $variation, $parentProduct): array
    {
        $preferredProduct = $stockSource === 'product' ? $parentProduct : $variation;
        $fallbackProduct = $stockSource === 'product' ? $variation : $parentProduct;

        $stock = $preferredProduct->get_stock_quantity();
        $stockStatus = $preferredProduct->get_stock_status();

        // If preferred source has no numeric stock, fallback to the other source.
        if ($stock === null && $stockStatus === 'instock') {
            $fallbackStock = $fallbackProduct->get_stock_quantity();
            $fallbackStockStatus = $fallbackProduct->get_stock_status();

            if ($fallbackStock !== null || $fallbackStockStatus !== 'instock') {
                $stock = $fallbackStock;
                $stockStatus = $fallbackStockStatus;
            }
        }

        return [$stock, $stockStatus];
    }

    private function getVariantProperties($variation, $parentProduct, ?array $attributes = null): array
    {
        $properties = [];
        $variationData = $attributes ?? $variation->get_variation_attributes();

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
