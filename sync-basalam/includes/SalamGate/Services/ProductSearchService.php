<?php

namespace SyncBasalam\SalamGate\Services;

use SyncBasalam\SalamGate\Utils\Units;

defined('ABSPATH') || exit;

/** Read-only local catalogue search; no SalamGate or OAuth request is involved. */
final class ProductSearchService
{
    public const LIMIT = 8;
    public const MAX_QUERY_LENGTH = 80;

    public function search(string $term): array
    {
        if (!function_exists('wc_get_product') || !function_exists('get_woocommerce_currency')) {
            return [];
        }
        $term = trim($term);
        if ($term === '') return [];
        $term = mb_substr($term, 0, self::MAX_QUERY_LENGTH, 'UTF-8');

        // The title query is escaped by WP_Query. One Persian character is enough.
        // Published variations are searched as distinct choices, never a variable
        // parent's minimum/range price that may describe a different variation.
        $query = new \WP_Query([
            'post_type' => ['product', 'product_variation'],
            'post_status' => 'publish',
            's' => $term,
            'search_columns' => ['post_title'],
            'posts_per_page' => self::LIMIT * 3,
            'fields' => 'ids',
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
            'orderby' => 'relevance',
            'order' => 'DESC',
        ]);

        $results = [];
        $currency = (string) get_woocommerce_currency();
        foreach ($query->posts as $id) {
            $product = wc_get_product((int) $id);
            if (!$product || $product->get_status() !== 'publish'
                || !$product->is_type(['simple', 'variation']) || $product->is_virtual()
            ) continue;

            $parent = null;
            if ($product->is_type('variation')) {
                $parent = wc_get_product($product->get_parent_id());
                if (!$parent || $parent->get_status() !== 'publish') continue;
            }
            $title = html_entity_decode(wp_strip_all_tags((string) $product->get_name()), ENT_QUOTES, 'UTF-8');
            if (trim($title) === '') continue;

            $rawWeight = $product->get_weight();
            $weight = is_numeric($rawWeight) && (float) $rawWeight > 0
                ? Units::weightToGrams($rawWeight) : null;
            if ($weight !== null && $weight <= 0) $weight = null;

            $rawPrice = $product->get_price();
            $rial = is_numeric($rawPrice) && (float) $rawPrice >= 0
                ? Units::orderAmountToRial($rawPrice, $currency) : null;
            $price = $rial === null ? null : Units::toToman($rial);
            $imageId = (int) $product->get_image_id();
            if ($imageId <= 0 && $parent) $imageId = (int) $parent->get_image_id();
            $image = $imageId > 0 ? wp_get_attachment_image_url($imageId, 'woocommerce_thumbnail') : false;

            $results[] = [
                'id' => (int) $product->get_id(),
                'title' => $title,
                'image_url' => $image ? esc_url_raw($image) : '',
                'weight_grams' => $weight,
                'price_toman' => $price,
                'price_label' => $price !== null ? Units::formatToman($price) . ' تومان'
                    : ($rawPrice === '' ? 'قیمت ثبت نشده' : 'قیمت فروشگاه قابل تبدیل به تومان نیست'),
                'weight_label' => $weight !== null ? Units::formatToman($weight) . ' گرم' : 'وزن ثبت نشده',
            ];
            if (count($results) >= self::LIMIT) break;
        }

        return $results;
    }
}
