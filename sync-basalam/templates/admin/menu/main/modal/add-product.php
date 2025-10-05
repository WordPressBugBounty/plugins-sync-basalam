<?php
if (! defined('ABSPATH')) exit;
$inProgress = false;
if ($create_product_job_exist || $count_of_chunk_create_product_tasks) {
    $inProgress = true;
}
$formClass = $inProgress ? 'not-allowed' : '';
?>
<div id="basalamAddProductsModal" class="basalam-modal" style="display:none;">
    <div class="basalam-modal-content" style="max-width: 400px;">
        <span class="basalam-modal-close"> <img style="width: 20px;" src="<?php echo esc_url(sync_basalam_configure()->assets_url() . '/icons/close.svg'); ?>">
        </span>
        <h3 class="basalam-h" style="font-size:20px;margin-top: 15px !important;">افزودن محصولات به باسلام</h3>

        <?php if ($has_active_create_jobs): ?>
            <!-- Display when jobs are running -->
            <div id="active-create-jobs-info" style="display:block;margin:10px">
                <div style="background: #fff3cd; padding: 20px; border-radius: 8px; margin-top: 15px; border: 1px solid #ffc107;" class="basalam-p">
                    <h4 class="basalam-h" style="margin: 0 0 15px 0; font-size: 16px; color: #856404;">
                        ⚠️ عملیات افزودن محصولات در حال اجرا است
                    </h4>

                    <p style="margin: 0 0 10px 0; font-size: 14px; color: #856404;">
                        <strong>نوع عملیات:</strong> افزودن محصولات به باسلام
                    </p>

                    <?php
                    $total_create_count = $single_create_count + $count_create_product_tasks;
                    if ($total_create_count > 0): ?>
                        <p style="margin: 0 0 10px 0; font-size: 14px; color: #856404;">
                            <strong>تعداد محصولات در صف:</strong>
                            <span style="background: #ffc107; color: #000; padding: 2px 8px; border-radius: 4px; font-weight: bold;">
                                <?php echo $total_create_count > 1000 ? '+1000' : $total_create_count; ?> محصول
                            </span>
                        </p>
                    <?php endif; ?>

                    <p style="margin: 10px 0 0 0; font-size: 13px; color: #856404;">
                        لطفاً تا پایان عملیات جاری صبر کنید. می‌توانید پیشرفت را از صفحه لاگ‌ها مشاهده کنید.
                    </p>
                </div>
            </div>
        <?php elseif (!$inProgress): ?>
            <p class="basalam-p" style="padding-top: 15px;font-size: 14px;text-align: justify;">آیا مطمئن هستید که می‌خواهید همه محصولات را به باسلام ارسال کنید؟</p>
        <?php endif; ?>

        <?php if ($inProgress && !$has_active_create_jobs): ?>
            <?php if ($count_of_chunk_create_product_tasks): ?>
                <p class="basalam-p" style="padding: 20px 0;font-size: 14px;font-weight:bold;text-align: justify;">محصولات در حال اضافه شدن به باسلام میباشند و به ترتیب به صف اضافه خواهند شد.</p>
            <?php endif; ?>
            <?php if (
                $inProgress &&
                !$count_of_chunk_create_product_tasks
            ): ?>
                <p class="basalam-p" style="padding: 20px 0;font-size: 14px;font-weight:bold;text-align: justify;">تمامی محصولات به نوبت اضافه خواهند شد ، میتوانید فرایند را از صفحه لاگ ها دنبال کنید.</p>
            <?php endif; ?>
            <?php if ($create_product_job_exist): ?>
                <p class="basalam-p" style="padding-bottom: 20px;font-size: 14px;">تسک های باقی مانده : <?php echo '<b style="background:red;padding:5px;color:white;border-radius:5px;">' . (esc_attr($count_create_product_tasks)) . ' عدد</b>' ?></p>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (!$has_active_create_jobs): ?>
            <form method="POST" action="#" id="basalamAddProductsForm" class="<?php echo esc_attr($formClass); ?>">
                <?php wp_nonce_field('create_products_to_basalam_nonce', '_wpnonce'); ?>
                <input type="hidden" name="action" value="create_products_to_basalam">
                <?php if ($inProgress): ?>
                    <button type="submit" class="basalam-primary-button basalam-p basalam-product-action-button" style="cursor:not-allowed; margin-bottom:0 !important;" disabled>
                        <img src="<?php echo esc_url(sync_basalam_configure()->assets_url() . '/icons/submit.svg'); ?>" alt="">
                        ارسال محصولات
                    </button>
                <?php else: ?>
                    <div class="basalam-form-group" style="margin: 10px 0;">
                        <label for="include_out_of_stock" class="basalam-p">
                            <input type="checkbox" id="include_out_of_stock" name="include_out_of_stock" value="1" style="transform: scale(1.2);">
                            افزودن محصولات ناموجود نیز انجام شود
                        </label>
                    </div>

                    <button type="submit" class="basalam-primary-button basalam-p basalam-product-action-button" <?php echo esc_html($create_product_job_exist) ? 'disabled' : ''; ?>>
                        <img src="<?php echo esc_url(sync_basalam_configure()->assets_url() . '/icons/submit.svg'); ?>" alt="">
                        ارسال محصولات
                    </button>
                <?php endif; ?>
            </form>
        <?php else: ?>
            <!-- Disabled button when jobs are running -->
            <div style="margin-top: 20px; margin-bottom: 10px;">
                <button type="button" disabled class="basalam-primary-button basalam-p basalam-product-action-button" style="cursor:not-allowed; background-color: #ccc !important; margin-bottom:0 !important;">
                    <img src="<?php echo esc_url(sync_basalam_configure()->assets_url() . '/icons/submit.svg'); ?>" alt="">
                    ارسال محصولات
                </button>
            </div>
        <?php endif; ?>

        <?php if ($has_active_create_jobs): ?>
            <!-- Cancel button for new job system -->
            <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')) ?>" id="BasalamCancelCreateJobs">
                <?php wp_nonce_field('cancel_create_jobs_nonce', '_wpnonce'); ?>
                <input type="hidden" name="action" value="cancel_create_jobs">
                <button type="submit" class="basalam-primary-button basalam-p" style="width: 100%; background-color: #dc3545 !important; margin-bottom: 10px !important;">
                    <img style="width: 20px; vertical-align: middle; margin-left: 5px;" src="<?php echo esc_url(sync_basalam_configure()->assets_url() . '/icons/trash.svg'); ?>">
                    متوقف کردن عملیات
                </button>
            </form>
        <?php elseif ($inProgress): ?>
            <!-- Legacy cancel button -->
            <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')) ?>" id="BasalamDeleteTasks">
                <?php wp_nonce_field('cancel_create_products_to_basalam_nonce', '_wpnonce'); ?>
                <input type="hidden" name="action" value="cancel_create_products_to_basalam">
                <button type="submit" class="basalam-primary-button basalam-p" style="width: -webkit-fill-available; background-color: red !important;margin-top:10px !important;margin-bottom: 10px !important;">
                    <img style="width: 20px;" src="<?php echo esc_url(sync_basalam_configure()->assets_url() . '/icons/trash.svg'); ?>">
                    متوقف کردن فرایند
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>
<div id="BasalamConfirmModal" class="basalam-modal" style="display: none;">
    <div class="basalam-modal-content" style="max-width: 400px;">
        <span class="basalam-modal-close">
            <img style="width: 20px;" src="<?php echo esc_url(sync_basalam_configure()->assets_url() . '/icons/close.svg'); ?>">
        </span>
        <h3 class="basalam-h" style="font-size:20px;margin-top: 15px !important;">تأیید نهایی</h3>
        <p class="basalam-p" style="padding-top: 15px;font-size: 14px;font-weight:bold;text-align:justify;">
            اگر برخی از محصولات شما پیش‌تر به صورت دستی یا با استفاده از ابزارهای دیگر در باسلام ثبت شده‌اند، ابتدا لازم است از طریق قابلیت "اتصال محصولات" آن‌ها را متصل کنید. پس از آن از این گزینه استفاده نمایید.
        </p>
        <div style="margin-top: 15px; display: flex; justify-content: space-between; gap: 10px;margin-bottom: 15px;">
            <button class="basalam-modal-close basalam-primary-button basalam-p basalam-danger-button" style="flex: 1;">
                انصراف
            </button>
            <button id="confirmSubmitBtn" class="basalam-primary-button basalam-p" style="flex: 1;">
                تایید و ارسال
            </button>
        </div>
    </div>
</div>