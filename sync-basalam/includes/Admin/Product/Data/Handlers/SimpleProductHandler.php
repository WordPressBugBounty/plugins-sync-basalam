<?php

namespace SyncBasalam\Admin\Product\Data\Handlers;

use SyncBasalam\Admin\Product\Data\Services\CategoryService;
use SyncBasalam\Admin\Product\Data\Services\PriceService;
use SyncBasalam\Admin\Product\Data\Services\PhotoService;
use SyncBasalam\Admin\Product\Data\Services\AttributeService;
use SyncBasalam\Admin\Settings\SettingsConfig;

defined('ABSPATH') || exit;

class SimpleProductHandler implements ProductDataHandlerInterface
{
    private CategoryService $categoryService;
    private PriceService $priceService;
    private PhotoService $photoService;
    private AttributeService $attributeService;
    private array $settings;

    public function __construct()
    {
        $this->categoryService = new CategoryService();
        $this->priceService = new PriceService();
        $this->photoService = new PhotoService();
        $this->attributeService = new AttributeService();
        $this->settings = syncBasalamSettings()->getSettings();
    }

    public function getName($product): string
    {
        $baseName = $product->get_name();

        $prefix = $this->settings[SettingsConfig::PRODUCT_PREFIX_TITLE];
        $suffix = $this->settings[SettingsConfig::PRODUCT_SUFFIX_TITLE];

        $name = $prefix ? "{$prefix} {$baseName}" : $baseName;
        $name = $suffix ? "{$name} {$suffix}" : $name;

        $attributeSuffix = $this->attributeService->getAttributeSuffix($product);
        if ($attributeSuffix) $name .= " ({$attributeSuffix})";

        return mb_substr($name, 0, 120);
    }

    public function getDescription($product): string
    {
        return $this->attributeService->generateDescription($product);
    }

    public function getCategoryId($product): ?int
    {
        return $this->categoryService->getPrimaryCategoryId($product);
    }

    public function getCategoryIds($product): array
    {
        return $this->categoryService->getCategoryIds($product);
    }

    public function getPrice($product): ?int
    {
        return $this->priceService->calculateFinalPrice($product);
    }

    public function getStock($product): int
    {
        $defaultStock = $this->settings[SettingsConfig::DEFAULT_STOCK_QUANTITY];
        $safeStock = $this->settings[SettingsConfig::SAFE_STOCK];
        $stock = $product->get_stock_quantity();
        $stockStatus = $product->get_stock_status();

        $calculatedStock = $stockStatus === 'instock' ? $stock ?? $defaultStock : 0;

        if ($safeStock > 0 && $calculatedStock <= $safeStock) return 0;

        return $calculatedStock;
    }

    public function getWeight($product): ?int
    {
        if (empty($product->get_weight())) return $this->settings[SettingsConfig::DEFAULT_WEIGHT];

        $weight = str_replace(',', '.', $product->get_weight());
        $weightUnit = get_option('woocommerce_weight_unit');

        return ($weightUnit === 'kg') ? floatval($weight) * 1000 : floatval($weight);
    }

    public function getPackageWeight($product): int
    {
        $weight = $this->getWeight($product) ?? 0;
        $packageWeight = $this->settings[SettingsConfig::DEFAULT_PACKAGE_WEIGHT];

        return intval($weight + $packageWeight);
    }

    public function getMainPhoto($product): ?int
    {
        return $this->photoService->getMainPhotoId($product);
    }

    public function getGalleryPhotos($product): array
    {
        return $this->photoService->getGalleryPhotoIds($product);
    }

    public function getPreparationDays($product): int
    {
        return $this->settings[SettingsConfig::DEFAULT_PREPARATION];
    }

    public function getUnitType($product): int
    {
        $unitType = get_post_meta($product->get_id(), '_sync_basalam_product_unit', true);
        return ($unitType && is_numeric($unitType)) ? intval($unitType) : 6304;
    }

    public function getUnitQuantity($product): int
    {
        $quantity = get_post_meta($product->get_id(), '_sync_basalam_product_value', true);
        return is_numeric($quantity) ? intval($quantity) : 1;
    }

    public function isWholesale($product): bool
    {
        $globalSetting = $this->settings[SettingsConfig::ALL_PRODUCTS_WHOLESALE];

        if ($globalSetting === 'all') return true;

        return get_post_meta($product->get_id(), '_sync_basalam_is_wholesale', true) === 'yes';
    }

    public function getVariants($product): array
    {
        return [];
    }

    public function getAttributes($product): array
    {
        return $this->attributeService->getBasalamAttributes($product);
    }
}
