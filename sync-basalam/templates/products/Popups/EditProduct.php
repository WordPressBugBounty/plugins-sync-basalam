<?php defined('ABSPATH') || exit; ?>

<div id="BasalamUpdateProductsModal" class="basalam-modal basalam-hidden">
    <div class="basalam-modal-content basalam-max-width-500">
        <span class="basalam-modal-close"> <img class="basalam-img-20" src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/icons/close.svg'); ?>">
        </span>

        <h3 class="basalam-h basalam-font-20-important">بروزرسانی محصولات در باسلام</h3>

        <?php if (!$has_active_update_jobs): ?>
            <!-- Selection Screen when no jobs are running -->
            <div id="update-type-selection" class="basalam-block">
                <?php wp_nonce_field('update_products_in_basalam_nonce', '_wpnonce'); ?>
                <p class="basalam-p basalam-padding-top-normal">لطفا نوع بروزرسانی مورد نظر خود را انتخاب کنید:</p>




                <div class="basalam-bg-light-warning-margin basalam-p">
                    <div class="basalam-display-flex-gap-20">
                        <div class="basalam-flex-1">
                            <h4 class="basalam-h basalam-margin-primary-color">• بروزرسانی فوری:</h4>
                            <p class="basalam-margin-muted">
                                فقط قیمت و موجودی محصول بروزرسانی میشود
                            </p>
                        </div>
                        <div class="basalam-flex-1">
                            <h4 class="basalam-h basalam-margin-dark-color">• بروزرسانی کامل:</h4>
                            <p class="basalam-margin-muted">تمام اطلاعات محصولات به صورت تکی بروزرسانی می‌شود.</p>
                        </div>
                    </div>
                </div>

                <div class="basalam-display-flex-gap-10-margin">
                    <button type="button" id="quick-update-btn" class="basalam-primary-button basalam-p basalam-height-20-flex-primary">
                        <span class="basalam-font-15-white">بروزرسانی فوری</span>
                    </button>

                    <button type="button" id="full-update-btn" class="basalam-primary-button basalam-p basalam-height-20-flex">
                        <span class="basalam-font-15-white">بروزرسانی کامل</span>
                    </button>
                </div>
            </div>

        <?php elseif ($quick_update_processing_job): ?>
            <div id="quick-update-in-progress" class="basalam-display-block-10">
                <div class="basalam-bg-warning-info-margin basalam-p">
                    <h4 class="basalam-h basalam-margin-warning-header">
                        بروزرسانی فوری در حال اجرا است
                    </h4>

                    <p class="basalam-margin-warning">
                        <strong>نوع عملیات:</strong> بروزرسانی فوری قیمت و موجودی
                    </p>

                    <p class="basalam-margin-warning">
                        <strong>وضعیت:</strong>
                        <span class="basalam-badge-warning">
                            <?php echo $quick_update_processing_job ? 'در حال پردازش' : 'در انتظار'; ?>
                        </span>
                    </p>

                    <p class="basalam-margin-warning-justify-10">
                        لطفاً تا پایان عملیات جاری صبر کنید. می‌توانید پیشرفت را از صفحه لاگ‌ها مشاهده کنید.
                    </p>
                </div>

                <!-- Cancel button for quick update -->
                <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')) ?>" id="BasalamCancelUpdateJobs">
                    <?php wp_nonce_field('cancel_update_jobs_nonce', '_wpnonce'); ?>
                    <input type="hidden" name="action" value="cancel_update_jobs">
                    <button type="submit" class="basalam-primary-button basalam-p basalam-width-danger-margin">
                        <img class="basalam-img-20-vertical" src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/icons/trash.svg'); ?>">
                        متوقف کردن عملیات
                    </button>
                </form>

            </div>
        <?php elseif ($has_active_update_jobs): ?>
            <!-- Display when jobs are running -->
            <div id="active-jobs-info" class="basalam-display-block-10">
                <div class="basalam-bg-warning-info-margin basalam-p">
                    <h4 class="basalam-h basalam-margin-warning-header">
                        عملیات بروزرسانی در حال اجرا است
                    </h4>

                    <?php if ($active_update_type === 'quick'): ?>
                        <p class="basalam-margin-warning">
                            <strong>نوع عملیات:</strong> بروزرسانی فوری قیمت و موجودی
                        </p>
                    <?php elseif ($active_update_type === 'full'): ?>
                        <p class="basalam-margin-warning">
                            <strong>نوع عملیات:</strong> بروزرسانی کامل اطلاعات محصولات
                        </p>
                    <?php endif; ?>

                    <?php if ($single_update_count > 0): ?>
                        <p class="basalam-margin-warning">
                            <strong>تعداد محصولات در صف:</strong>
                            <span class="basalam-badge-warning">
                                <?php echo $single_update_count; ?> محصول
                            </span>
                        </p>
                    <?php endif; ?>

                    <p class="basalam-margin-warning-justify-10">
                        لطفاً تا پایان عملیات جاری صبر کنید. می‌توانید پیشرفت را از صفحه لاگ‌ها مشاهده کنید.
                    </p>
                </div>

                <!-- Cancel button for new job system -->
                <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')) ?>" id="BasalamCancelUpdateJobs">
                    <?php wp_nonce_field('cancel_update_jobs_nonce', '_wpnonce'); ?>
                    <input type="hidden" name="action" value="cancel_update_jobs">
                    <button type="submit" class="basalam-primary-button basalam-p basalam-width-danger-margin">
                        <img class="basalam-img-20-vertical" src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/icons/trash.svg'); ?>">
                        متوقف کردن عملیات
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>