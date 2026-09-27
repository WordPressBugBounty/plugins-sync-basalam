<?php

namespace SyncBasalam\Services\Products;

use SyncBasalam\Logger\Logger;
use SyncBasalam\Jobs\Exceptions\RetryableException;
use SyncBasalam\Jobs\Exceptions\NonRetryableException;
use SyncBasalam\Utilities\ProductMetaKey;

defined('ABSPATH') || exit;

class AutoConnectProducts
{
    private const FALLBACK_BATCH_SIZE = 10;

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
            $productIdMetaKey = ProductMetaKey::basalamProductId();

            foreach ($syncBasalamProducts['data'] as $syncBasalamProduct) {
                $normalizedTitle = trim($syncBasalamProduct['title']);

                // A WooCommerce title often adds a model/code suffix that is
                // not present in Basalam (for example: "... کد 50"). Use a
                // prefix only for sufficiently specific titles; short,
                // generic titles must retain exact-match behaviour.
                $likeTitle = mb_strlen($normalizedTitle, 'UTF-8') >= 12
                    ? $wpdb->esc_like($normalizedTitle) . '%'
                    : $normalizedTitle;

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct lookup on core posts/postmeta tables; no cache key available for this title match.
                $productId = $wpdb->get_var(
                    $wpdb->prepare("
                    SELECT p.ID
                    FROM {$wpdb->posts} p
                    LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
                    WHERE p.post_type = 'product'
                    AND p.post_status = 'publish'
                    AND pm.post_id IS NULL
                    AND LOWER(p.post_title) LIKE LOWER(%s)
                    LIMIT 1
                ", $productIdMetaKey, $likeTitle)
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
                        'completed'   => true,
                    ];
                } else {
                    return [
                        'error'       => true,
                        'message'     => 'محصول مشابهی یافت نشد.',
                        'status_code' => 404,
                        'has_more'    => false,
                        'next_cursor' => null,
                        'completed'   => true,
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

    /**
     * Search Basalam for the remaining unconnected WooCommerce products.
     *
     * The first pass is intentionally vendor-list based because it is cheap.
     * This second pass reuses the exact endpoint used by the single-product
     * screen, which catches harmless title differences that the first pass
     * cannot see.
     *
     * @return array{has_more: bool, next_product_id: int, connected: int}
     */
    public function connectUnconnectedProductsBySearch(int $lastProductId = 0, int $batchSize = self::FALLBACK_BATCH_SIZE): array
    {
        global $wpdb;

        $productIdMetaKey = ProductMetaKey::basalamProductId();
        $batchSize = max(1, min(25, $batchSize));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This is a cursor query over core product tables; every run must see newly connected products.
        $products = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_title
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
                 WHERE p.post_type = 'product'
                   AND p.post_status = 'publish'
                   AND pm.post_id IS NULL
                   AND p.ID > %d
                 ORDER BY p.ID ASC
                 LIMIT %d",
                $productIdMetaKey,
                $lastProductId,
                $batchSize
            )
        );

        if (empty($products)) {
            return [
                'has_more'        => false,
                'next_product_id' => $lastProductId,
                'connected'       => 0,
            ];
        }

        $connected = 0;
        $lastSeenProductId = $lastProductId;
        $connectProductService = new ConnectSingleProductService();

        foreach ($products as $product) {
            $lastSeenProductId = (int) $product->ID;
            $searchResults = $this->checkSameProduct((string) $product->post_title);
            $match = ProductTitleMatcher::bestMatch((string) $product->post_title, $searchResults);

            if (!$match) continue;

            if ($connectProductService->connectProductById($product->ID, $match['id'])) {
                $connected++;
                Logger::info($match['title'] . ' به محصول مشابه خود در باسلام متصل شد', [
                    'product_id' => (int) $product->ID,
                    'basalam_product_id' => $match['id'],
                    'عملیات' => 'اتصال جست‌وجویی محصولات ووکامرس و باسلام',
                ]);
            }
        }

        return [
            'has_more'        => count($products) === $batchSize,
            'next_product_id' => $lastSeenProductId,
            'connected'       => $connected,
        ];
    }
}
