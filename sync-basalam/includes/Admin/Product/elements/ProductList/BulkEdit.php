<?php

namespace SyncBasalam\Admin\Product\elements\ProductList;

use SyncBasalam\Admin\Product\elements\SingleProduct\PriceIncreaseField;
use SyncBasalam\Admin\Product\Utils\ProductUnits;

defined('ABSPATH') || exit;

class BulkEdit
{
    public const TYPE_META_KEY = '_sync_basalam_is_product_type_checkbox';
    public const UNIT_META_KEY = '_sync_basalam_product_unit';
    public const QUANTITY_META_KEY = '_sync_basalam_product_value';
    public const WHOLESALE_META_KEY = '_sync_basalam_is_wholesale';

    public function renderFields(string $columnName, string $postType): void
    {
        if ($postType !== 'product' || $columnName !== 'name') {
            return;
        }

        $units = ProductUnits::all();

        require __DIR__ . '/views/BulkEditFields.php';
    }

    public function save(int $postId, \WP_Post $post): void
    {
        if ($post->post_type !== 'product') {
            return;
        }

        if (
            !isset($_REQUEST['_inline_edit'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_inline_edit'])), 'inlineeditnonce')
        ) {
            return;
        }

        if (!current_user_can('edit_post', $postId)) {
            return;
        }

        if (!isset($_REQUEST['sync_basalam_bulk_product_type_action']) && !isset($_REQUEST['sync_basalam_bulk_increase_action'])) {
            return;
        }

        $this->saveProductType($postId);
        $this->saveWholesale($postId);
        $this->saveIncrease($postId);
    }

    private function saveProductType(int $postId): void
    {
        $action = isset($_REQUEST['sync_basalam_bulk_product_type_action'])
            ? sanitize_text_field(wp_unslash($_REQUEST['sync_basalam_bulk_product_type_action']))
            : 'keep';

        if ($action === 'keep') {
            return;
        }

        if ($action === 'yes') {
            update_post_meta($postId, self::TYPE_META_KEY, 'yes');

            $unit = isset($_REQUEST['sync_basalam_bulk_product_unit'])
                ? sanitize_text_field(wp_unslash($_REQUEST['sync_basalam_bulk_product_unit']))
                : '6304';
            $quantity = isset($_REQUEST['sync_basalam_bulk_product_value'])
                ? sanitize_text_field(wp_unslash($_REQUEST['sync_basalam_bulk_product_value']))
                : '1';

            $unitValue = is_numeric($unit) ? (string) intval($unit) : '6304';
            $quantityValue = (is_numeric($quantity) && intval($quantity) > 0) ? (string) intval($quantity) : '1';

            update_post_meta($postId, self::UNIT_META_KEY, $unitValue);
            update_post_meta($postId, self::QUANTITY_META_KEY, $quantityValue);

            return;
        }

        update_post_meta($postId, self::TYPE_META_KEY, 'no');
        delete_post_meta($postId, self::UNIT_META_KEY);
        delete_post_meta($postId, self::QUANTITY_META_KEY);
    }

    private function saveWholesale(int $postId): void
    {
        $action = isset($_REQUEST['sync_basalam_bulk_wholesale_action'])
            ? sanitize_text_field(wp_unslash($_REQUEST['sync_basalam_bulk_wholesale_action']))
            : 'keep';

        if ($action === 'keep') {
            return;
        }

        $wholesaleValue = $action === 'yes' ? 'yes' : 'no';
        update_post_meta($postId, self::WHOLESALE_META_KEY, $wholesaleValue);
    }

    private function saveIncrease(int $postId): void
    {
        $action = isset($_REQUEST['sync_basalam_bulk_increase_action'])
            ? sanitize_text_field(wp_unslash($_REQUEST['sync_basalam_bulk_increase_action']))
            : 'keep';

        if ($action === 'keep') {
            return;
        }

        if ($action === 'clear') {
            delete_post_meta($postId, PriceIncreaseField::META_KEY);

            return;
        }

        $value = isset($_REQUEST['sync_basalam_bulk_increase_value'])
            ? sanitize_text_field(wp_unslash($_REQUEST['sync_basalam_bulk_increase_value']))
            : '';

        if ($value === '' || !is_numeric($value)) {
            return;
        }

        $increaseValue = (string) intval($value);
        update_post_meta($postId, PriceIncreaseField::META_KEY, $increaseValue);
    }
}
