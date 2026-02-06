<?php

use SyncBasalam\Services\BasalamAppStoreReview;

defined('ABSPATH') || exit;

$basalamReviewService = new BasalamAppStoreReview();
if (isset($_POST['sync_basalam_support']) && $_POST['sync_basalam_support'] == 1) {
    $comment = isset($_POST['sync_basalam_comment']) ? sanitize_textarea_field(wp_unslash($_POST['sync_basalam_comment'])) : 'استفاده کننده فعال پلاگین.';
    $basalamReviewService->createReview($comment);
}
?>

<div class="notice notice-error basalam-notice-flex">
    <p class="basalam-p">
        در صورتی که از عملکرد پلاگین ووسلام رضایت دارید، لطفا از ما در جعبه ابزار باسلام حمایت کنید.
    </p>
    <button type="button" id="sync_basalam_support_btn" class="button-primary basalam-p">حمایت</button>
</div>

<div id="sync_basalam_support_modal" class="basalam-modal-backdrop" style="display: none;">
    <div class="basalam-bg-modal-white">
        <h3 class="basalam-margin-top-0-family">لطفا نظر خود را بنویسید</h3>
        <form method="POST" action="" id="sync_basalam_support_form">
            <?php wp_nonce_field('sync_basalam_support_action', 'sync_basalam_support_nonce'); ?>
            <input type="hidden" name="sync_basalam_support" value="1">
            <textarea name="sync_basalam_comment" id="sync_basalam_comment" rows="5" class="basalam-width-fill basalam-padding-10 basalam-border-radius" placeholder="نظر خود را اینجا بنویسید..." required></textarea>
            <div class="basalam-margin-top-15-flex">
                <button type="button" id="sync_basalam_cancel_btn" class="button basalam-p">انصراف</button>
                <button type="submit" class="button-primary basalam-p">ارسال حمایت</button>
            </div>
        </form>
    </div>
</div>