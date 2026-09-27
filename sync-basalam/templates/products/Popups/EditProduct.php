<?php defined('ABSPATH') || exit; ?>

<div id="BasalamUpdateProductsModal" class="basalam-modal basalam-hidden" data-status-nonce="<?php echo esc_attr(wp_create_nonce('get_update_queue_status_nonce')); ?>">
    <div class="basalam-modal-content basalam-max-width-500">
        <span class="basalam-modal-close"> <img class="basalam-img-20" src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/icons/close.svg'); ?>">
        </span>

        <h3 class="basalam-h basalam-font-20-important">بروزرسانی محصولات در باسلام</h3>

        <?php if (!$vendorCanUpdate): ?>
            <div class="basalam-p basalam-warning-bg">
                <strong>بروزرسانی محصولات در دسترس نیست.</strong>
                <p><?php echo esc_html($vendorSyncState['message']); ?></p>
            </div>
        <?php else: ?>
            <div id="update-status-loading" class="basalam-p basalam-padding-top-normal" hidden>در حال بررسی وضعیت بروزرسانی...</div>
            <div id="update-status-error" class="basalam-p basalam-padding-top-normal" hidden>
                دریافت وضعیت بروزرسانی ممکن نشد.
                <button type="button" id="update-status-retry" class="basalam-primary-button basalam-p">تلاش دوباره</button>
            </div>
            <div id="update-type-selection" class="basalam-block" <?php echo $update_queue_status['active'] ? 'hidden' : ''; ?>>
                <?php wp_nonce_field('update_products_in_basalam_nonce', '_wpnonce'); ?>
                <p class="basalam-p basalam-padding-top-normal">
                    <?php echo $vendorUpdateIsLimited
                        ? 'به دلیل غیرفعال بودن غرفه، فقط قیمت و موجودی بروزرسانی می‌شود.'
                        : 'لطفا نوع بروزرسانی مورد نظر خود را انتخاب کنید:'; ?>
                </p>




                <div class="basalam-bg-light-warning-margin basalam-p">
                    <div class="basalam-display-flex-gap-20">
                        <div class="basalam-flex-1">
                            <h4 class="basalam-h basalam-margin-primary-color">• بروزرسانی فوری:</h4>
                            <p class="basalam-margin-muted">
                                فقط قیمت و موجودی محصول بروزرسانی میشود
                            </p>
                        </div>
                        <?php if (!$vendorUpdateIsLimited): ?>
                        <div class="basalam-flex-1">
                            <h4 class="basalam-h basalam-margin-dark-color">• بروزرسانی کامل:</h4>
                            <p class="basalam-margin-muted">تمام اطلاعات محصولات به صورت تکی بروزرسانی می‌شود.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="basalam-display-flex-gap-10-margin">
                    <button type="button" id="quick-update-btn" class="basalam-primary-button basalam-p basalam-height-20-flex-primary">
                        <span class="basalam-font-15-white">بروزرسانی فوری</span>
                    </button>

                    <?php if (!$vendorUpdateIsLimited): ?>
                    <button type="button" id="full-update-btn" class="basalam-primary-button basalam-p basalam-height-20-flex">
                        <span class="basalam-font-15-white">بروزرسانی کامل</span>
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <div id="quick-update-in-progress" class="basalam-display-block-10" <?php echo $update_queue_status['type'] === 'quick' ? '' : 'hidden'; ?>>
                <div class="basalam-bg-warning-info-margin basalam-p">
                    <h4 class="basalam-h basalam-margin-warning-header">
                        بروزرسانی فوری در حال اجرا است
                    </h4>

                    <p class="basalam-margin-warning">
                        <strong>نوع عملیات:</strong> بروزرسانی فوری قیمت و موجودی
                    </p>

                    <p class="basalam-margin-warning">
                        <strong>وضعیت:</strong>
                        <span id="quick-update-status" class="basalam-badge-warning">
                            <?php echo $update_queue_status['status'] === 'processing' ? 'در حال پردازش' : 'در انتظار'; ?>
                        </span>
                    </p>

                    <p class="basalam-margin-warning-justify-10">
                        لطفاً تا پایان عملیات جاری صبر کنید. می‌توانید پیشرفت را از صفحه لاگ‌ها مشاهده کنید.
                    </p>
                </div>

                <!-- Cancel button for quick update -->
                <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')) ?>" id="BasalamCancelQuickUpdateJobs">
                    <?php wp_nonce_field('cancel_update_jobs_nonce', '_wpnonce'); ?>
                    <input type="hidden" name="action" value="cancel_update_jobs">
                    <button type="submit" class="basalam-primary-button basalam-p basalam-width-danger-margin">
                        <img class="basalam-img-20-vertical" src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/icons/trash.svg'); ?>">
                        متوقف کردن عملیات
                    </button>
                </form>

            </div>
            <div id="active-jobs-info" class="basalam-display-block-10" <?php echo $update_queue_status['type'] === 'full' ? '' : 'hidden'; ?>>
                <div class="basalam-bg-warning-info-margin basalam-p">
                    <h4 class="basalam-h basalam-margin-warning-header">
                        عملیات بروزرسانی در حال اجرا است
                    </h4>

                    <p class="basalam-margin-warning">
                        <strong>نوع عملیات:</strong> بروزرسانی کامل اطلاعات محصولات
                    </p>

                        <p id="update-queue-count-row" class="basalam-margin-warning" <?php echo $update_queue_status['count'] > 0 ? '' : 'hidden'; ?>>
                            <strong>تعداد محصولات در صف:</strong>
                            <span id="update-queue-count" class="basalam-badge-warning">
                                <?php echo esc_html($update_queue_status['count']); ?> محصول
                            </span>
                        </p>

                    <p class="basalam-margin-warning-justify-10">
                        لطفاً تا پایان عملیات جاری صبر کنید. می‌توانید پیشرفت را از صفحه لاگ‌ها مشاهده کنید.
                    </p>
                </div>

                <!-- Cancel button for new job system -->
                <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')) ?>" id="BasalamCancelFullUpdateJobs">
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
