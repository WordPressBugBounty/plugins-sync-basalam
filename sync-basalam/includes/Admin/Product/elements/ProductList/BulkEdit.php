<?php

namespace SyncBasalam\Admin\Product\elements\ProductList;

use SyncBasalam\Admin\Product\elements\SingleProduct\PriceChangeField;
use SyncBasalam\Admin\Product\Utils\ProductUnits;
use SyncBasalam\Utilities\PriceAdjustment;

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

    /**
     * Runs on admin_action_sync_basalam_bulk_edit, which passes no arguments,
     * so the selected products are read from the bulk edit request itself.
     */
    public function save(): void
    {
        if (!isset($_REQUEST['post']) || !is_array($_REQUEST['post'])) {
            return;
        }

        if (
            !isset($_REQUEST['_wpnonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])), 'bulk-posts')
        ) {
            wp_die('درخواست نامعتبر است. لطفاً دوباره تلاش کنید.');
        }

        if (!isset($_REQUEST['sync_basalam_bulk_product_type_action']) && !isset($_REQUEST['sync_basalam_bulk_price_change_action'])) {
            return;
        }

        $postIds = array_map('intval', wp_unslash($_REQUEST['post'])); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; each ID is cast with intval().

        foreach ($postIds as $postId) {
            if ($postId <= 0 || get_post_type($postId) !== 'product') {
                continue;
            }

            if (!current_user_can('edit_post', $postId)) {
                continue;
            }

            $this->saveProductType($postId);
            $this->saveWholesale($postId);
            $this->savePriceChange($postId);
        }
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

    private function savePriceChange(int $postId): void
    {
        $action = isset($_REQUEST['sync_basalam_bulk_price_change_action'])
            ? sanitize_text_field(wp_unslash($_REQUEST['sync_basalam_bulk_price_change_action']))
            : 'keep';

        if ($action === 'keep') {
            return;
        }

        if ($action === 'clear') {
            delete_post_meta($postId, PriceChangeField::META_KEY);

            return;
        }

        $value = isset($_REQUEST['sync_basalam_bulk_price_change_value'])
            ? sanitize_text_field(wp_unslash($_REQUEST['sync_basalam_bulk_price_change_value']))
            : '';

        $priceChangeValue = PriceAdjustment::normalize($value);

        if ($priceChangeValue === null) {
            return;
        }

        update_post_meta($postId, PriceChangeField::META_KEY, $priceChangeValue);
    }
}
