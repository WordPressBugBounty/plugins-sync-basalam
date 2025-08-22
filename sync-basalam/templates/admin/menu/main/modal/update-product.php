<?php
if (! defined('ABSPATH')) exit;
$inProgress = false;
if ($update_product_job_exist || $count_of_chunk_update_product_tasks) {
    $inProgress = true;
}
$formClass = $inProgress ? 'not-allowed' : '';
?>
<div id="BasalamUpdateProductsModal" class="basalam-modal" style="display:none;">
    <div class="basalam-modal-content" style="max-width: 400px;">
        <span class="basalam-modal-close"> <img style="width: 20px;" src="<?php echo esc_url(sync_basalam_configure()->assets_url() . '/icons/close.svg'); ?>">
        </span>

        <h3 class="basalam-h" style="font-size:20px;margin-top: 15px !important;">بروزرسانی محصولات در باسلام</h3>
        <?php if (!$inProgress): ?>
            <p class="basalam-p" style="padding-top: 15px;font-size: 14px;text-align: justify;">آیا مطمئن هستید که می‌خواهید همه محصولات را در باسلام بروزرسانی کنید؟</p>
        <?php endif; ?>
        <?php if ($count_of_chunk_update_product_tasks): ?>
            <p class="basalam-p" style="padding: 20px 0;font-size: 14px;font-weight:bold;text-align: justify;">محصولات در حال اضافه شدن به باسلام میباشند و به ترتیب به صف اضافه خواهند شد.</p>
        <?php endif; ?>
        <?php if (
            $inProgress &&
            !$count_of_chunk_update_product_tasks
        ): ?>
            <p class="basalam-p" style="padding: 20px 0;font-size: 14px;font-weight:bold;text-align: justify;">تمامی محصولات به نوبت به روزرسانی خواهند شد ، میتوانید فرایند را از صفحه لاگ ها دنبال کنید.</p>
        <?php endif; ?>
        <?php if ($update_product_job_exist): ?>
            <p class="basalam-p" style="padding-bottom: 20px;font-size: 14px;">تسک های باقی مانده : <?php echo '<b style="background:red;padding:5px;color:white;border-radius:5px;">' . (esc_attr($count_update_product_tasks)) . ' عدد</b>' ?></p>
        <?php endif; ?>
        <form method="POST" action="" id="BasalamUpdateProductsForm" class="<?php echo esc_attr($formClass); ?>">
            <?php wp_nonce_field('update_products_in_basalam_nonce', '_wpnonce'); ?>
            <?php if ($inProgress): ?>
                <button type="submit" class="basalam-primary-button basalam-p basalam-product-action-button" style="cursor:not-allowed; margin-bottom:0 !important;" disabled>
                    <img src="<?php echo esc_url(sync_basalam_configure()->assets_url() . '/icons/submit.svg'); ?>" alt="">
                    ارسال محصولات
                </button>
            <?php else: ?>
                <button type="submit" style="margin-top: 12px !important;" class="basalam-primary-button basalam-p basalam-product-action-button" <?php echo esc_html($update_product_job_exist) ? 'disabled' : ''; ?>>
                    <img src="<?php echo esc_url(sync_basalam_configure()->assets_url() . '/icons/submit.svg'); ?>" alt="">
                    ارسال محصولات
                </button>
            <?php endif; ?>
        </form>

        <?php if ($inProgress): ?>
            <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')) ?>" id="BasalamDeleteTasks">
                <?php wp_nonce_field('cancel_update_products_in_basalam_nonce', '_wpnonce'); ?>
                <input type="hidden" name="action" value="cancel_update_products_in_basalam">
                <button type="submit" class="basalam-primary-button basalam-p" style="width: -webkit-fill-available; background-color: red !important;margin-top:10px !important;margin-bottom: 10px !important;">
                    <img style="width: 20px;" src="<?php echo esc_url(sync_basalam_configure()->assets_url() . '/icons/trash.svg'); ?>">
                    متوقف کردن فرایند
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>