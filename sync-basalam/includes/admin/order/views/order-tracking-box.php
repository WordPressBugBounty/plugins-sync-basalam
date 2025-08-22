<?php
if (! defined('ABSPATH')) exit;

$logo_filename = 'basalam-logotype.svg';
?>

<div class="order-tracking-box">
    <?php if ($order_status == 'bslm-wait-vendor'): ?>
        <p class="basalam-p" style="text-align: justify;font-size:12px;">
            شما تنها 24 ساعت فرصت دارید تا وضعیت سفارش خود را مشخص کنید. در غیر این صورت، وضعیت سفارش به صورت خودکار به لغو شده تغییر می‌کند.
        </p>
        <div class="form-field" style="margin-top: 15px;">
            <button type="button"
                class="basalam-button basalam-btn basalam-p confirm-order"
                data-order="<?php echo esc_attr($order_id); ?>"
                data-nonce="<?php echo esc_attr(wp_create_nonce('confirm_basalam_order_nonce')); ?>"
                style="margin-bottom: 5px; width: 100%;">تایید سفارش</button>
            <!-- <button type="button"
                class="basalam-button basalam-btn basalam-p cancel-order"
                data-order="<?php echo esc_attr($order_id); ?>"
                style="width: 100%; margin-top: 5px;">لغو سفارش</button> -->
        </div>

    <?php elseif ($order_status == 'bslm-preparation'): ?>
        <div class="form-field">
            <label class="basalam-p" for="order_tracking_code" style="display: block; margin-bottom: 5px;font-size: 12px;text-align: justify;margin-bottom: 5px !important;">
                کد رهگیری سفارش:
            </label>
            <input type="text" id="order_tracking_code" name="order_tracking_code" style="width: 100%;">

            <label class="basalam-p" for="phone_number" style="display: block; margin-bottom: 5px; margin-top: 5px;font-size: 12px;text-align: justify;margin-bottom: 5px !important;margin-top: 5px !important;">
                شماره تلفن:
            </label>
            <input type="text" id="phone_number" name="phone_number" style="width: 100%;margin-bottom: 15px !important;">
            <button type="button"
                class="basalam-button basalam-btn basalam-p save-tracking-code"
                data-order="<?php echo esc_attr($order_id); ?>"
                style="width: 100%;">ثبت کد رهگیری</button>

            <button type="button"
                class="basalam-button basalam-btn basalam-p request-delay"
                data-order="<?php echo esc_attr($order_id); ?>"
                style="width: 100%; margin-top: 10px;">ثبت درخواست تاخیر در ارسال سفارش</button>
        </div>

    <?php elseif ($order_status == 'bslm-shipping'): ?>
        <p class="basalam-p__small" style="text-align: justify;">
            سفارش توسط شما برای مشتری ارسال شده است. پس از دریافت محصول توسط مشتری و اعلام آن، وضعیت این سفارش به تکمیل شده تغییر می‌کند.
        </p>
    <?php endif; ?>
</div>

<!-- Delay Request Popup -->
<div id="delay-request-popup" style="box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1), 0 1px 3px rgba(0, 0, 0, 0.08); display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 20px; border: 1px solid #ccc; z-index: 1000;">
    <div class="popup-content">
        <div style="align-items: center; display: flex; justify-content: center; gap: 10px;">
            <img src="<?php echo esc_url(sync_basalam_configure()->assets_url() . "/images/" . $logo_filename) ?>" alt="Basalam">
            <h3 class="basalam-h">درخواست تاخیر در ارسال سفارش</h3>
        </div>
        <label for="delay-description" class="basalam-p">توضیحات:</label>
        <textarea id="delay-description" rows="4" style="width: 100%; margin: 10px 0;font-family: 'PelakFA';"></textarea>

        <label for="postpone-days" class="basalam-p">تعداد روزهای تاخیر:</label>
        <input type="number" id="postpone-days" min="1" max="7" style="width: 100%; margin: 10px 0;font-family: 'PelakFA'">

        <button id="submit-delay-request" class="basalam-button basalam-btn basalam-p" style="height: 35px;"
            data-nonce="<?php echo esc_attr(wp_create_nonce('delay_req_basalam_order_nonce')); ?>">ارسال درخواست</button>
        <a id="cancel-delay-request" class="basalam-button basalam-btn basalam-p basalam-a" style="height: 35px;">لغو</a>
    </div>
</div>

<!-- Shipping Method Popup -->
<div id="shipping-method-popup" style="box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1), 0 1px 3px rgba(0, 0, 0, 0.08); display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 20px; border: 1px solid #ccc; z-index: 1000;">
    <div class="popup-content">
        <div style="align-items: center; display: flex; justify-content: center; gap: 10px;">
            <img src="<?php echo esc_url(sync_basalam_configure()->assets_url() . "/images/" . $logo_filename) ?>" alt="Basalam">
            <h3 class="basalam-h">انتخاب روش ارسال</h3>
        </div>
        <label for="shipping-method" class="basalam-p">روش ارسال:</label>
        <select id="shipping-method" style="width: 100%; margin: 10px 0;font-family: 'PelakFA';">
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
        <button id="submit-shipping-method" class="basalam-button basalam-btn basalam-p" style="height: 35px;"
            data-nonce="<?php echo esc_attr(wp_create_nonce('tracking_code_basalam_order_nonce')); ?>">تایید</button>
        <a id="cancel-shipping-method" class="basalam-button basalam-btn basalam-p basalam-a" style="height: 35px;">لغو</a>
    </div>
</div>