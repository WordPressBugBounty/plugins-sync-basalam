<?php

namespace SyncBasalam\Admin\Order;

use SyncBasalam\Services\Orders\OrderManager;

defined('ABSPATH') || exit;

class BasalamItemMeta
{
    public static function hideFromFormattedMeta(array $formattedMeta): array
    {
        // Also exclude legacy visible fields without rewriting historical orders.
        $keys = [
            OrderManager::BASALAM_ITEM_NAME_META_KEY,
            OrderManager::BASALAM_ITEM_LINK_META_KEY,
            OrderManager::LEGACY_BASALAM_ITEM_NAME_META_KEY,
            OrderManager::LEGACY_BASALAM_ITEM_LINK_META_KEY,
        ];

        foreach ($formattedMeta as $id => $meta) {
            if (in_array($meta->key, $keys, true)) {
                unset($formattedMeta[$id]);
            }
        }

        return $formattedMeta;
    }

    public static function render($itemId, $item, $product): void
    {
        if (!$item instanceof \WC_Order_Item_Product) {
            return;
        }

        $name = (string) $item->get_meta(OrderManager::BASALAM_ITEM_NAME_META_KEY, true);
        if ($name === '') {
            $name = (string) $item->get_meta(OrderManager::LEGACY_BASALAM_ITEM_NAME_META_KEY, true);
        }

        $url = (string) $item->get_meta(OrderManager::BASALAM_ITEM_LINK_META_KEY, true);
        if ($url === '') {
            $legacyLink = (string) $item->get_meta(OrderManager::LEGACY_BASALAM_ITEM_LINK_META_KEY, true);
            if (preg_match('/href\s*=\s*["\']([^"\']+)["\']/i', $legacyLink, $matches)) {
                $url = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
            }
        }
        $url = esc_url($url);

        if ($name === '' && $url === '') {
            return;
        }

        echo '<div class="sync-basalam-item-details"><table cellspacing="0" class="display_meta">';
        if ($name !== '') {
            echo '<tr><th>نام محصول در باسلام:</th><td>' . esc_html($name) . '</td></tr>';
        }
        if ($url !== '') {
            echo '<tr><th>لینک محصول در باسلام:</th><td><a href="' . $url . '" target="_blank" rel="noopener noreferrer">مشاهده محصول در باسلام</a></td></tr>';
        }
        echo '</table></div>';
    }
}
