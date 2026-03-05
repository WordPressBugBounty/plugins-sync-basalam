<?php

namespace SyncBasalam\Services\Products\Discount;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Config\Endpoints;
use SyncBasalam\Services\ApiServiceManager;

class DiscountManager
{
    private $apiService;
    private $settingsAccessor;
    private string $url;

    public function __construct(
        $apiService = null
    ) {
        $this->apiService = $apiService ?: syncBasalamContainer()->get(ApiServiceManager::class);
        $this->settingsAccessor = syncBasalamSettings();
        $vendorId = $this->settingsAccessor->getSettings(SettingsConfig::VENDOR_ID);
        $this->url = sprintf(Endpoints::VENDOR_DISCOUNTS, $vendorId);
    }

    public function apply($discountPercent, $productIds, $variationIds, $activeDays = null)
    {
        if (!$activeDays) $activeDays = $this->settingsAccessor->getSettings(SettingsConfig::DISCOUNT_DURATION) ?? 7;

        $data = [
            'product_filter' => [
                'product_ids'   => $productIds,
                'variation_ids' => $variationIds,
                'status'        => [3568, 2976],
            ],
            'discount_percent' => $discountPercent,
            'active_days'      => $activeDays,
        ];

        try {
            return $this->apiService->post($this->url, $data);
        } catch (\Exception $e) {
            return [
                'status_code' => 500,
                'error' => 'خطا در اعمال تخفیف: ' . $e->getMessage(),
                'body' => null
            ];
        }
    }

    public function remove($productIds, $variationIds)
    {
        $data = [
            'product_filter' => [
                'product_ids'   => $productIds,
                'variation_ids' => $variationIds,
            ],
        ];

        try {
            return $this->apiService->delete($this->url, [], $data);
        } catch (\Exception $e) {
            return [
                'status_code' => 500,
                'error' => 'خطا در حذف تخفیف: ' . $e->getMessage(),
                'body' => null
            ];
        }
    }

    public static function calculateDiscountPercent($primaryPrice, $discountedPrice)
    {
        if ($primaryPrice <= 0) return 0;

        $discountPercent = (($primaryPrice - $discountedPrice) / $primaryPrice) * 100;

        return round($discountPercent);
    }
}
