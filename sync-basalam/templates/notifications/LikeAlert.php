<?php

defined('ABSPATH') || exit;

$show_notice = true;
$remind_later_transient = get_transient('sync_basalam_remind_later_review');
$never_remind_option = get_option('sync_basalam_review_never_remind', false);

if ($remind_later_transient !== false || $never_remind_option) $show_notice = false;

if (!$show_notice) return;

?>

<div class="notice notice-info basalam-notice-flex" id="sync_basalam_like_alert">
    <input type="hidden" id="sync_basalam_remind_later_review_nonce" value="<?php echo wp_create_nonce('sync_basalam_remind_later_review_nonce'); ?>">
    <input type="hidden" id="sync_basalam_never_remind_review_nonce" value="<?php echo wp_create_nonce('sync_basalam_never_remind_review_nonce'); ?>">
    <input type="hidden" id="sync_basalam_submit_review_nonce" value="<?php echo wp_create_nonce('sync_basalam_submit_review_nonce'); ?>">
    <p class="basalam-p">
        در صورتی که از عملکرد پلاگین ووسلام رضایت دارید، لطفا از ما در جعبه ابزار باسلام حمایت کنید.
    </p>
    <button type="button" id="sync_basalam_support_btn" class="button-primary basalam-p">حمایت</button>
    <button type="button" id="sync_basalam_remind_later_review_btn" class="button basalam-p">بعدا نظر می‌دهم</button>
    <button type="button" id="sync_basalam_never_remind_review_btn" class="button basalam-p">نظر نمی‌دهم</button>
</div>

<div id="sync_basalam_support_modal" class="basalam-modal-backdrop" style="display: none;">
    <div class="basalam-bg-modal-white">
        <h3 class="basalam-margin-top-0-family">لطفا نظر خود را بنویسید</h3>
        <form id="sync_basalam_support_form">
            <input type="hidden" name="sync_basalam_support" value="1">

            <div class="basalam-rating-container" style="display:flex;gap:10px;margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px;" class="basalam-p">امتیاز شما:</label>
                <div class="basalam-stars" id="basalam_rating_stars">
                    <span class="basalam-star" data-rating="1">&#9733;</span>
                    <span class="basalam-star" data-rating="2">&#9733;</span>
                    <span class="basalam-star" data-rating="3">&#9733;</span>
                    <span class="basalam-star" data-rating="4">&#9733;</span>
                    <span class="basalam-star" data-rating="5">&#9733;</span>
                </div>
                <input type="hidden" name="sync_basalam_rating" id="sync_basalam_rating" value="5">
            </div>

            <textarea name="sync_basalam_comment" id="sync_basalam_comment" required rows="3" class="basalam-width-fill basalam-padding-10 basalam-border-radius" placeholder="نظر خود را اینجا بنویسید..." required></textarea>
            <div class="basalam-margin-top-15-flex">
                <button type="button" id="sync_basalam_cancel_btn" class="button basalam-p">انصراف</button>
                <button type="submit" class="button-primary basalam-p">ارسال حمایت</button>
            </div>
        </form>
    </div>
</div>
