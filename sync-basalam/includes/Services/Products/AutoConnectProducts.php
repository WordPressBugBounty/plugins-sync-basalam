<?php

namespace SyncBasalam\Services\Products;

use SyncBasalam\Logger\Logger;
use SyncBasalam\Jobs\Exceptions\RetryableException;
use SyncBasalam\Jobs\Exceptions\NonRetryableException;

defined('ABSPATH') || exit;

class AutoConnectProducts
{
    public function checkSameProduct($title = null, $cursor = null)
    {
        try {
            $getProductData = new FetchProductsData();
            if ($title) {
                $title = mb_substr($title, 0, 120);
                $syncBasalamProducts = $getProductData->getProductData($title);
            } else {
                $syncBasalamProducts = $getProductData->getProductData(null, $cursor);
            }

            if (!is_array($syncBasalamProducts) || !isset($syncBasalamProducts['data'])) {
                return $title ? [] : [
                    'error' => true,
                    'message' => 'خطا در دریافت اطلاعات محصولات',
                    'status_code' => 400,
                    'has_more' => false,
                    'next_cursor' => null,
                ];
            }

            if ($title) {
                return $syncBasalamProducts['data'];
            }

            global $wpdb;

            $matchedProducts = [];

            foreach ($syncBasalamProducts['data'] as $syncBasalamProduct) {
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

            $hasMore = !empty($syncBasalamProducts['has_more']);
            $nextCursor = $syncBasalamProducts['next_cursor'] ?? null;

            if ($hasMore && !empty($nextCursor)) {
                return [
                    'success'     => true,
                    'message'     => 'محصولات با موفقیت به صف اتصال افزوده شدند.',
                    'status_code' => 200,
                    'has_more'    => true,
                    'next_cursor' => $nextCursor,
                ];
            } else {
                if (!empty($matchedProducts)) {
                    return [
                        'success'     => true,
                        'message'     => 'اتصال محصولات کامل شد.',
                        'status_code' => 200,
                        'has_more'    => false,
                        'next_cursor' => null,
                    ];
                } else {
                    return [
                        'error'       => true,
                        'message'     => 'محصول مشابهی یافت نشد.',
                        'status_code' => 404,
                        'has_more'    => false,
                        'next_cursor' => null,
                    ];
                }
            }
        } catch (RetryableException $e) {
            Logger::error("خطا در اتصال خودکار محصولات: " . $e->getMessage(), [
                'operation' => 'اتصال خودکار محصولات',
            ]);
            throw $e;
        } catch (NonRetryableException $e) {
            Logger::error("خطا در اتصال خودکار محصولات: " . $e->getMessage(), [
                'operation' => 'اتصال خودکار محصولات',
            ]);
            throw $e;
        } catch (\Exception $e) {
            Logger::error("خطا در اتصال خودکار محصولات: " . $e->getMessage(), [
                'operation' => 'اتصال خودکار محصولات',
            ]);
            throw $e;
        }
    }
}
