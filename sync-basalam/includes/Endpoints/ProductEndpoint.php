<?php

namespace SyncBasalam\Endpoints;

use SyncBasalam\Utilities\ProductMetaKey;

defined('ABSPATH') || exit;

class ProductEndpoint
{
    public static function registerRoutes(): void
    {
        register_rest_route(
            'sync-basalam',
            '/v1/products',
            [
                'methods' => 'GET',
                'callback' => [__CLASS__, 'getProducts'],
                'permission_callback' => [InformationEndpoint::class, 'checkPermissions'],
                'args' => [
                    'page' => [
                        'validate_callback' => static function () {
                            return new \WP_Error('sync_basalam_page_unsupported', 'Use cursor instead of page.');
                        },
                    ],
                    'cursor' => [
                        'type' => 'string',
                        'default' => '',
                        'maxLength' => 256,
                        'validate_callback' => [__CLASS__, 'validateCursor'],
                    ],
                    'per_page' => [
                        'type' => 'integer',
                        'default' => 50,
                        'minimum' => 1,
                        'maximum' => 100,
                        'validate_callback' => 'rest_validate_request_arg',
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );
    }

    public static function getProducts($request)
    {
        if (!function_exists('wc_get_products')) {
            return new \WP_Error(
                'sync_basalam_woocommerce_unavailable',
                'WooCommerce is unavailable.',
                ['status' => 503]
            );
        }

        global $wpdb;
        $perPage = (int) ($request->get_param('per_page') ?? 50);
        $cursor = (string) ($request->get_param('cursor') ?? '');
        if ($cursor === '') {
            $after = 0;
            // The primary-key maximum is cheap and bounds concurrent insertions.
            $until = (int) $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts}");
            if ($wpdb->last_error) {
                return self::databaseError();
            }
        } else {
            $position = self::decodeCursor($cursor);
            if ($position === null) {
                return new \WP_Error('rest_invalid_param', 'Invalid cursor. Restart without a cursor.', ['status' => 400]);
            }
            [$after, $until] = $position;
        }

        // Force the ordered ID range: the type/status/date index can otherwise
        // make MySQL sort the entire catalog for this multi-status query.
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} FORCE INDEX (PRIMARY)
             WHERE ID > %d AND ID <= %d AND post_type = 'product'
             AND post_status IN ('publish', 'draft', 'pending', 'private', 'future')
             ORDER BY ID ASC LIMIT %d",
            $after, $until, $perPage + 1
        ));
        if ($wpdb->last_error) {
            return self::databaseError();
        }
        $hasMore = count($ids) > $perPage;
        $ids = array_slice(array_map('intval', $ids), 0, $perPage);
        // Advance over selected IDs even if a product is deleted while hydrating.
        $nextCursor = $hasMore ? self::encodeCursor(end($ids), $until) : null;
        _prime_post_caches($ids, true, true);
        $metaKey = ProductMetaKey::basalamProductId();
        $products = [];

        foreach ($ids as $id) {
            $product = wc_get_product($id);
            if (!$product || !in_array($product->get_status(), ['publish', 'draft', 'pending', 'private', 'future'], true)) {
                continue;
            }
            $basalamId = (string) $product->get_meta($metaKey, true);
            $hasBasalamId = ctype_digit($basalamId) && (int) $basalamId > 0;

            $products[] = [
                'woocommerce_product_id' => $product->get_id(),
                'woocommerce_product_url' => $product->get_permalink(),
                'basalam_product_url' => $hasBasalamId ? 'https://basalam.com/p/' . $basalamId : null,
            ];
        }

        return rest_ensure_response([
            'data' => $products,
            'pagination' => [
                'per_page' => $perPage,
                'has_more' => $hasMore,
                'next_cursor' => $nextCursor,
            ],
        ]);
    }

    public static function validateCursor($value, $request, $param)
    {
        $valid = rest_validate_request_arg($value, $request, $param);
        if (is_wp_error($valid)) {
            return $valid;
        }
        return $value === '' || self::decodeCursor($value) !== null;
    }

    private static function encodeCursor(int $after, int $until): string
    {
        $payload = '1:' . $after . ':' . $until;
        return rtrim(strtr(base64_encode($payload . ':' . self::signature($payload)), '+/', '-_'), '=');
    }

    private static function decodeCursor(string $cursor): ?array
    {
        if (strlen($cursor) > 256 || !preg_match('/^[A-Za-z0-9_-]+$/D', $cursor)) {
            return null;
        }
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($decoded === false || !preg_match('/^1:([1-9][0-9]*):([1-9][0-9]*):([a-f0-9]{64})$/D', $decoded, $parts)) {
            return null;
        }
        $after = filter_var($parts[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $until = filter_var($parts[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($after === false || $until === false || $after >= $until) {
            return null;
        }
        $payload = '1:' . $after . ':' . $until;
        return hash_equals(self::signature($payload), $parts[3]) ? [$after, $until] : null;
    }

    private static function signature(string $payload): string
    {
        $scope = get_current_blog_id() . ':' . ProductMetaKey::basalamProductId();
        return hash_hmac('sha256', 'products:' . $scope . ':' . $payload, wp_salt('auth'));
    }

    private static function databaseError(): \WP_Error
    {
        return new \WP_Error('sync_basalam_products_unavailable', 'Products are temporarily unavailable.', ['status' => 503]);
    }
}
