<?php

namespace SyncBasalam\Admin;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Services\SystemResourceMonitor;

defined('ABSPATH') || exit;
class Components
{
    public static function renderIcon($icon)
    {
        return sprintf(
            '<span class="dashicons %s basalom-icon-medium"></span>',
            esc_attr($icon)
        );
    }

    public static function renderInfoPopup($content, $unique_id = '')
    {
        $info_icon_url = syncBasalamPlugin()->assetsUrl() . "/icons/info-black.svg";
        $modal_id = 'basalam-info-modal-' . $unique_id;

        return sprintf(
            '<div class="basalam-info-trigger" data-modal-id="%s">'
                . '<img src="%s" alt="اطلاعات" class="basalam-info-icon" title="برای مشاهده توضیحات کلیک کنید">'
                . '</div>'
                . '<div id="%s" class="basalam-info-modal basalam-modal-display-none">'
                . '<div class="basalam-info-modal-overlay"></div>'
                . '<div class="basalam-info-modal-content">'
                . '<div class="basalam-info-modal-header">'
                . '<h3 class="basalam-modal-header-text">راهنما</h3>'
                . '<span class="basalam-info-modal-close">&times;</span>'
                . '</div>'
                . '<div class="basalam-info-modal-body">%s</div>'
                . '</div>'
                . '</div>',
            esc_attr($modal_id),
            esc_url($info_icon_url),
            esc_attr($modal_id),
            esc_html($content)
        );
    }

    public static function renderLabelWithTooltip($label_text, $tooltip_content, $position = 'top')
    {

        $unique_id = sanitize_title($label_text);

        return sprintf(
            '<div class="basalam-label-container">'
                . '<label class="basalam-label">'
                . '<span class="basalam-label-text">%s</span>'
                . '%s'
                . '</label>'
                . '</div>',
            esc_html($label_text),
            self::renderInfoPopup($tooltip_content, $unique_id)
        );
    }

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

    public static function renderProductOperationType()
    {
        $current_value = syncBasalamSettings()->getSettings(SettingsConfig::PRODUCT_OPERATION_TYPE);
        echo '<select class="basalam-select basalam-select-center" name="sync_basalam_settings[' . esc_attr(SettingsConfig::PRODUCT_OPERATION_TYPE) . ']">'
            . '<option value="optimized"' . selected($current_value, 'optimized', false) . '>بهینه (استفاده از صف)</option>'
            . '<option value="immediate"' . selected($current_value, 'immediate', false) . '>در لحظه</option>'
            . '</select>';
    }

    public static function renderProductDiscountDuration()
    {
        $current_value = syncBasalamSettings()->getSettings(SettingsConfig::DISCOUNT_DURATION);

        echo '<input type="number" id="percentage-input" name="sync_basalam_settings[' . esc_attr(SettingsConfig::DISCOUNT_DURATION) . ']" min="1" max="90" value="' . esc_attr($current_value) . '" class="basalam-input basalam-p percentage-input" required>';
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

        $monitor = SystemResourceMonitor::getInstance();
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

        <form id="Basalam-map-option-form" method="post" class="basalam-flex-center-vertical">
            <?php wp_nonce_field('basalam_add_map_option_nonce', 'basalam_add_map_option_nonce'); ?>
            <label for="woo-option-name" class="basalam-p__small">نام ویژگی در ووکامرس</label>
            <input type="text" class="basalam-input basalam-width-auto" id="woo-option-name" name="woo-option-name" required>
            <label for="Basalam-option-name" class="basalam-p__small">نام ویژگی در باسلام</label>
            <input type="text" class="basalam-input basalam-width-auto" id="Basalam-option-name" name="Basalam-option-name" required>
            <button type="submit" class="basalam-primary-button basalam-p basalam-button-auto">ذخیره</button>
        </form>

    <?php
    }

    public static function renderSyncProductFields()
    {
        echo '<div>';
        echo wp_kses(self::renderSingleCheckbox(SettingsConfig::SYNC_PRODUCT_FIELD_NAME, 'نام'), self::allowedHtml());
        echo wp_kses(self::renderSingleCheckbox(SettingsConfig::SYNC_PRODUCT_FIELD_PHOTOS, 'عکس'), self::allowedHtml());
        echo wp_kses(self::renderSingleCheckbox(SettingsConfig::SYNC_PRODUCT_FIELD_PRICE, 'قیمت'), self::allowedHtml());
        echo wp_kses(self::renderSingleCheckbox(SettingsConfig::SYNC_PRODUCT_FIELD_STOCK, 'موجودی'), self::allowedHtml());
        echo wp_kses(self::renderSingleCheckbox(SettingsConfig::SYNC_PRODUCT_FIELD_WEIGHT, 'وزن'), self::allowedHtml());
        echo wp_kses(self::renderSingleCheckbox(SettingsConfig::SYNC_PRODUCT_FIELD_DESCRIPTION, 'توضیحات'), self::allowedHtml());
        echo wp_kses(self::renderSingleCheckbox(SettingsConfig::SYNC_PRODUCT_FIELD_ATTR, 'ویژگی ها'), self::allowedHtml());
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

    public static function allowedHtml()
    {
        return [
            'form' => [
                'action' => [],
                'method' => [],
                'id'     => [],
                'class'  => [],
            ],
            'input' => [
                'type'     => [],
                'name'     => [],
                'value'    => [],
                'class'    => [],
                'required' => [],
                'style'    => [],
                'id'       => [],
                'checked'  => [],
            ],
            'button' => [
                'type'  => [],
                'class' => [],
                'style' => [],
            ],
            'div' => [
                'class' => [],
                'id'    => [],
                'style' => [],
            ],
            'p' => [
                'class' => [],
                'style' => [],
            ],
            'strong' => [],
            'label'  => [
                'class' => [],
                'style' => [],
            ],
            'svg' => [
                'width'   => [],
                'height'  => [],
                'viewBox' => [],
                'xmlns'   => [],
                'fill'    => [],
            ],
            'path' => [
                'fill'      => [],
                'fill-rule' => [],
                'clip-rule' => [],
                'd'         => [],
            ],
            'span' => [
                'class' => [],
            ],
            'code' => [
                'class' => [],
            ],
            'a' => [
                'href'   => [],
                'target' => [],
                'class'  => [],
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
                'src'   => [],
                'alt'   => [],
                'class' => [],
            ],
        ];
    }

    public static function renderDeveloperMode()
    {
        $current_value = syncBasalamSettings()->getSettings(SettingsConfig::DEVELOPER_MODE);
        echo '<select name="sync_basalam_settings[' . esc_attr(SettingsConfig::DEVELOPER_MODE) . ']" class="basalam-select basalam-select-center" onchange="this.form.submit()">'
            . '<option value="false"' . selected($current_value, "false", false) . '>غیرفعال</option>'
            . '<option value="true"' . selected($current_value, "true", false) . '>فعال</option>'
            . '</select>';
    }

    public static function renderFaqByCategory($categories)
    {
        foreach ($categories as $category) {
            $is_active = $category === 'عمومی' ? ' active' : '';
            $nonce = wp_create_nonce('sync_basalam_faq_nonce');
            echo '<div class="basalam-faq-section' . esc_attr($is_active) . '" data-category="' . esc_attr($category) . '" data-nonce="' . esc_attr($nonce) . '">';

            $faqs = Faq::getFaqByCategory($category);
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

    public static function renderBtn($text, $link = null, $name = null, $product_id = null, $nonce_name = null)
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

    public static function renderCheckOrdersButton()
    {
    ?>
        <div class="alignleft actions custom">
            <button type="button" class="basalam-button basalam-p basalam_add_unsync_orders basalam-height-32"
                title="تمامی سفارشات جدیدی باسلامی که همگام سازی نشده اند ، اضافه میشوند."
                data-nonce="<?php echo esc_attr(wp_create_nonce('add_unsync_orders_from_basalam_nonce')); ?>">
                بررسی سفارشات باسلام
            </button>
        </div>
    <?php
    }

    public static function renderCheckOrdersButtonTraditional()
    {
        $screen = get_current_screen();

        // Only show on shop order list page when HPOS is not enabled
        if (!$screen || $screen->id !== 'edit-shop_order') {
            return;
        }

        // Check if HPOS is enabled - skip if it is
        if (
            function_exists('woocommerce_custom_orders_table_usage_is_enabled') &&
            woocommerce_custom_orders_table_usage_is_enabled()
        ) {
            return;
        }

        // Check post type to ensure we're on the shop order page
        if (!isset($_GET['post_type']) || $_GET['post_type'] !== 'shop_order') {
            return;
        }

    ?>
        <div class="alignleft actions custom">
            <button type="button" class="basalam-button basalam-p basalam_add_unsync_orders basalam-height-32"
                title="تمامی سفارشات جدیدی باسلامی که همگام سازی نشده اند ، اضافه میشوند."
                data-nonce="<?php echo esc_attr(wp_create_nonce('add_unsync_orders_from_basalam_nonce')); ?>">
                بررسی سفارشات باسلام
            </button>
        </div>
<?php
    }

    public static function renderCategoryOptionsMapping($data)
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
                        class="Basalam-delete-option basalam-primary-button basalam-button-auto"
                        data-_wpnonce="' . esc_attr($delete_nonce) . '"
                        onclick="return confirm(\'آیا مطمئن هستید؟\')">
                        حذف
                    </button>
                </td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
        echo '</div>';
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
                    <th class='basalam-table-padding basalam-table-cell-center'>محصول در باسلام</th>
                </tr>
              </thead>";

        echo "<tbody>";

        foreach ($unsync_products as $index => $product) {
            echo "<tr class='basalam-table-row'>
            <td class='basalam-table-padding'>" . esc_html($index + 1) . "</td>
            <td class='basalam-table-padding'><img src='" . esc_url($product['photo']) . "' alt='Product Image' class='basalam-product-img-table'></td>
            <td class='basalam-table-padding'>" . esc_html($product['title']) . "</td>
            <td class='basalam-table-padding'>" . esc_html(number_format($product['price'])) . "</td>
            <td class='basalam-table-padding'>" . esc_html($product['id']) . "</td>
            <td class='basalam-table-padding basalam-table-cell-center'>
            <button class='basalam-button basalam-p basalam-button-table basalam-height-35 basalam-margin-auto'>
                <a class='basalam-a basalam-link-small' href='https://basalam.com/p/" . esc_attr($product['id']) . "' target='_blank'>مشاهده محصول</a>
            </button>

            </td>
          </tr>";
        }

        echo "</tbody></table>";
        echo "</div>";
    }
    public static function renderUnauthorizedError()
    {
        echo '<div class="basalam-container">
            <div class="basalam-error-message">
                <p class="basalam-p">دسترسی شما صحیح نیست، فرایند دریافت دسترسی را مجددا انجام دهید و در صورت مشکل با پشتیبانی ارتباط برقرار کنید.</p>
            </div>
        </div>';
    }
}
