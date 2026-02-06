<?php
defined("ABSPATH") || exit;
?>

<!-- Delay Request Popup -->
<div id="delay-request-popup" class="basalam-popup-modal">
    <div class="popup-content">
        <div class="basalam-popup-center-gap">
            <img src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . "/images/basalam-logotype.svg") ?>" alt="Basalam">
            <h3 class="basalam-h">درخواست تاخیر در ارسال سفارش</h3>
        </div>
        <label for="delay-description" class="basalam-p">توضیحات:</label>
        <textarea id="delay-description" rows="4" class="basalam-textarea-full"></textarea>

        <label for="postpone-days" class="basalam-p">تعداد روزهای تاخیر:</label>
        <input type="number" id="postpone-days" min="1" max="7" class="basalam-input-number">

        <button id="submit-delay-request" class="basalam-button basalam-btn basalam-p basalam-height-35"
            data-nonce="<?php echo esc_attr(wp_create_nonce("delay_req_basalam_order_nonce")); ?>">ارسال درخواست</button>
        <a id="cancel-delay-request" class="basalam-button basalam-btn basalam-p basalam-a basalam-height-35">لغو</a>
    </div>
</div>