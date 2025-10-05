<?php
if (! defined('ABSPATH')) exit;
$inProgress = false;
if ($update_product_job_exist || $count_of_chunk_update_product_tasks) {
    $inProgress = true;
}
$formClass = $inProgress ? 'not-allowed' : '';
?>
<style>
    #quick-update-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 168, 132, 0.3);
    }

    #full-update-btn:hover {
        background: #34495e !important;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(44, 62, 80, 0.3);
    }
</style>
<div id="BasalamUpdateProductsModal" class="basalam-modal" style="display:none;">
    <div class="basalam-modal-content" style="max-width: 500px;">
        <span class="basalam-modal-close"> <img style="width: 20px;" src="<?php echo esc_url(sync_basalam_configure()->assets_url() . '/icons/close.svg'); ?>">
        </span>

        <h3 class="basalam-h" style="font-size:20px;margin-top: 15px !important;">بروزرسانی محصولات در باسلام</h3>

        <?php if (!$inProgress && !$has_active_update_jobs): ?>
            <!-- Selection Screen when no jobs are running -->
            <div id="update-type-selection" style="display:block;">
                <?php wp_nonce_field('update_products_in_basalam_nonce', '_wpnonce'); ?>
                <p class="basalam-p" style="padding-top: 15px;font-size: 14px;text-align: justify;">لطفا نوع بروزرسانی مورد نظر خود را انتخاب کنید:</p>

                <div style="background: #f5f5f5; padding: 15px; border-radius: 8px; margin-top: 15px;" class="basalam-p">
                    <div style="display: flex; gap: 20px;">
                        <div style="flex: 1;">
                            <h4 style="margin: 0 0 8px 0; font-size: 14px; color: var(--basalam-primary-color);" class="basalam-h">• بروزرسانی فوری:</h4>
                            <p style="margin: 0; font-size: 12px; color: #666; line-height: 1.5;">
                                فقط قیمت و موجودی محصول بروزرسانی میشود(2000 بروزرسانی در دقیقه)
                            </p>
                        </div>
                        <div style="flex: 1;">
                            <h4 style="margin: 0 0 8px 0; font-size: 14px; color: #2c3e50;" class="basalam-h">• بروزرسانی کامل:</h4>
                            <p style="margin: 0; font-size: 12px; color: #666; line-height: 1.5;">تمام اطلاعات محصولات به صورت تکی بروزرسانی می‌شود.</p>
                        </div>
                    </div>
                </div>

                <div style="display: flex; gap: 10px; margin-top: 20px;margin-bottom: 20px;">
                    <button type="button" id="quick-update-btn" class="basalam-primary-button basalam-p" style="height: 20px;flex: 1;padding: 20px 15px;background: var(--basalam-primary-color);display: flex;align-items: center;justify-content: center;border-radius: 8px; transition: all 0.3s ease;">
                        <span style="font-weight: bold; font-size: 15px; color: white;">بروزرسانی فوری</span>
                    </button>

                    <button type="button" id="full-update-btn" class="basalam-primary-button basalam-p" style="height: 20px;flex: 1;padding: 20px 15px;background: #2c3e50;display: flex;align-items: center;justify-content: center;border-radius: 8px; transition: all 0.3s ease;">
                        <span style="font-weight: bold; font-size: 15px; color: white;">بروزرسانی کامل</span>
                    </button>
                </div>
            </div>
        <?php elseif ($quick_update_job || $quick_update_processing_job): ?>
            <!-- Display when quick update job (sync_basalam_update_all_products) is running -->
            <div id="quick-update-in-progress" style="display:block;margin:10px">
                <div style="background: #fff3cd; padding: 20px; border-radius: 8px; margin-top: 15px; border: 1px solid #ffc107;" class="basalam-p">
                    <h4 class="basalam-h" style="margin: 0 0 15px 0; font-size: 16px; color: #856404;">
                        ⚠️ بروزرسانی فوری در حال اجرا است
                    </h4>

                    <p style="margin: 0 0 10px 0; font-size: 14px; color: #856404;">
                        <strong>نوع عملیات:</strong> بروزرسانی فوری قیمت و موجودی
                    </p>

                    <p style="margin: 0 0 10px 0; font-size: 14px; color: #856404;">
                        <strong>وضعیت:</strong>
                        <span style="background: #ffc107; color: #000; padding: 2px 8px; border-radius: 4px; font-weight: bold;">
                            <?php echo ($quick_update_processing_job ? 'در حال پردازش' : 'در انتظار'); ?>
                        </span>
                    </p>

                    <?php if ($count_quick_update_batches > 0): ?>
                        <p style="margin: 0 0 10px 0; font-size: 14px; color: #856404;">
                            <strong>تسک‌های باقی‌مانده:</strong>
                            <span style="background: #ffc107; color: #000; padding: 2px 8px; border-radius: 4px; font-weight: bold;">
                                <?php echo $count_quick_update_batches; ?> عدد
                            </span>
                        </p>
                    <?php endif; ?>

                    <p style="margin: 10px 0 0 0; font-size: 13px; color: #856404;">
                        لطفاً تا پایان عملیات جاری صبر کنید. می‌توانید پیشرفت را از صفحه لاگ‌ها مشاهده کنید.
                    </p>
                </div>

                <!-- Disabled buttons -->
                <div style="display: flex; gap: 10px; margin-top: 20px; margin-bottom: 10px; opacity: 0.5;">
                    <button type="button" disabled class="basalam-primary-button basalam-p" style="height: 20px;flex: 1;padding: 20px 15px;background: #ccc;display: flex;align-items: center;justify-content: center;border-radius: 8px; cursor: not-allowed;">
                        <span style="font-weight: bold; font-size: 15px; color: white;">بروزرسانی فوری</span>
                    </button>

                    <button type="button" disabled class="basalam-primary-button basalam-p" style="height: 20px;flex: 1;padding: 20px 15px;background: #ccc;display: flex;align-items: center;justify-content: center;border-radius: 8px; cursor: not-allowed;">
                        <span style="font-weight: bold; font-size: 15px; color: white;">بروزرسانی کامل</span>
                    </button>
                </div>

                <!-- Cancel button -->
                <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')) ?>" id="BasalamCancelUpdateJobs">
                    <?php wp_nonce_field('cancel_update_jobs_nonce', '_wpnonce'); ?>
                    <input type="hidden" name="action" value="cancel_update_jobs">
                    <button type="submit" class="basalam-primary-button basalam-p" style="width: 100%; background-color: #dc3545 !important;">
                        <img style="width: 20px; vertical-align: middle; margin-left: 5px;" src="<?php echo esc_url(sync_basalam_configure()->assets_url() . '/icons/trash.svg'); ?>">
                        متوقف کردن عملیات
                    </button>
                </form>
            </div>
        <?php elseif ($has_active_update_jobs): ?>
            <!-- Display when jobs are running -->
            <div id="active-jobs-info" style="display:block;margin:10px">
                <div style="background: #fff3cd; padding: 20px; border-radius: 8px; margin-top: 15px; border: 1px solid #ffc107;" class="basalam-p">
                    <h4 class="basalam-h" style="margin: 0 0 15px 0; font-size: 16px; color: #856404;">
                        ⚠️ عملیات بروزرسانی در حال اجرا است
                    </h4>

                    <?php if ($active_update_type === 'quick'): ?>
                        <p style="margin: 0 0 10px 0; font-size: 14px; color: #856404;">
                            <strong>نوع عملیات:</strong> بروزرسانی فوری قیمت و موجودی
                        </p>
                    <?php elseif ($active_update_type === 'full'): ?>
                        <p style="margin: 0 0 10px 0; font-size: 14px; color: #856404;">
                            <strong>نوع عملیات:</strong> بروزرسانی کامل اطلاعات محصولات
                        </p>
                    <?php endif; ?>

                    <?php if ($single_update_count > 0): ?>
                        <p style="margin: 0 0 10px 0; font-size: 14px; color: #856404;">
                            <strong>تعداد محصولات در صف:</strong>
                            <span style="background: #ffc107; color: #000; padding: 2px 8px; border-radius: 4px; font-weight: bold;">
                                <?php echo $single_update_count; ?> محصول
                            </span>
                        </p>
                    <?php endif; ?>

                    <p style="margin: 10px 0 0 0; font-size: 13px; color: #856404;">
                        لطفاً تا پایان عملیات جاری صبر کنید. می‌توانید پیشرفت را از صفحه لاگ‌ها مشاهده کنید.
                    </p>
                </div>

                <!-- Disabled buttons -->
                <div style="display: flex; gap: 10px; margin-top: 20px; margin-bottom: 10px; opacity: 0.5;">
                    <button type="button" disabled class="basalam-primary-button basalam-p" style="height: 20px;flex: 1;padding: 20px 15px;background: #ccc;display: flex;align-items: center;justify-content: center;border-radius: 8px; cursor: not-allowed;">
                        <span style="font-weight: bold; font-size: 15px; color: white;">بروزرسانی فوری</span>
                    </button>

                    <button type="button" disabled class="basalam-primary-button basalam-p" style="height: 20px;flex: 1;padding: 20px 15px;background: #ccc;display: flex;align-items: center;justify-content: center;border-radius: 8px; cursor: not-allowed;">
                        <span style="font-weight: bold; font-size: 15px; color: white;">بروزرسانی کامل</span>
                    </button>
                </div>

                <!-- Cancel button for new job system -->
                <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')) ?>" id="BasalamCancelUpdateJobs">
                    <?php wp_nonce_field('cancel_update_jobs_nonce', '_wpnonce'); ?>
                    <input type="hidden" name="action" value="cancel_update_jobs">
                    <button type="submit" class="basalam-primary-button basalam-p" style="width: 100%; background-color: #dc3545 !important;">
                        <img style="width: 20px; vertical-align: middle; margin-left: 5px;" src="<?php echo esc_url(sync_basalam_configure()->assets_url() . '/icons/trash.svg'); ?>">
                        متوقف کردن عملیات
                    </button>
                </form>
            </div>
        <?php elseif ($inProgress): ?>
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