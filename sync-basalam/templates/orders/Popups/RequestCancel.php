<?php
defined("ABSPATH") || exit;
?>

<!-- Request Cancel Order Popup -->
<div id="request-cancel-order-popup" class="basalam-popup-modal">
    <div class="popup-content">
        <div class="basalam-popup-center-gap">
            <img src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . "/images/basalam-logotype.svg") ?>" alt="Basalam">
            <h3 class="basalam-h">درخواست لغو سفارش</h3>
        </div>

        <label for="request-cancel-description" class="basalam-p">توضیحات:</label>
        <textarea id="request-cancel-description" rows="4" class="basalam-textarea-full" placeholder="لطفاً دلیل درخواست لغو سفارش را وارد کنید..."></textarea>

        <button id="submit-request-cancel-order" class="basalam-button basalam-btn basalam-p basalam-height-35"
            data-nonce="<?php echo esc_attr(wp_create_nonce("request_cancel_basalam_order_nonce")); ?>">ارسال درخواست</button>
        <a id="cancel-request-cancel-order" class="basalam-button basalam-btn basalam-p basalam-a basalam-height-35">انصراف</a>
    </div>
</div>