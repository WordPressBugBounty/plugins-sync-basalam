<?php

namespace SyncBasalam\Services\Products;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Logger\Logger;
use SyncBasalam\JobManager;

defined('ABSPATH') || exit;

class AutoConnectProducts
{
    public function checkSameProduct($title = null, $page = 1)
    {
        try {
            $getProductData = new FetchProductsData();
            if ($title) {
                $title = mb_substr($title, 0, 120);
                $syncBasalamProducts = $getProductData->getProductData($title);
            } else $syncBasalamProducts = $getProductData->getProductData(null, $page);

            if ($title) return $syncBasalamProducts['products'];

            global $wpdb;

            $matchedProducts = [];

            foreach ($syncBasalamProducts['products'] as $syncBasalamProduct) {
                $normalizedTitle = trim($syncBasalamProduct['title']);

                if (mb_strlen($normalizedTitle) >= 120) {
                    $likeTitle = $normalizedTitle . '%';
                } else {
                    $likeTitle = $normalizedTitle;
                }

                $productId = $wpdb->get_var(
                    $wpdb->prepare("
                    SELECT p.ID
                    FROM {$wpdb->posts} p
                    LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'sync_basalam_product_id'
                    WHERE p.post_type = 'product'
                    AND p.post_status = 'publish'
                    AND pm.post_id IS NULL
                    AND LOWER(p.post_title) LIKE LOWER(%s)
                    LIMIT 1
                ", $likeTitle)
                );

                if ($productId) {
                    $connectProductService = new ConnectSingleProductService();
                    $result = $connectProductService->connectProductById($productId, $syncBasalamProduct['id']);

                    if ($result) {
                        Logger::info($syncBasalamProduct['title'] . ' به محصول مشابه خود در باسلام متصل شد', [
                            'product_id' => $productId,
                            'عملیات'     => "اتصال اتوماتیک محصولات ووکامرس و باسلام",
                        ]);
                    }

                    $matchedProducts[] = $syncBasalamProduct;
                }
            }

            if (!empty($syncBasalamProducts['total_page']) && is_numeric($syncBasalamProducts['total_page'])) $totalPage = $syncBasalamProducts['total_page'];
            else $totalPage = 0;

            if ($page < $totalPage) {
                return [
                    'success'     => true,
                    'message'     => 'محصولات با موفقیت به صف اتصال افزوده شدند.',
                    'status_code' => 200,
                    'has_more'    => true,
                    'total_page'  => $totalPage,
                ];
            } else {
                if (!empty($matchedProducts)) {
                    return [
                        'success'     => true,
                        'message'     => 'اتصال محصولات کامل شد.',
                        'status_code' => 200,
                        'has_more'    => false,
                    ];
                } else {
                    return [
                        'error'       => true,
                        'message'     => 'محصول مشابهی یافت نشد.',
                        'status_code' => 404,
                        'has_more'    => false,
                    ];
                }
            }
        } catch (\Exception $e) {
            return [
                'error'       => true,
                'message'     => $e->getMessage(),
                'status_code' => 400,
            ];
        }
    }
}
