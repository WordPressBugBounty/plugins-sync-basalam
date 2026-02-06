<?php
defined("ABSPATH") || exit;
?>

<p class="basalam-p basalam-text-justify-12">
    شما تنها 24 ساعت فرصت دارید تا وضعیت سفارش خود را مشخص کنید. در غیر این صورت، وضعیت سفارش به صورت خودکار به لغو شده تغییر می‌کند.
</p>
<div class="form-field basalam-margin-top-15">
    <button type="button"
        class="basalam-button basalam-btn basalam-p confirm-order basalam-margin-bottom-5-width"
        data-order="<?php echo esc_attr($orderId); ?>"
        data-nonce="<?php echo esc_attr(wp_create_nonce("confirm_basalam_order_nonce")); ?>">تایید سفارش</button>
    <button type="button"
        class="basalam-button basalam-btn basalam-p cancel-order basalam-width-full-margin-top-5"
        data-order="<?php echo esc_attr($orderId); ?>"
        data-nonce="<?php echo esc_attr(wp_create_nonce("cancel_basalam_order_nonce")); ?>">لغو سفارش</button>
</div>