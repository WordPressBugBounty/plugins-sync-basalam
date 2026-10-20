<?php

namespace SyncBasalam\Services\Products;

defined('ABSPATH') || exit;

final class ProductPriceStockRetry
{
    /**
     * Retry a rejected update once, dropping everything except price, stock and
     * identifiers. Keep the effective payload so callers cannot restore a SKU
     * or description after the fallback has run.
     */
    public static function send(callable $send, array &$payload, ?callable $buildFallback = null): array
    {
        try {
            $response = $send($payload);
        } catch (\Exception $exception) {
            if (!self::isValidationError($exception) || !self::prepareFallback($payload, $buildFallback)) {
                throw $exception;
            }

            return $send($payload);
        }

        if (self::isValidationError($response) && self::prepareFallback($payload, $buildFallback)) {
            return $send($payload);
        }

        return $response;
    }

    public static function onlyPriceStock(array $payload): array
    {
        // Batch endpoints need the product and variation ids; product PATCH
        // callers already remove variation ids before reaching this helper.
        if (isset($payload['data']) && is_array($payload['data'])) {
            return ['data' => array_map([self::class, 'onlyPriceStock'], $payload['data'])];
        }

        $restricted = array_intersect_key($payload, array_flip(['id', 'type', 'primary_price', 'stock', 'variants']));
        $restricted = array_filter($restricted, fn($value) => $value !== null);

        if (isset($restricted['variants']) && is_array($restricted['variants'])) {
            $restricted['variants'] = array_map(function (array $variant): array {
                return array_filter(
                    array_intersect_key($variant, array_flip(['id', 'properties', 'primary_price', 'stock'])),
                    fn($value) => $value !== null
                );
            }, $restricted['variants']);

            if (empty($restricted['variants'])) unset($restricted['variants']);
        }

        return $restricted;
    }

    private static function isValidationError($source): bool
    {
        $status = $source instanceof \Throwable ? $source->getCode() : ($source['status_code'] ?? 0);

        return in_array((int) $status, [400, 422], true);
    }

    private static function prepareFallback(array &$payload, ?callable $buildFallback): bool
    {
        $fallback = self::onlyPriceStock($buildFallback ? $buildFallback() : $payload);
        if ($fallback === $payload || !self::hasUpdates($fallback)) return false;

        $payload = $fallback;

        return true;
    }

    private static function hasUpdates(array $payload): bool
    {
        if (array_key_exists('primary_price', $payload) || array_key_exists('stock', $payload)) return true;

        foreach (['data', 'variants'] as $key) {
            foreach ($payload[$key] ?? [] as $item) {
                if (self::hasUpdates($item)) return true;
            }
        }

        return false;
    }
}
