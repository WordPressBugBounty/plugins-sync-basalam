<?php
defined("ABSPATH") || exit;
?>

<!-- Shipping Method Popup -->
<div id="shipping-method-popup" class="basalam-popup-modal">
    <div class="popup-content">
        <div class="basalam-popup-center-gap">
            <img src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . "/images/basalam-logotype.svg") ?>" alt="Basalam">
            <h3 class="basalam-h">انتخاب روش ارسال</h3>
        </div>
        <label for="shipping-method" class="basalam-p">روش ارسال:</label>
        <select id="shipping-method" class="basalam-select-full">
            <option value="3197">پست سفارشی</option>
            <option value="3198">پست پیشتاز</option>
            <option value="4040">تیپاکس</option>
            <option value="6102">ماهکس</option>
            <option value="6101">چاپار</option>
            <option value="6112">چیتاپست</option>
            <option value="6110">آمادست</option>
            <option value="6111">دکاپست</option>
            <option value="6113">باکسیت</option>
            <option value="5137">باربری</option>
            <option value="3259">پیک</option>
            <option value="6114">سلام رسان</option>
        </select>
        <button id="submit-shipping-method" class="basalam-button basalam-btn basalam-p basalam-height-35"
            data-nonce="<?php echo esc_attr(wp_create_nonce("tracking_code_basalam_order_nonce")); ?>">تایید</button>
        <a id="cancel-shipping-method" class="basalam-button basalam-btn basalam-p basalam-a basalam-height-35">لغو</a>
    </div>
</div>