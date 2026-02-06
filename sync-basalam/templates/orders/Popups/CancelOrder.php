<?php
defined("ABSPATH") || exit;
?>

<!-- Cancel Order Popup -->
<div id="cancel-order-popup" class="basalam-popup-modal">
    <div class="popup-content">
        <div class="basalam-popup-center-gap">
            <img src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . "/images/basalam-logotype.svg") ?>" alt="Basalam">
            <h3 class="basalam-h">لغو سفارش</h3>
        </div>

        <label for="cancel-reason" class="basalam-p">دلیل لغو سفارش:</label>
        <select id="cancel-reason" class="basalam-select-full">
            <option value="">لطفاً دلیل لغو را انتخاب کنید</option>
            <option value="3473">قیمت محصول (قیمت اشتباه، کم، زیاد)</option>
            <option value="3474">عدم موجودی</option>
            <option value="3479">مشکلات ارسال</option>
            <option value="3481">مشکلات شخصی غرفه‌دار</option>
            <option value="3573">هزینه ارسال</option>
        </select>

        <label for="cancel-description" class="basalam-p">توضیحات:</label>
        <textarea id="cancel-description" rows="4" class="basalam-textarea-full" placeholder="لطفاً توضیحات لازم را وارد کنید..."></textarea>

        <button id="submit-cancel-order" class="basalam-button basalam-btn basalam-p basalam-height-35"
            data-order="<?php echo esc_attr($orderId); ?>"
            data-nonce="<?php echo esc_attr(wp_create_nonce("cancel_basalam_order_nonce")); ?>
            ">لغو سفارش</button>
        <a id="cancel-cancel-order" class="basalam-button basalam-btn basalam-p basalam-a basalam-height-35">انصراف</a>
    </div>
</div>