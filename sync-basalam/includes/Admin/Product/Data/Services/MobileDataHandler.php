<?php

namespace SyncBasalam\Admin\Product\Data\Services;

defined('ABSPATH') || exit;

class MobileDataHandler
{
    public static function getMobileAttributesAsArray($product): array
    {
        $mobileData = self::getMobileData($product);

        return [
            [
                'attribute_id' => 1707,
                'value' => $mobileData['storage'] ?? '',
            ],
            [
                'attribute_id' => 1708,
                'value' => $mobileData['cpu_type'] ?? '',
            ],
            [
                'attribute_id' => 1709,
                'value' => $mobileData['ram'] ?? '',
            ],
            [
                'attribute_id' => 1710,
                'value' => $mobileData['screen_size'] ?? '',
            ],
            [
                'attribute_id' => 1711,
                'value' => $mobileData['rear_camera'] ?? '',
            ],
            [
                'attribute_id' => 1712,
                'value' => $mobileData['battery_capacity'] ?? '',
            ],
        ];
    }

    public static function getMobileData($product): array
    {
        return [
            'storage' => get_post_meta($product->get_id(), '_sync_basalam_mobile_storage', true),
            'cpu_type' => get_post_meta($product->get_id(), '_sync_basalam_cpu_type', true),
            'ram' => get_post_meta($product->get_id(), '_sync_basalam_mobile_ram', true),
            'screen_size' => get_post_meta($product->get_id(), '_sync_basalam_screen_size', true),
            'rear_camera' => get_post_meta($product->get_id(), '_sync_basalam_rear_camera', true),
            'battery_capacity' => get_post_meta($product->get_id(), '_sync_basalam_battery_capacity', true),
        ];
    }

    public static function saveMobileData($product): void
    {
        // No longer needed - individual fields are saved directly by MobileFields
    }

    public static function deleteMobileData($product): void
    {
        // No longer needed - individual fields are deleted directly by MobileFields
    }

    public static function hasMobileData($product): bool
    {
        $fields = [
            '_sync_basalam_mobile_storage',
            '_sync_basalam_cpu_type',
            '_sync_basalam_mobile_ram',
            '_sync_basalam_screen_size',
            '_sync_basalam_rear_camera',
            '_sync_basalam_battery_capacity',
        ];

        foreach ($fields as $field) {
            if (!empty(get_post_meta($product->get_id(), $field, true))) {
                return true;
            }
        }

        return false;
    }

    public static function getMobileFieldValue($product, string $field): string
    {
        $fieldMap = [
            'storage' => '_sync_basalam_mobile_storage',
            'cpu_type' => '_sync_basalam_cpu_type',
            'ram' => '_sync_basalam_mobile_ram',
            'screen_size' => '_sync_basalam_screen_size',
            'rear_camera' => '_sync_basalam_rear_camera',
            'battery_capacity' => '_sync_basalam_battery_capacity',
        ];

        $metaKey = $fieldMap[$field] ?? null;
        return $metaKey ? get_post_meta($product->get_id(), $metaKey, true) : '';
    }

    public static function getAllMobileFields(): array
    {
        return [
            'storage' => 'حافظه داخلی',
            'cpu_type' => 'نوع پردازنده - CPU',
            'ram' => 'حافظه RAM',
            'screen_size' => 'سایز صفحه نمایش',
            'rear_camera' => 'دوربین پشت',
            'battery_capacity' => 'ظرفیت باتری',
        ];
    }
}