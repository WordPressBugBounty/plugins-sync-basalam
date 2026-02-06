<?php

namespace SyncBasalam\Admin\Product\Data\Services;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Utilities\TextConverter;
use SyncBasalam\Services\Products\GetCategoryAttr;

defined('ABSPATH') || exit;

class AttributeService
{
    private MobileDataHandler $mobileDataHandler;

    public function __construct()
    {
        $this->mobileDataHandler = new MobileDataHandler();
    }

    public function getAttributeSuffix($product): ?string
    {
        $isEnabled = syncBasalamSettings()->getSettings(SettingsConfig::PRODUCT_ATTRIBUTE_SUFFIX_ENABLED);

        if ($isEnabled !== 'yes') return null;

        $attributeName = trim(syncBasalamSettings()->getSettings(SettingsConfig::PRODUCT_ATTRIBUTE_SUFFIX_PRIORITY));

        if (empty($attributeName)) return null;

        $wooAttributes = $this->getWooCommerceAttributes($product);

        foreach ($wooAttributes as $attribute) {
            if (trim($attribute['title']) === $attributeName && !empty($attribute['value'])) return $attribute['value'];
        }

        return null;
    }

    public function generateDescription($product): string
    {
        $descriptionParts = [];

        if (syncBasalamSettings()->getSettings(SettingsConfig::ADD_SHORT_DESC_TO_DESC_PRODUCT) === 'yes') {
            $shortDesc = TextConverter::convertHtmlToPlainText($product->get_short_description());

            if (!empty($shortDesc)) $descriptionParts[] = trim($shortDesc);
        }

        $mainDesc = TextConverter::convertHtmlToPlainText($product->get_description());

        if (!empty($mainDesc)) $descriptionParts[] = trim($mainDesc);

        if (syncBasalamSettings()->getSettings(SettingsConfig::ADD_ATTR_TO_DESC_PRODUCT) === 'yes') {
            $attributes = $this->getWooCommerceAttributes($product);

            if (!empty($attributes)) {
                $attributeTexts = [];

                foreach ($attributes as $attribute) {
                    if (!empty($attribute['title']) && !empty($attribute['value'])) {
                        $attributeTexts[] = trim($attribute['title']) . ' : ' . trim($attribute['value']);
                    }
                }

                if (!empty($attributeTexts)) $descriptionParts[] = implode("\n", $attributeTexts);
            }
        }

        $fullDescription = implode("\n\n", $descriptionParts);
        return mb_substr($fullDescription, 0, 5000);
    }

    public function getBasalamAttributes($product): array
    {
        $attributes = [];

        if ($this->isMobileProduct($product)) $attributes = array_merge($attributes, $this->getMobileAttributes($product));

        $wooAttributes = $this->getMappedAttributes($product);
        if (!empty($wooAttributes)) $attributes = array_merge($attributes, $wooAttributes);

        return $attributes;
    }

    private function getWooCommerceAttributes($product): array
    {
        $attributes = [];
        $productAttributes = $product->get_attributes();

        if (empty($productAttributes)) return [];

        foreach ($productAttributes as $attribute) {
            if ($attribute->is_taxonomy()) {
                $taxonomy = $attribute->get_name();
                $label = wc_attribute_label($taxonomy);
                $terms = wc_get_product_terms($product->get_id(), $taxonomy, ['fields' => 'names']);
                $value = implode(', ', $terms);
            } else {
                $label = wc_attribute_label($attribute->get_name());
                $options = $attribute->get_options();
                $value = implode(', ', $options);
            }

            $attributes[] = [
                'title' => $label,
                'value' => $value,
            ];
        }

        return $attributes;
    }

    private function getMappedAttributes($product): array
    {
        $wooAttributes = $this->getWooCommerceAttributes($product);
        $mappedOptions = $this->getMappedCategoryOptions();

        if (empty($wooAttributes) || empty($mappedOptions)) return [];

        foreach ($wooAttributes as &$wooAttribute) {
            foreach ($mappedOptions as $mappedOption) {
                if (trim($mappedOption['woo_name']) === trim($wooAttribute['title'])) {
                    $wooAttribute['title'] = $mappedOption['sync_basalam_name'];
                    break;
                }
            }
        }

        $basalamAttributes = $this->getBasalamAttributeDefinitions($product);
        $matchedAttributes = [];

        foreach ($wooAttributes as $wooAttribute) {
            foreach ($basalamAttributes as $basalamAttribute) {
                if (trim($wooAttribute['title']) === trim($basalamAttribute['title'])) {
                    $matchedAttributes[] = [
                        'attribute_id' => $basalamAttribute['id'],
                        'value' => $wooAttribute['value'],
                    ];
                    break;
                }
            }
        }

        return $matchedAttributes;
    }

    private function getBasalamAttributeDefinitions($product): array
    {
        try {
            $categoryService = new CategoryService();
            $categoryId = $categoryService->getPrimaryCategoryId($product);

            if (!$categoryId) return [];

            $response = GetCategoryAttr::getAttr($categoryId);
            $attributes = [];
            $responseBody = json_decode($response['body'], true);

            foreach ($responseBody['data'] as $group) {
                foreach ($group['attributes'] as $attribute) {
                    $attributes[] = [
                        'id' => $attribute['id'],
                        'title' => $attribute['title'],
                    ];
                }
            }

            return $attributes;
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getMobileAttributes($product): array
    {
        return $this->mobileDataHandler->getMobileAttributesAsArray($product);
    }

    private function getMappedCategoryOptions(): array
    {
        global $wpdb;
        $categoryOptionsManager = new \SyncBasalam\Admin\Product\Category\CategoryOptions($wpdb);
        return $categoryOptionsManager->getAll();
    }

    private function isMobileProduct($product): bool
    {
        return get_post_meta($product->get_id(), '_sync_basalam_is_mobile_product_checkbox', true) === 'yes';
    }
}
