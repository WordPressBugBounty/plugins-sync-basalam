<?php

namespace SyncBasalam\Admin\Product\Data\Services;

defined('ABSPATH') || exit;

class GoldDataHandler
{
    public static function getGoldAttributesAsArray($product): array
    {
        $goldData = self::getGoldData($product);

        return [
            [
                'attribute_id' => 1785,
                'value' => $goldData['purity'] ?? '',
            ],
            [
                'attribute_id' => 1786,
                'value' => $goldData['weight'] ?? '',
            ],
        ];
    }

    public static function getGoldData($product): array
    {
        return [
            'purity' => get_post_meta($product->get_id(), '_sync_basalam_gold_purity', true),
            'weight' => get_post_meta($product->get_id(), '_sync_basalam_gold_weight', true),
        ];
    }

    public static function hasGoldData($product): bool
    {
        $fields = [
            '_sync_basalam_gold_purity',
            '_sync_basalam_gold_weight',
        ];

        foreach ($fields as $field) {
            if (!empty(get_post_meta($product->get_id(), $field, true))) {
                return true;
            }
        }

        return false;
    }

    public static function getAllGoldFields(): array
    {
        return [
            'purity' => 'عیار طلا (مبنای 1000)',
            'weight' => 'وزن طلا (میلی گرم)',
        ];
    }
}
