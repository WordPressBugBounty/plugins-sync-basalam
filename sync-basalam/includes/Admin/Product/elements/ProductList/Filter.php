<?php

namespace SyncBasalam\Admin\Product\elements\ProductList;

defined('ABSPATH') || exit;

class Filter
{
    public function renderFilterDropdown()
    {
        $selected = sanitize_text_field(isset($_GET['sync_basalam_not_added'])) ? sanitize_text_field(wp_unslash($_GET['sync_basalam_not_added'])) : '';
        wp_nonce_field('sync_basalam_filter_action', '_sync_basalam_filter_nonce'); ?>
        <select name="sync_basalam_not_added" class="basalam-font-pelak-12">
            <option value="">فیلتر ووسلام</option>
            <option value="0" <?php selected($selected, '0'); ?>>محصولات اضافه شده به باسلام</option>
            <option value="1" <?php selected($selected, '1'); ?>>محصولات اضافه نشده به باسلام</option>
        </select>
<?php
    }

    public function applyFilterToQuery($query)
    {
        global $pagenow;

        if (
            is_admin()
            && $query->is_main_query()
            && $pagenow === 'edit.php'
            && isset($_GET['_sync_basalam_filter_nonce'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_sync_basalam_filter_nonce'])), 'sync_basalam_filter_action')
        ) {
            $postType = isset($_GET['post_type']) ? sanitize_text_field(wp_unslash($_GET['post_type'])) : '';
            $filterValue = isset($_GET['sync_basalam_not_added']) ? sanitize_text_field(wp_unslash($_GET['sync_basalam_not_added'])) : '';

            if ($postType === 'product') {
                if ($filterValue === '1') {
                    $query->set('meta_query', [
                        [
                            'key'     => 'sync_basalam_product_id',
                            'compare' => 'NOT EXISTS',
                        ],
                    ]);
                } elseif ($filterValue === '0') {
                    $query->set('meta_query', [
                        [
                            'key'     => 'sync_basalam_product_id',
                            'compare' => 'EXISTS',
                        ],
                    ]);
                }
            }
        }
    }
}
