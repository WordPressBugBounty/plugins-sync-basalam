<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Admin_UI
{
    public static function render_icon($icon)
    {
        return sprintf(
            '<span class="dashicons %s" style="font-size: 17px; vertical-align: middle;"></span>',
            esc_attr($icon)
        );
    }

    public static function render_sync_basalam_delete_access()
    {
        echo '<input type="hidden" name="sync_basalam_settings[' . esc_attr(sync_basalam_Admin_Settings::TOKEN) . ']" value="">' .
            '<input type="hidden" name="sync_basalam_settings[' . esc_attr(sync_basalam_Admin_Settings::WEBHOOK_ID) . ']" value="">' .
            '<input type="hidden" name="sync_basalam_settings[' . esc_attr(sync_basalam_Admin_Settings::REFRESH_TOKEN) . ']" value="">';
    }

    public static function render_sync_basalam_delete_webhook()
    {
        return '<input type="hidden" name="sync_basalam_settings[' . esc_attr(sync_basalam_Admin_Settings::WEBHOOK_ID) . ']" value="' . esc_attr(null) . '">';
    }

    // Render the input field for sync product
    public static function sync_status_product()
    {
        $value = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::SYNC_STATUS_PRODUCT) == true ? false : true;
        echo '<input type="hidden" name="sync_basalam_settings[' . esc_attr(sync_basalam_Admin_Settings::SYNC_STATUS_PRODUCT) . ']" value="' . esc_attr($value) . '">';
    }

    public static function sync_status_order()
    {
        $value = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::SYNC_STATUS_ORDER) == true ? false : true;
        echo '<input type="hidden" name="sync_basalam_settings[' . esc_attr(sync_basalam_Admin_Settings::SYNC_STATUS_ORDER) . ']" value="' . esc_attr($value) . '">';
    }
    public static function render_auto_confirm_order_button()
    {
        $value = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::AUTO_CONFIRM_ORDER) == true ? false : true;
        echo '<input type="hidden" name="sync_basalam_settings[' . esc_attr(sync_basalam_Admin_Settings::AUTO_CONFIRM_ORDER) . ']" value="' . esc_attr($value) . '">';
    }
    // Render the input field for basalam webhook id
    public static function render_sync_basalam_webhook_id()
    {
        $current_value = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::WEBHOOK_ID);
        echo '<input type="text" name="sync_basalam_settings[' . esc_attr(sync_basalam_Admin_Settings::WEBHOOK_ID) . ']" value="' . esc_attr($current_value) . '" class="basalam-input" required>';
    }

    // Render the input field for basalam default weight
    public static function render_default_weight()
    {
        $current_value = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::DEFAULT_WEIGHT);
        echo '<input type="number" name="sync_basalam_settings[' . esc_attr(sync_basalam_Admin_Settings::DEFAULT_WEIGHT) . ']" min="50" value="' . esc_attr($current_value) . '" class="basalam-input basalam-p" required>';
    }

    public static function render_package_weight()
    {
        $current_value = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::DEFAULT_PACKAGE_WEIGHT);
        echo '<input type="number" name="sync_basalam_settings[' . esc_attr(sync_basalam_Admin_Settings::DEFAULT_PACKAGE_WEIGHT) . ']" min="10" value="' . esc_attr($current_value) . '" class="basalam-input basalam-p" required>';
    }
    // Render the input field for basalam default preparation
    public static function render_default_preparation()
    {
        $current_value = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::DEFAULT_PREPARATION);
        echo '<input type="number" name="sync_basalam_settings[' . esc_attr(sync_basalam_Admin_Settings::DEFAULT_PREPARATION) . ']" min="0" value="' . esc_attr($current_value) . '" class="basalam-input basalam-p" required>';
    }

    public static function render_default_stock_quantity()
    {
        $current_value = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::DEFAULT_STOCK_QUANTITY);
        echo '<input type="number" name="sync_basalam_settings[' . esc_attr(sync_basalam_Admin_Settings::DEFAULT_STOCK_QUANTITY) . ']" min="0" value="' . esc_attr($current_value) . '" class="basalam-input basalam-p" required>';
    }

    // Render the input field for basalam default percentage
    public static function render_default_percentage()
    {
        $current_value = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::INCREASE_PRICE_VALUE);
        $is_checked = ($current_value == -1) ? 'checked' : '';
        $input_disabled = ($current_value == -1) ? 'disabled' : '';
        $input_value = ($current_value == -1) ? '' : number_format($current_value);

        echo '<div class="basalam-input-container" style="display: flex; align-items: center; gap: 4px;justify-content: center;">';
        echo '<input type="text" id="percentage-input" name="sync_basalam_settings[' . esc_attr(sync_basalam_Admin_Settings::INCREASE_PRICE_VALUE) . ']" min="0" value="' . esc_attr($input_value) . '" class="basalam-input basalam-p percentage-input" ' . esc_attr($input_disabled) . ' required>';
        echo '<span class="percentage-unit basalam-p" style="min-width: 0px;font-size: 13px;">' . ($current_value <= 100 ? 'درصد' : 'تومان') . '</span>';
        echo '</div>';

        echo '<div style="margin-top: 8px; display: flex; align-items: center; gap: 4px;">';
        echo '<input type="checkbox" id="toggle-percentage" name="toggle_percentage" class="Basalam-checkbox" ' . esc_attr($is_checked) . '>';
        echo '<label style="font-size:10px;" for="toggle-percentage">کارمزد دسته‌بندی</label>';
        echo '</div>';

        echo '<input type="hidden" id="final-value" name="sync_basalam_settings[' . esc_attr(sync_basalam_Admin_Settings::INCREASE_PRICE_VALUE) . ']" value="' . esc_attr($current_value) . '">';
    }

    // Render the input field for basalam default round
    public static function render_default_round()
    {
        $current_value = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::ROUND_PRICE);
        echo '<select style="text-align: center; font-size:12px;" name="sync_basalam_settings[' . esc_attr(sync_basalam_Admin_Settings::ROUND_PRICE) . ']" class="basalam-select"> ' .
            '<option value="none"' . selected($current_value, "none", false) . '>رند نکردن</option>' .
            '<option value="up"' . selected($current_value, "up", false) . '>بالا</option>' .
            '<option value="down"' . selected($current_value, "down", false) . '>پایین</option>' .
            '</select>';
    }
    public static function render_default_shipping_method(array $methods)
    {
        $current_value = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::ORDER_SHIPPING_METHOD);
        echo '<select style="text-align: center; font-size:12px;" name="sync_basalam_settings[' . esc_attr(sync_basalam_Admin_Settings::ORDER_SHIPPING_METHOD) . ']" class="basalam-select">';
        echo '<option value="false"' . selected($current_value, 'false', false) . '>بدون روش ارسال</option>';

        foreach ($methods as $method) {
            $id = esc_attr($method['method_id']);
            $title = esc_html($method['method_title']);
            $selected = selected($current_value, $id, false);

            echo '<option value="' . esc_attr($id) . '"' . esc_attr($selected) . '>' . esc_html($title) . '</option>';
        }

        echo '</select>';
    }

    public static function render_sync_product()
    {
        $current_value = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::SYNC_PRODUCT_FIELDS);
        echo '<select style="text-align: center;font-size:12px;" name="sync_basalam_settings[' . esc_attr(sync_basalam_Admin_Settings::SYNC_PRODUCT_FIELDS) . ']" class="basalam-select" onchange="BasalamToggleCustomFields(this.value)" id="basalam-sync-type">' .
            '<option value="all"' . selected($current_value, "all", false) . '>همه اطلاعات</option>' .
            '<option value="price_stock"' . selected($current_value, "price_stock", false) . '>فقط قیمت و موجودی</option>' .
            '<option value="custom"' . selected($current_value, "custom", false) . '>سفارشی</option>' .
            '</select>';
    }

    public static function render_wholesale_products()
    {
        $current_value = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::ALL_PRODUCTS_WHOLESALE);
        echo '<select style="text-align: center; font-size:12px;" name="sync_basalam_settings[' . esc_attr(sync_basalam_Admin_Settings::ALL_PRODUCTS_WHOLESALE) . ']" class="basalam-select">' .
            '<option value="none"' . selected($current_value, "none", false) . '>هیچ یا برخی محصولات عمده</option>' .
            '<option value="all"' . selected($current_value, "all", false) . '>همه محصولات عمده</option>' .
            '</select>';
    }

    public static function render_attr_add_to_desc()
    {
        $current_value = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::ADD_ATTR_TO_DESC_PRODUCT);
        echo '<select style="text-align: center; font-size:12px;" name="sync_basalam_settings[' . esc_attr(sync_basalam_Admin_Settings::ADD_ATTR_TO_DESC_PRODUCT) . ']" class="basalam-select">' .
            '<option value="no"' . selected($current_value, 'no', false) . '>اضافه نشود</option>' .
            '<option value="yes"' . selected($current_value, 'yes', false) . '>اضافه شود</option>' .
            '</select>';
    }

    public static function render_order_status()
    {
        $current_value = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::ORDER_STATUES_TYPE);
        echo '<select style="text-align: center; font-size:12px;" name="sync_basalam_settings[' . esc_attr(sync_basalam_Admin_Settings::ORDER_STATUES_TYPE) . ']" class="basalam-select">' .
            '<option value="woosalam_statuses"' . selected($current_value, 'woosalam_statuses', false) . '>وضعیت های ووسلام</option>' .
            '<option value="woocommerce_statuses"' . selected($current_value, 'woocommerce_statuses', false) . '>وضعیت های ووکامرس</option>' .
            '</select>';
    }

    public static function render_short_attr_add_to_desc()
    {
        $current_value = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::ADD_SHORT_DESC_TO_DESC_PRODUCT);
        echo '<select style="text-align: center; font-size:12px;" name="sync_basalam_settings[' . esc_attr(sync_basalam_Admin_Settings::ADD_SHORT_DESC_TO_DESC_PRODUCT) . ']" class="basalam-select">' .
            '<option value="no"' . selected($current_value, 'no', false) . '>اضافه نشود</option>' .
            '<option value="yes"' . selected($current_value, 'yes', false) . '>اضافه شود</option>' .
            '</select>';
    }

    public static function render_product_price()
    {
        $current_value = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::PRODUCT_PRICE_FIELD);
        echo '<select style="text-align: center; font-size:12px;" name="sync_basalam_settings[' . esc_attr(sync_basalam_Admin_Settings::PRODUCT_PRICE_FIELD) . ']" class="basalam-select">' .
            '<option value="original_price"' . selected($current_value, 'original_price', false) . '>قیمت اصلی</option>' .
            '<option value="sale_price"' . selected($current_value, 'sale_price', false) . '>قیمت حراجی</option>' .
            '</select>';
    }

    public static function render_prefix_product_title()
    {
        $current_value = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::PRODUCT_PREFIX_TITLE);
        echo '<input type="text" name="sync_basalam_settings[' . esc_attr(sync_basalam_Admin_Settings::PRODUCT_PREFIX_TITLE) . ']" value="' . esc_attr($current_value) . '" class="basalam-input basalam-p" style="max-width:80% !important;font-size:12px;">';
    }

    public static function render_suffix_product_title()
    {
        $current_value = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::PRODUCT_SUFFIX_TITLE);
        echo '<input type="text" name="sync_basalam_settings[' . esc_attr(sync_basalam_Admin_Settings::PRODUCT_SUFFIX_TITLE) . ']" value="' . esc_attr($current_value) . '" class="basalam-input basalam-p"style="max-width:80% !important;font-size:12px;">';
    }

    public static function render_map_options_product()
    {
?>

        <form id="Basalam-map-option-form" method="post" style="display: flex; align-items: center; gap: 10px;">
            <?php wp_nonce_field('basalam_add_map_option_nonce', 'basalam_add_map_option_nonce'); ?>
            <label for="woo-option-name" class="basalam-p__small">نام ویژگی در ووکامرس</label>
            <input type="text" class="basalam-input" style="width: auto;" id="woo-option-name" name="woo-option-name" required>
            <label for="Basalam-option-name" class="basalam-p__small">نام ویژگی در باسلام</label>
            <input type="text" class="basalam-input" style="width: auto;" id="Basalam-option-name" name="Basalam-option-name" required>
            <button type="submit" class="basalam-primary-button basalam-p" style="width: auto;">ذخیره</button>
        </form>

    <?php
    }

    public static function render_sync_product_fields()
    {
        echo '<div>';
        echo wp_kses(self::render_single_checkbox(sync_basalam_Admin_Settings::SYNC_PRODUCT_FIELD_NAME, 'نام'), self::allowed_html());
        echo wp_kses(self::render_single_checkbox(sync_basalam_Admin_Settings::SYNC_PRODUCT_FIELD_PHOTOS, 'عکس'), self::allowed_html());
        echo wp_kses(self::render_single_checkbox(sync_basalam_Admin_Settings::SYNC_PRODUCT_FIELD_PRICE, 'قیمت'), self::allowed_html());
        echo wp_kses(self::render_single_checkbox(sync_basalam_Admin_Settings::SYNC_PRODUCT_FIELD_STOCK, 'موجودی'), self::allowed_html());
        echo wp_kses(self::render_single_checkbox(sync_basalam_Admin_Settings::SYNC_PRODUCT_FIELD_WEIGHT, 'وزن'), self::allowed_html());
        echo wp_kses(self::render_single_checkbox(sync_basalam_Admin_Settings::SYNC_PRODUCT_FIELD_DESCRIPTION, 'توضیحات'), self::allowed_html());
        echo wp_kses(self::render_single_checkbox(sync_basalam_Admin_Settings::SYNC_PRODUCT_FIELD_ATTR, 'ویژگی ها'), self::allowed_html());
        echo '</div>';
    }

    private static function render_single_checkbox($field_key, $label)
    {
        return '<label class="basalam-p sync-checkbox-label" style="width: 32%; text-align:right;  margin-bottom: 10px;">' .
            '<input type="hidden" name="sync_basalam_settings[' . esc_attr($field_key) . ']" value="">' .
            '<input type="checkbox" name="sync_basalam_settings[' . esc_attr($field_key) . ']" value="1" ' .
            checked(sync_basalam_Admin_Settings::get_settings($field_key), true, false) . '>' .
            esc_html($label) .
            '</label>';
    }

    public static function allowed_html()
    {
        return [
            'form' => [
                'action' => [],
                'method' => [],
                'id' => [],
                'class' => [],
            ],
            'input' => [
                'type' => [],
                'name' => [],
                'value' => [],
                'class' => [],
                'required' => [],
                'style' => [],
                'id' => [],
                'checked' => [],
            ],
            'button' => [
                'type' => [],
                'class' => [],
                'style' => [],
            ],
            'div' => [
                'class' => [],
                'id' => [],
                'style' => [],
            ],
            'p' => [
                'class' => [],
                'style' => [],
            ],
            'strong' => [],
            'label' => [
                'class' => [],
                'style' => [],
            ],
            'svg' => [
                'width' => [],
                'height' => [],
                'viewBox' => [],
                'xmlns' => [],
                'fill' => [],
            ],
            'path' => [
                'fill' => [],
                'fill-rule' => [],
                'clip-rule' => [],
                'd' => [],
            ],
            'span' => [
                'class' => [],
            ],
            'code' => [
                'class' => [],
            ],
            'a' => [
                'href' => [],
                'target' => [],
                'class' => [],
            ],
            'ul' => [
                'class' => [],
                'style' => [],
            ],
            'li' => [
                'class' => [],
                'style' => [],
            ],
            'img' => [
                'src' => [],
                'alt' => [],
                'class' => [],
            ],
        ];
    }


    // Render the input field for basalam developer mode
    public static function render_developer_mode()
    {
        $current_value = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::DEVELOPER_MODE);
        echo '<select name="sync_basalam_settings[' . esc_attr(sync_basalam_Admin_Settings::DEVELOPER_MODE) . ']" class="basalam-select" onchange="this.form.submit()">' .
            '<option value="false"' . selected($current_value, "false", false) . '>غیرفعال</option>' .
            '<option value="true"' . selected($current_value, "true", false) . '>فعال</option>' .
            '</select>';
    }

    public static function render_faq_by_category($categories)
    {
        foreach ($categories as $category) {
            $is_active = $category === 'عمومی' ? ' active' : '';
            $nonce = wp_create_nonce('sync_basalam_faq_nonce');
            echo '<div class="basalam-faq-section' . esc_attr($is_active) . '" data-category="' . esc_attr($category) . '" data-nonce="' . esc_attr($nonce) . '">';

            $faqs = sync_basalam_Admin_Help::get_faq_by_category($category);
            $faqs_html = array_map(function ($faq) {
                return '
                    <div class="basalam-faq-item">
                        <div class="basalam-faq-question">
                            <h3>' . esc_html($faq['question']) . '</h3>
                            <span class="basalam-faq-toggle">+</span>
                        </div>
                        <div class="basalam-faq-answer">
                            <p>' . esc_html($faq['answer']) . '</p>
                        </div>
                    </div>
                ';
            }, $faqs);

            $escaped_faqs_html = implode('', $faqs_html);
            echo wp_kses_post($escaped_faqs_html);

            echo '</div>';
        }
    }

    public static function render_sync_product_status_ok()
    {
        echo '<span class="dashicons dashicons-yes-alt" style="color: #00b67a;" title="محصول با باسلام سینک شده است."></span>';
    }

    public static function render_sync_product_status_pending()
    {
        echo '<span class="dashicons dashicons-update" style="color: #f7a700;" title="در حال سینک با باسلام"></span>';
    }

    public static function render_sync_product_status_fail()
    {
        echo '<span class="dashicons dashicons-no-alt" style="color: #dc3232;" title="محصول در باسلام ثبت نشده است یا فرایند سینک موفق نبود"></span>';
    }

    public static function render_btn($text, $link = null, $name = null, $product_id = null, $nonce_name = null)
    {
        if ($name && $link == null) {
            if ($nonce_name) {
                $nonce = wp_create_nonce($nonce_name);
            }
            echo '<button class="basalam-button basalam-action-button  basalam-p basalam-a" 
            data-url="' . esc_url(admin_url('admin-post.php')) . '" 
            data-action="' . esc_attr($name) . '" 
            data-product-id="' . esc_attr($product_id) . '" 
            data-_wpnonce="' . esc_attr($nonce) . '">' . esc_html($text) . '</button>';
        } else {
            echo '<a href="' . esc_url($link) . '" target="_blank" class="basalam-button basalam-btn basalam-p basalam-a">' . esc_html($text) . '</a>';
        }
    }
    public static function render_check_sync_basalam_orders_button()
    {
    ?>
        <div class="alignleft actions custom">
            <button type="button" class="basalam-button basalam-p basalam_add_unsync_orders" style="height:32px;"
                title="تمامی سفارشات جدیدی باسلامی که همگام سازی نشده اند ، اضافه میشوند."
                data-nonce="<?php echo esc_attr(wp_create_nonce('add_unsync_orders_from_basalam_nonce')); ?>">
                بررسی سفارشات باسلام
            </button>
        </div>
<?php
    }
    public static function render_category_options_mapping($data)
    {
        $delete_nonce = wp_create_nonce('basalam_delete_mapped_option_nonce');

        echo '<div class="options_mapping_section">';
        echo '<p class="basalam-p">لیست ویژگی ها : </p>';
        echo "<table class='basalam-table basalam-p'>";
        echo '<thead><tr><th>نام ویژگی در ووکامرس</th><th>نام ویژگی در باسلام</th><th>عملیات</th></tr></thead>';
        echo '<tbody>';

        if (!empty($data)) {
            foreach ($data as $item) {
                echo '<tr data-woo="' . esc_attr($item['woo_name']) . '" data-Basalam="' . esc_attr($item['sync_basalam_name']) . '">';
                echo '<td>' . esc_html($item['woo_name']) . '</td>';
                echo '<td>' . esc_html($item['sync_basalam_name']) . '</td>';
                echo '<td>
                    <button 
                        class="Basalam-delete-option basalam-primary-button" 
                        data-_wpnonce="' . esc_attr($delete_nonce) . '" 
                        onclick="return confirm(\'آیا مطمئن هستید؟\')" 
                        style="width:auto">
                        حذف
                    </button>
                </td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    public static function render_unsync_basalam_products_table($unsync_products)
    {
        echo "<div style='display: flex;flex-direction: column;justify-content: center;align-items: center;'>";
        if (empty($unsync_products)) {
            return null;
        }

        echo "<h3 style='margin-bottom: 15px !important;font-family: PelakFA !important;font-weight: bold;'>📦 محصولات سینک‌نشده باسلام:</h3>";
        echo "<table class='basalam-p' style='border-collapse: collapse;max-width: 1005px;width: 100%;'>";

        echo "<thead style='background-color: #f4f4f4; color: #333;'>
                <tr>
                    <th style='padding: 6px 8px;  border: 1px solid #ddd;'>#</th>
                    <th style='padding: 6px 8px;  border: 1px solid #ddd;'>تصویر</th>
                    <th style='padding: 6px 8px; border: 1px solid #ddd;'>عنوان</th>
                    <th style='padding: 6px 8px; border: 1px solid #ddd;'>قیمت (تومان)</th>
                    <th style='padding: 6px 8px;  border: 1px solid #ddd;'>آیدی باسلام</th>
                    <th style='padding: 6px 20px;  border: 1px solid #ddd;'>محصول در باسلام</th>
                </tr>
              </thead>";

        echo "<tbody>";

        foreach ($unsync_products as $index => $product) {
            echo "<tr style='background-color: #fff; border-bottom: 1px solid #ddd;'>
            <td style='padding: 6px 8px; '>" . esc_html($index + 1) . "</td>
            <td style='padding: 6px 8px; '><img src='" . esc_url($product['photo']) . "' alt='Product Image' style='border-radius: 4px; width: 60px; height: 60px; object-fit: cover;'></td>
            <td style='padding: 6px 8px;'>" . esc_html($product['title']) . "</td>
            <td style='padding: 6px 8px;'>" . esc_html(number_format($product['price'])) . "</td>
            <td style='padding: 6px 8px; '>" . esc_html($product['id']) . "</td>
            <td style='padding: 6px 8px; '>
            <button class='basalam-button basalam-p' style='width: unset;margin:auto !important;height: 35px;'>
                <a class='basalam-a' style='font-size:10px' href='https://basalam.com/p/" . esc_attr($product['id']) . "' target='_blank'>مشاهده محصول</a>
            </button>

            </td>
          </tr>";
        }

        echo "</tbody></table>";
        echo "</div>";
    }
}
