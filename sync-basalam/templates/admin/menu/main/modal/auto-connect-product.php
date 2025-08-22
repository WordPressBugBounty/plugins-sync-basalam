<?php
if (! defined('ABSPATH')) exit;
?>
<div id="BasalamConnectProductsModal" class="basalam-modal" style="display:none;">
    <div class="basalam-modal-content" style="max-width: 400px;">
        <span class="basalam-modal-close">
            <img style="width: 20px;" src="<?php echo esc_url(sync_basalam_configure()->assets_url() . '/icons/close.svg'); ?>">
        </span>

        <h3 class="basalam-h" style="font-size:20px; margin-top: 15px !important;">اتصال اتوماتیک محصولات با باسلام</h3>

        <p class="basalam-p" style="padding: 10px 0;font-size: 14px;text-align: justify;">
            اتصال محصول تنها در صورتی انجام میشود که عنوان دو محصول در باسلام و ووکامرس دقیقا یکسان باشند.
        </p>
        <?php if ($auto_connect_product_job_exist_status): ?>
            <p class="basalam-p" style="padding: 10px 0;font-size: 14px;text-align: justify;">
            </p>
        <?php endif; ?>

        <form method="POST" action="" id="BasalamConnectProductsForm" class="<?php echo esc_html($auto_connect_product_job_exist); ?>">
            <?php wp_nonce_field('connect_products_with_basalam_nonce', '_wpnonce'); ?>
            <button type="submit"
                class="basalam-primary-button basalam-p basalam-product-action-button"
                style=" <?php echo esc_html($auto_connect_product_job_exist) ? 'cursor:not-allowed;margin-bottom:0 !important;' : ''; ?>"
                <?php echo esc_html($auto_connect_product_job_exist) ? 'disabled' : ''; ?>>
                اتصال خودکار محصولات
            </button>
        </form>

        <?php if ($auto_connect_product_job_exist_status): ?>
            <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')) ?>">
                <?php wp_nonce_field('cancel_connect_products_with_basalam_nonce', '_wpnonce'); ?>
                <input type="hidden" name="action" value="cancel_connect_products_with_basalam">
                <button type="submit" class="basalam-primary-button basalam-p" style="width: -webkit-fill-available; background-color: red !important;margin-top:10px !important;margin-bottom: 10px !important;">
                    <img style="width: 20px;" src="<?php echo esc_url(sync_basalam_configure()->assets_url() . '/icons/trash.svg'); ?>">
                    متوقف کردن فرایند 
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>