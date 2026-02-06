<?php defined('ABSPATH') || exit; ?>
<div id="basalamAddProductsModal" class="basalam-modal basalam-modal-hidden">
    <div class="basalam-modal-content basalam-modal-max-width-400">
        <span class="basalam-modal-close"> <img class="basalam-modal-img-20" src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/icons/close.svg'); ?>">
        </span>
        <h3 class="basalam-h basalam-modal-font-20">افزودن محصولات به باسلام</h3>

        <?php if ($has_active_create_jobs): ?>
            <!-- Display when jobs are running -->
            <div id="active-create-jobs-info" class="basalam-job-info-block">
                <div class="basalam-p basalam-warning-bg">
                    <h4 class="basalam-h basalam-warning-header">
                        عملیات افزودن محصولات در حال اجرا است
                    </h4>

                    <p class="basalam-warning-text">
                        <strong>نوع عملیات:</strong> افزودن محصولات به باسلام
                    </p>

                    <?php
                    $total_create_count = $single_create_count;
            if ($total_create_count > 0): ?>
                        <p class="basalam-warning-text">
                            <strong>تعداد محصولات در صف:</strong>
                            <span class="basalam-warning-badge">
                                <?php echo $total_create_count > 1000 ? '+1000' : $total_create_count; ?> محصول
                            </span>
                        </p>
                    <?php endif; ?>

                    <p class="basalam-warning-justify-10">
                        لطفاً تا پایان عملیات جاری صبر کنید. می‌توانید پیشرفت را از صفحه لاگ‌ها مشاهده کنید.
                    </p>
                </div>
            </div>
        <?php else: ?>
            <p class="basalam-p basalam-onboarding-paragraph">آیا مطمئن هستید که می‌خواهید همه محصولات را به باسلام ارسال کنید؟</p>
        <?php endif; ?>

        <?php if (!$has_active_create_jobs): ?>
            <form method="POST" action="#" id="basalamAddProductsForm">
                <?php wp_nonce_field('create_products_to_basalam_nonce', '_wpnonce'); ?>
                <input type="hidden" name="action" value="create_products_to_basalam">
                <div class="basalam-form-group basalam-form-group-margin">
                    <label for="include_out_of_stock" class="basalam-p">
                        <input type="checkbox" id="include_out_of_stock" name="include_out_of_stock" value="1" class="basalam-checkbox-scale">
                        افزودن محصولات ناموجود نیز انجام شود
                    </label>
                </div>

                <button type="<?php echo $has_active_create_jobs ? 'button' : 'submit'; ?>"
                    class="basalam-primary-button basalam-p basalam-product-action-button"
                    <?php echo $has_active_create_jobs ? 'disabled class="basalam-button-disabled-bg"' : ''; ?>>
                    <img src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/icons/submit.svg'); ?>" alt="">
                    ارسال محصولات
                </button>
            </form>
        <?php endif; ?>

        <?php if ($has_active_create_jobs): ?>
            <!-- Cancel button for new job system -->
            <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')) ?>" id="BasalamCancelCreateJobs">
                <?php wp_nonce_field('cancel_create_jobs_nonce', '_wpnonce'); ?>
                <input type="hidden" name="action" value="cancel_create_jobs">
                <button type="submit" class="basalam-primary-button basalam-p basalam-danger-button-full">
                    <img class="basalam-danger-button-img" src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/icons/trash.svg'); ?>">
                    متوقف کردن عملیات
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div id="BasalamConfirmModal" class="basalam-modal basalam-modal-display-none">
    <div class="basalam-modal-content basalam-modal-max-width-400">
        <span class="basalam-modal-close">
            <img class="basalam-modal-img-20" src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/icons/close.svg'); ?>">
        </span>
        <h3 class="basalam-h basalam-modal-font-20">تأیید نهایی</h3>
        <p class="basalam-p basalam-padding-top-bold">
            اگر برخی از محصولات شما پیش‌تر به صورت دستی یا با استفاده از ابزارهای دیگر در باسلام ثبت شده‌اند، ابتدا لازم است از طریق قابلیت "اتصال محصولات" آن‌ها را متصل کنید. پس از آن از این گزینه استفاده نمایید.
        </p>
        <div class="basalam-margin-top-15-flex-end">
            <button class="basalam-modal-close basalam-primary-button basalam-p basalam-danger-button basalam-width-fill-available">
                انصراف
            </button>
            <button id="confirmSubmitBtn" class="basalam-primary-button basalam-p basalam-width-fill-available">
                تایید و ارسال
            </button>
        </div>
    </div>
</div>