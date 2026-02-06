<?php
defined("ABSPATH") || exit;
?>

<div class="form-field">
    <label class="basalam-p basalam-label-block" for="order_tracking_code">
        کد رهگیری سفارش:
    </label>
    <input type="text" id="order_tracking_code" name="order_tracking_code" class="basalam-input-full">

    <label class="basalam-p basalam-label-block-margin" for="phone_number">
        شماره تلفن:
    </label>
    <input type="text" id="phone_number" name="phone_number" class="basalam-input-full-margin">
    <button type="button"
        class="basalam-button basalam-btn basalam-p save-tracking-code basalam-width-full"
        data-order="<?php echo esc_attr($orderId); ?>">ثبت کد رهگیری</button>

    <button type="button"
        class="basalam-button basalam-btn basalam-p request-delay basalam-width-full basalam-margin-top-10"
        data-order="<?php echo esc_attr($orderId); ?>">ثبت درخواست تاخیر در ارسال سفارش</button>

    <button type="button"
        class="basalam-button basalam-btn basalam-p request-cancel-order basalam-width-full basalam-margin-top-10"
        data-order="<?php echo esc_attr($orderId); ?>"
        data-nonce="<?php echo esc_attr(wp_create_nonce("request_cancel_basalam_order_nonce")); ?>">درخواست لغو سفارش</button>
</div>