<?php

namespace SyncBasalam\Services\Products;

defined('ABSPATH') || exit;

/**
 * Helpers for handling Basalam's duplicate-SKU validation error.
 */
final class ProductSkuRetry
{
    /**
     * Return true when the payload contains a non-empty SKU at any level.
     */
    public static function hasSku(array $payload): bool
    {
        return self::containsSkuKey($payload);
    }

    /**
     * Remove product and variation SKU fields while preserving the rest of the payload.
     */
    public static function withoutSkus(array $payload): array
    {
        self::removeSkuKeys($payload);

        return $payload;
    }

    /**
     * Detect a duplicate-SKU error from either an exception or an API response.
     *
     * Basalam has returned this validation in more than one shape, so both the
     * field names/codes and human-readable messages are considered here.
     *
     * @param mixed $source Throwable, API response, response body, or message.
     */
    public static function isDuplicateSkuError($source): bool
    {
        if (is_array($source) && self::isSuccessfulResponse($source)) return false;
        if (is_string($source)) {
            $decoded = json_decode($source, true);
            if (is_array($decoded) && self::isSuccessfulResponse($decoded)) return false;
        }

        $parts = [];
        self::collectText($source, $parts);

        if (is_object($source) && $source instanceof \Throwable) {
            $parts[] = $source->getMessage();

            if (method_exists($source, 'getResponseData')) {
                $responseData = $source->getResponseData();
                if (self::containsSkuField($responseData)) return true;

                self::collectText($responseData, $parts);
            }
        }

        if (is_array($source) && self::containsSkuField($source)) return true;

        if (empty($parts)) return false;

        $text = self::normalize(implode(' ', $parts));

        return self::containsSkuReference($text) && self::containsDuplicateMarker($text);
    }

    private static function containsSkuField($value): bool
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) && self::containsSkuField($decoded);
        }

        if (!is_array($value)) return false;

        foreach ($value as $key => $item) {
            if (in_array(strtolower((string) $key), ['fields', 'field', 'parameter', 'path'], true) && self::fieldsContainSku($item)) {
                return true;
            }

            if (self::containsSkuField($item)) return true;
        }

        return false;
    }

    private static function fieldsContainSku($fields): bool
    {
        if (is_string($fields)) return self::containsSkuReference(self::normalize($fields));
        if (!is_array($fields)) return false;

        foreach ($fields as $field) {
            if (is_string($field) && self::containsSkuReference(self::normalize($field))) return true;
            if (is_array($field) && self::fieldsContainSku($field)) return true;
        }

        return false;
    }

    private static function containsSkuKey($value): bool
    {
        if (!is_array($value)) return false;

        foreach ($value as $key => $item) {
            if (strcasecmp((string) $key, 'sku') === 0 && self::hasValue($item)) return true;
            if (is_array($item) && self::containsSkuKey($item)) return true;
        }

        return false;
    }

    private static function removeSkuKeys(array &$value): void
    {
        foreach ($value as $key => &$item) {
            if (strcasecmp((string) $key, 'sku') === 0) {
                unset($value[$key]);
                continue;
            }

            if (is_array($item)) self::removeSkuKeys($item);
        }

        unset($item);
    }

    private static function hasValue($value): bool
    {
        if ($value === null) return false;
        if (is_string($value)) return trim($value) !== '';

        return $value !== '';
    }

    private static function isSuccessfulResponse(array $response): bool
    {
        if (!array_key_exists('status_code', $response)) return false;

        $statusCode = (int) $response['status_code'];

        return $statusCode >= 200 && $statusCode < 300;
    }

    private static function collectText($value, array &$parts, string $key = ''): void
    {
        if ($key !== '') $parts[] = $key;

        if (is_array($value)) {
            foreach ($value as $childKey => $childValue) {
                self::collectText($childValue, $parts, (string) $childKey);
            }

            return;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                self::collectText($decoded, $parts);
                return;
            }

            $parts[] = $value;
            return;
        }

        if (is_scalar($value)) $parts[] = (string) $value;

        if (is_object($value) && method_exists($value, 'get_error_message')) {
            $parts[] = (string) $value->get_error_message();
        }
    }

    private static function normalize(string $text): string
    {
        $text = function_exists('mb_strtolower')
            ? mb_strtolower($text, 'UTF-8')
            : strtolower($text);

        $text = str_replace(["\xC2\xA0", "\xE2\x80\x8C"], ' ', $text);

        return preg_replace('/[_\-.]+/u', ' ', $text) ?: $text;
    }

    private static function containsSkuReference(string $text): bool
    {
        if (preg_match('/(^|[^a-z0-9])sku([^a-z0-9]|$)/i', $text)) return true;
        if (strpos($text, 'stock keeping unit') !== false) return true;

        return strpos($text, 'اس کی یو') !== false
            || strpos($text, 'اسکیو') !== false
            || strpos($text, 'کد محصول') !== false
            || strpos($text, 'کد کالا') !== false
            || strpos($text, 'شناسه محصول') !== false
            || strpos($text, 'شناسه کالا') !== false;
    }

    private static function containsDuplicateMarker(string $text): bool
    {
        foreach ([
            'duplicate',
            'duplicated',
            'already exists',
            'already exist',
            'already taken',
            'already used',
            'already registered',
            'sku exists',
            'sku taken',
            'sku used',
            'sku registered',
            'has been taken',
            'has been used',
            'is already registered',
            'taken',
            'in use',
            'must be unique',
            'unique',
            'unique constraint',
            'unique violation',
            'conflict',
            'تکراری',
            'تکرار شده',
            'قبلا وجود',
            'قبلاً وجود',
            'قبلا استفاده',
            'قبلاً استفاده',
            'قبلا ثبت',
            'قبلاً ثبت',
            'از قبل وجود',
            'استفاده شده',
            'ثبت شده',
            'گرفته شده',
            'وجود دارد',
            'یکتا',
        ] as $marker) {
            if (strpos($text, $marker) !== false) return true;
        }

        return false;
    }
}
