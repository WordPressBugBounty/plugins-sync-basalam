<?php

namespace SyncBasalam\Admin\Components;

defined('ABSPATH') || exit;

class ProductListComponents
{
    public static function renderSyncProductStatusSynced()
    {
        echo '<span class="dashicons dashicons-yes-alt basalam-status-success" title="محصول با باسلام سینک شده است."></span>';
    }

    public static function renderSyncProductStatusPending()
    {
        echo '<span class="dashicons dashicons-update basalam-status-warning" title="در حال سینک با باسلام"></span>';
    }

    public static function renderSyncProductStatusUnsync()
    {
        echo '<span class="dashicons dashicons-no-alt basalam-status-error" title="محصول در باسلام ثبت نشده است یا فرایند سینک موفق نبود"></span>';
    }

    public static function renderUnsyncBasalamProductsTable($unsync_products)
    {
        echo "<div class='basalam-flex-center-vertical basalam-flex-col'>";
        if (empty($unsync_products)) return null;

        echo "<h3 class='basalam-margin-bottom-15 basalam-font-bold'>📦 محصولات سینک‌نشده باسلام:</h3>";
        echo "<table class='basalam-p basalam-table-unsync'>";

        echo "<thead class='basalam-table-header'>
                <tr>
                    <th class='basalam-table-padding'>#</th>
                    <th class='basalam-table-padding'>تصویر</th>
                    <th class='basalam-table-padding'>عنوان</th>
                    <th class='basalam-table-padding'>قیمت (تومان)</th>
                    <th class='basalam-table-padding'>آیدی باسلام</th>
                    <th class='basalam-table-padding basalam-table-cell-center' style='width: 14%;'>محصول در باسلام</th>
                </tr>
              </thead>";

        echo "<tbody>";

        foreach ($unsync_products as $index => $product) {
            echo "<tr class='basalam-table-row'>
            <td class='basalam-table-padding'>" . esc_html($index + 1) . "</td>
            <td class='basalam-table-padding'><img src='" . esc_url($product['photo']['md']) . "' alt='Product Image' class='basalam-product-img-table'></td>
            <td class='basalam-table-padding'>" . esc_html($product['title']) . "</td>
            <td class='basalam-table-padding'>" . esc_html(number_format($product['price'])) . "</td>
            <td class='basalam-table-padding'>" . esc_html($product['id']) . "</td>
            <td class='basalam-table-padding basalam-table-cell-center'>
            <button class='basalam-button basalam-p basalam-button-table basalam-height-35 basalam-margin-auto'>
                <a class='basalam-a' href='https://basalam.com/p/" . esc_attr($product['id']) . "' target='_blank'>مشاهده محصول</a>
            </button>

            </td>
          </tr>";
        }

        echo "</tbody></table>";
        echo "</div>";
    }
}
