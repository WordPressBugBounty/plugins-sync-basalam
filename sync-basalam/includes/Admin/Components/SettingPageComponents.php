<?php

namespace SyncBasalam\Admin\Components;

use SyncBasalam\Admin\Settings\SettingsConfig;

defined('ABSPATH') || exit;

class SettingPageComponents
{
    public static function renderDeleteAccess()
    {
        echo '<input type="hidden" name="sync_basalam_settings[' . esc_attr(SettingsConfig::TOKEN) . ']" value="">'
            . '<input type="hidden" name="sync_basalam_settings[' . esc_attr(SettingsConfig::REFRESH_TOKEN) . ']" value="">';
    }

    public static function syncStatusProduct()
    {
        $value = syncBasalamSettings()->getSettings(SettingsConfig::SYNC_STATUS_PRODUCT) == true ? false : true;
        echo '<input type="hidden" name="sync_basalam_settings[' . esc_attr(SettingsConfig::SYNC_STATUS_PRODUCT) . ']" value="' . esc_attr($value) . '">';
    }

    public static function syncStatusOrder()
    {
        $value = syncBasalamSettings()->getSettings(SettingsConfig::SYNC_STATUS_ORDER) == true ? false : true;
        echo '<input type="hidden" name="sync_basalam_settings[' . esc_attr(SettingsConfig::SYNC_STATUS_ORDER) . ']" value="' . esc_attr($value) . '">';
    }

    public static function renderAutoConfirmOrderButton()
    {
        $value = syncBasalamSettings()->getSettings(SettingsConfig::AUTO_CONFIRM_ORDER) == true ? false : true;
        echo '<input type="hidden" name="sync_basalam_settings[' . esc_attr(SettingsConfig::AUTO_CONFIRM_ORDER) . ']" value="' . esc_attr($value) . '">';
    }

    public static function renderDefaultWeight()
    {
        $current_value = syncBasalamSettings()->getSettings(SettingsConfig::DEFAULT_WEIGHT);
        echo '<input type="number" name="sync_basalam_settings[' . esc_attr(SettingsConfig::DEFAULT_WEIGHT) . ']" min="50" value="' . esc_attr($current_value) . '" class="basalam-input basalam-p" required>';
    }

    public static function renderPackageWeight()
    {
        $current_value = syncBasalamSettings()->getSettings(SettingsConfig::DEFAULT_PACKAGE_WEIGHT);
        echo '<input type="number" name="sync_basalam_settings[' . esc_attr(SettingsConfig::DEFAULT_PACKAGE_WEIGHT) . ']" min="10" value="' . esc_attr($current_value) . '" class="basalam-input basalam-p" required>';
    }

    public static function renderDefaultPreparation()
    {
        $current_value = syncBasalamSettings()->getSettings(SettingsConfig::DEFAULT_PREPARATION);
        echo '<input type="number" name="sync_basalam_settings[' . esc_attr(SettingsConfig::DEFAULT_PREPARATION) . ']" min="0" value="' . esc_attr($current_value) . '" class="basalam-input basalam-p" required>';
    }

    public static function renderDefaultStockQuantity()
    {
        $current_value = syncBasalamSettings()->getSettings(SettingsConfig::DEFAULT_STOCK_QUANTITY);
        echo '<input type="number" name="sync_basalam_settings[' . esc_attr(SettingsConfig::DEFAULT_STOCK_QUANTITY) . ']" min="0" value="' . esc_attr($current_value) . '" class="basalam-input basalam-p" required>';
    }

    public static function renderSafeStock()
    {
        $current_value = syncBasalamSettings()->getSettings(SettingsConfig::SAFE_STOCK);
        echo '<input type="number" name="sync_basalam_settings[' . esc_attr(SettingsConfig::SAFE_STOCK) . ']" min="0" value="' . esc_attr($current_value) . '" class="basalam-input basalam-p" required>';
    }

    public static function renderDefaultPercentage()
    {
        $current_value = syncBasalamSettings()->getSettings(SettingsConfig::INCREASE_PRICE_VALUE);
        $is_checked = ($current_value == -1) ? 'checked' : '';
        $input_disabled = ($current_value == -1) ? 'disabled' : '';
        $input_value = ($current_value == -1) ? '' : number_format($current_value);

        echo '<div class="basalam-input-container">';
        echo '<input type="text" id="percentage-input" name="sync_basalam_settings[' . esc_attr(SettingsConfig::INCREASE_PRICE_VALUE) . ']" min="0" value="' . esc_attr($input_value) . '" class="basalam-input basalam-p percentage-input" ' . esc_attr($input_disabled) . ' required>';
        echo '<span class="percentage-unit basalam-p basalam-min-width-0 basalam-font-13">' . ($current_value <= 100 ? 'درصد' : 'تومان') . '</span>';
        echo '</div>';

        echo '<div class="basalam-flex-end-gap-4 basalam-margin-top-8">';
        echo '<input type="checkbox" id="toggle-percentage" name="toggle_percentage" class="Basalam-checkbox" ' . esc_attr($is_checked) . '>';
        echo '<label class="basalam-font-10" for="toggle-percentage">کارمزد دسته‌بندی</label>';
        echo '</div>';

        echo '<input type="hidden" id="final-value" name="sync_basalam_settings[' . esc_attr(SettingsConfig::INCREASE_PRICE_VALUE) . ']" value="' . esc_attr($current_value) . '">';
    }

    public static function renderDefaultRound()
    {
        $current_value = syncBasalamSettings()->getSettings(SettingsConfig::ROUND_PRICE);
        echo '<select class="basalam-select basalam-select-center" name="sync_basalam_settings[' . esc_attr(SettingsConfig::ROUND_PRICE) . ']">'
            . '<option value="none"' . selected($current_value, "none", false) . '>رند نکردن</option>'
            . '<option value="up"' . selected($current_value, "up", false) . '>بالا</option>'
            . '<option value="down"' . selected($current_value, "down", false) . '>پایین</option>'
            . '</select>';
    }

    public static function renderSyncProduct()
    {
        $current_value = syncBasalamSettings()->getSettings(SettingsConfig::SYNC_PRODUCT_FIELDS);
        echo '<select class="basalam-select basalam-select-center" name="sync_basalam_settings[' . esc_attr(SettingsConfig::SYNC_PRODUCT_FIELDS) . ']" onchange="BasalamToggleCustomFields(this.value)" id="basalam-sync-type">'
            . '<option value="all"' . selected($current_value, "all", false) . '>همه اطلاعات</option>'
            . '<option value="price_stock"' . selected($current_value, "price_stock", false) . '>فقط قیمت و موجودی</option>'
            . '<option value="custom"' . selected($current_value, "custom", false) . '>سفارشی</option>'
            . '</select>';
    }

    public static function renderWholesaleProducts()
    {
        $current_value = syncBasalamSettings()->getSettings(SettingsConfig::ALL_PRODUCTS_WHOLESALE);
        echo '<select class="basalam-select basalam-select-center" name="sync_basalam_settings[' . esc_attr(SettingsConfig::ALL_PRODUCTS_WHOLESALE) . ']">'
            . '<option value="none"' . selected($current_value, "none", false) . '>هیچ یا برخی محصولات عمده</option>'
            . '<option value="all"' . selected($current_value, "all", false) . '>همه محصولات عمده</option>'
            . '</select>';
    }

    public static function renderAttrAddToDesc()
    {
        $current_value = syncBasalamSettings()->getSettings(SettingsConfig::ADD_ATTR_TO_DESC_PRODUCT);
        echo '<select class="basalam-select basalam-select-center" name="sync_basalam_settings[' . esc_attr(SettingsConfig::ADD_ATTR_TO_DESC_PRODUCT) . ']">'
            . '<option value="no"' . selected($current_value, 'no', false) . '>اضافه نشود</option>'
            . '<option value="yes"' . selected($current_value, 'yes', false) . '>اضافه شود</option>'
            . '</select>';
    }

    public static function renderOrderStatus()
    {
        $current_value = syncBasalamSettings()->getSettings(SettingsConfig::ORDER_STATUES_TYPE);
        echo '<select class="basalam-select basalam-select-center" name="sync_basalam_settings[' . esc_attr(SettingsConfig::ORDER_STATUES_TYPE) . ']">'
            . '<option value="woosalam_statuses"' . selected($current_value, 'woosalam_statuses', false) . '>وضعیت های ووسلام</option>'
            . '<option value="woocommerce_statuses"' . selected($current_value, 'woocommerce_statuses', false) . '>وضعیت های ووکامرس</option>'
            . '</select>';
    }

    public static function renderShortAttrAddToDesc()
    {
        $current_value = syncBasalamSettings()->getSettings(SettingsConfig::ADD_SHORT_DESC_TO_DESC_PRODUCT);
        echo '<select class="basalam-select basalam-select-center" name="sync_basalam_settings[' . esc_attr(SettingsConfig::ADD_SHORT_DESC_TO_DESC_PRODUCT) . ']">'
            . '<option value="no"' . selected($current_value, 'no', false) . '>اضافه نشود</option>'
            . '<option value="yes"' . selected($current_value, 'yes', false) . '>اضافه شود</option>'
            . '</select>';
    }

    public static function renderProductPrice()
    {
        $current_value = syncBasalamSettings()->getSettings(SettingsConfig::PRODUCT_PRICE_FIELD);
        echo '<select class="basalam-select basalam-select-center" name="sync_basalam_settings[' . esc_attr(SettingsConfig::PRODUCT_PRICE_FIELD) . ']">'
            . '<option value="original_price"' . selected($current_value, 'original_price', false) . '>قیمت اصلی</option>'
            . '<option value="sale_price"' . selected($current_value, 'sale_price', false) . '>قیمت حراجی (تک قیمت)</option>'
            . '<option value="sale_strikethrough_price"' . selected($current_value, 'sale_strikethrough_price', false) . '>قیمت حراجی (خط خورده)</option>'
            . '</select>';
    }

    public static function renderProductDiscountDuration()
    {
        $current_value = syncBasalamSettings()->getSettings(SettingsConfig::DISCOUNT_DURATION);

        echo '<input type="number" id="percentage-input" name="sync_basalam_settings[' . esc_attr(SettingsConfig::DISCOUNT_DURATION) . ']" min="1" max="90" value="' . esc_attr($current_value) . '" class="basalam-input basalam-p percentage-input" required>';
    }

    public static function renderDiscountReductionPercent()
    {
        $current_value = syncBasalamSettings()->getSettings(SettingsConfig::DISCOUNT_REDUCTION_PERCENT);

        echo '<input type="number" name="sync_basalam_settings[' . esc_attr(SettingsConfig::DISCOUNT_REDUCTION_PERCENT) . ']" min="0" max="100" value="' . esc_attr($current_value) . '" class="basalam-input basalam-p percentage-input" required>';
    }

    public static function renderTasksPerMinute()
    {
        $current_value = syncBasalamSettings()->getSettings(SettingsConfig::TASKS_PER_MINUTE);
        $is_auto = syncBasalamSettings()->getSettings(SettingsConfig::TASKS_PER_MINUTE_AUTO) == 'true';
        $disabled = $is_auto ? 'disabled' : '';

        echo '<input type="number" name="sync_basalam_settings[' . esc_attr(SettingsConfig::TASKS_PER_MINUTE) . ']" min="1" max="60" value="' . esc_attr($current_value) . '" class="basalam-input basalam-p basalam-tasks-manual-input" ' . $disabled . ' required>';
    }

    public static function renderTasksPerMinuteAutoToggle()
    {
        $is_auto = syncBasalamSettings()->getSettings(SettingsConfig::TASKS_PER_MINUTE_AUTO) == 'true';
        $checked = $is_auto ? 'checked' : '';

        echo '<label class="basalam-switch">';
        echo '<input type="hidden" name="sync_basalam_settings[' . esc_attr(SettingsConfig::TASKS_PER_MINUTE_AUTO) . ']" value="false">';
        echo '<input type="checkbox" name="sync_basalam_settings[' . esc_attr(SettingsConfig::TASKS_PER_MINUTE_AUTO) . ']" value="true" ' . $checked . ' class="basalam-tasks-auto-toggle">';
        echo '<span class="basalam-slider"></span>';
        echo '</label>';
    }

    public static function renderTasksPerMinuteInfo()
    {
        $is_auto = syncBasalamSettings()->getSettings(SettingsConfig::TASKS_PER_MINUTE_AUTO) == 'true';
        $display_style = $is_auto ? '' : 'display: none;';

        $monitor = \SyncBasalam\Services\SystemResourceMonitor::getInstance();
        $optimal = $monitor->calculateOptimalTasksPerMinute();

        echo '<div class="basalam-p basalam-form-group-full" style="' . esc_attr($display_style) . '">';
        echo '<div class="basalam-tasks-info basalam-tasks-info-container" data-initial-optimal="' . esc_attr($optimal) . '">';

        echo '<div class="basalam-tasks-info-flex">';

        echo '<div class="basalam-tasks-info-item">';
        echo '<strong class="basalam-tasks-info-label">🚀 تعداد تسک های اجرایی در دقیقه: </strong>';
        echo '<span class="basalam-tasks-info-value-primary" id="basalam-tasks-optimal-value">' . esc_html($optimal) . ' تسک در دقیقه</span>';
        echo '</div>';

        echo '</div>';

        echo '</div>';
        echo '</div>';
    }

    public static function renderPrefixProductTitle()
    {
        $current_value = syncBasalamSettings()->getSettings(SettingsConfig::PRODUCT_PREFIX_TITLE);
        echo '<input type="text" name="sync_basalam_settings[' . esc_attr(SettingsConfig::PRODUCT_PREFIX_TITLE) . ']" value="' . esc_attr($current_value) . '" class="basalam-input basalam-p basalam-max-width-80 basalam-font-12">';
    }

    public static function renderSuffixProductTitle()
    {
        $current_value = syncBasalamSettings()->getSettings(SettingsConfig::PRODUCT_SUFFIX_TITLE);
        echo '<input type="text" name="sync_basalam_settings[' . esc_attr(SettingsConfig::PRODUCT_SUFFIX_TITLE) . ']" value="' . esc_attr($current_value) . '" class="basalam-input basalam-p basalam-max-width-80 basalam-font-12">';
    }

    public static function renderAttributeSuffixEnabled()
    {
        $current_value = syncBasalamSettings()->getSettings(SettingsConfig::PRODUCT_ATTRIBUTE_SUFFIX_ENABLED);
        $checked = $current_value == 'yes' ? 'checked' : '';

        echo '<label class="basalam-switch">';
        echo '<input type="checkbox" name="sync_basalam_settings[' . esc_attr(SettingsConfig::PRODUCT_ATTRIBUTE_SUFFIX_ENABLED) . ']" value="yes" ' . $checked . ' class="basalam-attribute-suffix-toggle">';
        echo '<span class="basalam-slider"></span>';
        echo '</label>';
        echo '<input type="hidden" name="sync_basalam_settings[' . esc_attr(SettingsConfig::PRODUCT_ATTRIBUTE_SUFFIX_ENABLED) . ']" value="no" class="basalam-attribute-suffix-hidden">';
    }

    public static function renderAttributeSuffixPriority()
    {
        $current_value = syncBasalamSettings()->getSettings(SettingsConfig::PRODUCT_ATTRIBUTE_SUFFIX_PRIORITY);
        $is_enabled = syncBasalamSettings()->getSettings(SettingsConfig::PRODUCT_ATTRIBUTE_SUFFIX_ENABLED) == 'yes';
        $disabled = $is_enabled ? '' : 'disabled';

        echo '<input type="text" name="sync_basalam_settings[' . esc_attr(SettingsConfig::PRODUCT_ATTRIBUTE_SUFFIX_PRIORITY) . ']" value="' . esc_attr($current_value) . '" class="basalam-input basalam-p basalam-attribute-suffix-priority basalam-max-width-80 basalam-font-12" placeholder="مثال: ناشر" ' . $disabled . '>';
    }

    public static function renderMapOptionsProduct()
    {
?>
        <div id="Basalam-map-option-form" class="basalam-flex-center-vertical" role="group" aria-label="تغییر نام ویژگی دسته بندی">
            <?php wp_nonce_field('basalam_add_map_option_nonce', 'basalam_add_map_option_nonce', false); ?>
            <label for="woo-option-name" class="basalam-p__small">نام ویژگی در ووکامرس</label>
            <input type="text" class="basalam-input basalam-width-auto" id="woo-option-name" name="woo-option-name">
            <label for="Basalam-option-name" class="basalam-p__small">نام ویژگی در باسلام</label>
            <input type="text" class="basalam-input basalam-width-auto" id="Basalam-option-name" name="Basalam-option-name">
            <button type="button" id="Basalam-map-option-submit" class="basalam-primary-button basalam-p basalam-button-auto">ذخیره</button>
        </div>
<?php
    }

    public static function renderSyncProductFields()
    {
        echo '<div>';
        echo self::renderSingleCheckbox(SettingsConfig::SYNC_PRODUCT_FIELD_NAME, 'نام');
        echo self::renderSingleCheckbox(SettingsConfig::SYNC_PRODUCT_FIELD_PHOTOS, 'عکس');
        echo self::renderSingleCheckbox(SettingsConfig::SYNC_PRODUCT_FIELD_PRICE, 'قیمت');
        echo self::renderSingleCheckbox(SettingsConfig::SYNC_PRODUCT_FIELD_STOCK, 'موجودی');
        echo self::renderSingleCheckbox(SettingsConfig::SYNC_PRODUCT_FIELD_WEIGHT, 'وزن');
        echo self::renderSingleCheckbox(SettingsConfig::SYNC_PRODUCT_FIELD_DESCRIPTION, 'توضیحات');
        echo self::renderSingleCheckbox(SettingsConfig::SYNC_PRODUCT_FIELD_ATTR, 'ویژگی ها');
        echo '</div>';
    }

    private static function renderSingleCheckbox($field_key, $label)
    {
        return '<label class="basalam-p sync-checkbox-label basalam-checkbox-label">'
            . '<input type="hidden" name="sync_basalam_settings[' . esc_attr($field_key) . ']" value="">'
            . '<input type="checkbox" name="sync_basalam_settings[' . esc_attr($field_key) . ']" value="1" '
            . checked(syncBasalamSettings()->getSettings($field_key), true, false) . '>'
            . esc_html($label)
            . '</label>';
    }

    public static function renderDeveloperMode()
    {
        $current_value = syncBasalamSettings()->getSettings(SettingsConfig::DEVELOPER_MODE);
        echo '<select name="sync_basalam_settings[' . esc_attr(SettingsConfig::DEVELOPER_MODE) . ']" class="basalam-select basalam-select-center" onchange="this.form.submit()">'
            . '<option value="false"' . selected($current_value, "false", false) . '>غیرفعال</option>'
            . '<option value="true"' . selected($current_value, "true", false) . '>فعال</option>'
            . '</select>';
    }

    public static function renderCategoryOptionsMapping($data)
    {
        $delete_nonce = wp_create_nonce('basalam_delete_mapped_option_nonce');

        echo '<div class="options_mapping_section" data-delete-nonce="' . esc_attr($delete_nonce) . '">';
        echo '<p class="basalam-p">لیست ویژگی ها : </p>';
        echo "<table class='basalam-table basalam-p'>";
        echo '<thead><tr><th>نام ویژگی در ووکامرس</th><th>نام ویژگی در باسلام</th><th>عملیات</th></tr></thead>';
        echo '<tbody>';

        if (!empty($data)) {
            foreach ($data as $item) {
                echo '<tr data-woo="' . esc_attr($item['woo_name']) . '" data-basalam="' . esc_attr($item['sync_basalam_name']) . '">';
                echo '<td>' . esc_html($item['woo_name']) . '</td>';
                echo '<td>' . esc_html($item['sync_basalam_name']) . '</td>';
                echo '<td>
                    <button
                        type="button"
                        class="Basalam-delete-option basalam-primary-button basalam-button-auto"
                        data-_wpnonce="' . esc_attr($delete_nonce) . '">
                        حذف
                    </button>
                </td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    public static function renderShippingMethod()
    {
        $current_value = syncBasalamSettings()->getSettings(SettingsConfig::ORDER_SHIPPING_METHOD);

        echo '<select class="basalam-select basalam-select-center" name="sync_basalam_settings[' . esc_attr(SettingsConfig::ORDER_SHIPPING_METHOD) . ']">';
        echo '<option value="basalam"' . selected($current_value, 'basalam', false) . '>حمل و نقل باسلام</option>';

        // Get active WooCommerce shipping methods
        if (class_exists('WC_Shipping')) {
            $shipping_zones = \WC_Shipping_Zones::get_zones();
            $unique_methods = [];

            foreach ($shipping_zones as $zone) {
                $zone_id = $zone['id'] ?? 0;
                $shipping_zone = new \WC_Shipping_Zone($zone_id);
                $methods = $shipping_zone->get_shipping_methods(true);

                foreach ($methods as $method) {
                    $method_value = 'wc_' . $method->id;
                    $method_title = $method->get_title() ?: $method->get_method_title();

                    // Avoid duplicate methods
                    if (!isset($unique_methods[$method_value])) {
                        $unique_methods[$method_value] = $method_title;
                    }
                }
            }

            // Render options
            foreach ($unique_methods as $value => $title) {
                echo '<option value="' . esc_attr($value) . '"' . selected($current_value, $value, false) . '>' . esc_html($title) . '</option>';
            }
        }

        echo '</select>';
    }

    public static function renderCustomerPrefixName()
    {
        $current_value = syncBasalamSettings()->getSettings(SettingsConfig::CUSTOMER_PREFIX_NAME);
        echo '<input type="text" name="sync_basalam_settings[' . esc_attr(SettingsConfig::CUSTOMER_PREFIX_NAME) . ']" value="' . esc_attr($current_value) . '" class="basalam-input basalam-p basalam-max-width-80 basalam-font-12" placeholder="مثال: آقای/خانم">';
    }

    public static function renderCustomerSuffixName()
    {
        $current_value = syncBasalamSettings()->getSettings(SettingsConfig::CUSTOMER_SUFFIX_NAME);
        echo '<input type="text" name="sync_basalam_settings[' . esc_attr(SettingsConfig::CUSTOMER_SUFFIX_NAME) . ']" value="' . esc_attr($current_value) . '" class="basalam-input basalam-p basalam-max-width-80 basalam-font-12" placeholder="مثال: عزیز">';
    }
}
