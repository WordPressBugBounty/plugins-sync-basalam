<?php

namespace SyncBasalam\Services\Products;

use SyncBasalam\Config\Endpoints;
use SyncBasalam\Services\ApiServiceManager;

defined('ABSPATH') || exit;
class FetchCommission
{
    private static array $productCommissionCache = [];

    /**
     * Fetch the effective commission for an existing Basalam product.
     *
     * The product endpoint returns both the generic and category commission
     * sections.  For an update, Basalam's effective value is the value in
     * commission_data.commission_percent.
     */
    public static function fetchProductCommission($basalamProductId)
    {
        if (!is_numeric($basalamProductId) || intval($basalamProductId) <= 0) return 0;

        $basalamProductId = intval($basalamProductId);
        if (array_key_exists($basalamProductId, self::$productCommissionCache)) {
            return self::$productCommissionCache[$basalamProductId];
        }

        $apiservice = syncBasalamContainer()->get(ApiServiceManager::class);
        $url = sprintf(Endpoints::PRODUCT_COMMISSION, $basalamProductId);

        try {
            $result = $apiservice->get($url);
        } catch (\Throwable $e) {
            return 0;
        }

        if (!is_array($result) || !array_key_exists('body', $result)) {
            return 0;
        }

        $body = $result['body'];
        if (is_string($body)) $body = json_decode($body, true);

        if (!is_array($body)
            || !isset($body['commission_data'])
            || !is_array($body['commission_data'])
            || !array_key_exists('commission_percent', $body['commission_data'])
            || !is_numeric($body['commission_data']['commission_percent'])
        ) {
            return 0;
        }

        return self::$productCommissionCache[$basalamProductId] = $body['commission_data']['commission_percent'];
    }

    public static function resetProductCommissionCache(): void
    {
        self::$productCommissionCache = [];
    }

    public static function fetchCategoryCommission($categoryIds)
    {
        $queryParams = [];

        if (isset($categoryIds[0]) && is_numeric($categoryIds[0])) {
            $queryParams[] = "product.category.level1=" . intval($categoryIds[0]);
        }
        if (isset($categoryIds[1]) && is_numeric($categoryIds[1])) {
            $queryParams[] = "product.category.level2=" . intval($categoryIds[1]);
        }
        if (isset($categoryIds[2]) && is_numeric($categoryIds[2])) {
            $queryParams[] = "product.category.level3=" . intval($categoryIds[2]);
        }

        if (empty($queryParams)) return false;

        $apiservice = syncBasalamContainer()->get(ApiServiceManager::class);
        $url = Endpoints::COMMISSION . '?' . implode("&", $queryParams);

        try {
            $result = $apiservice->get($url);
        } catch (\Throwable $e) {
            return 0;
        }

        if (!is_array($result) || !array_key_exists('body', $result)) return 0;

        $decodedBody = $result['body'];
        if (is_string($decodedBody)) $decodedBody = json_decode($decodedBody, true);

        if (!is_array($decodedBody)
            || !isset($decodedBody['commission_data'])
            || !is_array($decodedBody['commission_data'])
            || !array_key_exists('commission_percent', $decodedBody['commission_data'])
            || !is_numeric($decodedBody['commission_data']['commission_percent'])
        ) {
            return 0;
        }

        $commissionPercent = $decodedBody['commission_data']['commission_percent'];

        if ($commissionPercent) return $commissionPercent;
        return 0;
    }
}
