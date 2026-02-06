<?php defined('ABSPATH') || exit; ?>
<div id="BasalamConnectProductsModal" class="basalam-modal basalam-hidden">
    <div class="basalam-modal-content basalam-max-width-400">
        <span class="basalam-modal-close">
            <img class="basalam-img-20" src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/icons/close.svg'); ?>">
        </span>

        <h3 class="basalam-h basalam-font-24-important">اتصال اتوماتیک محصولات با باسلام</h3>

        <p class="basalam-p basalam-padding-10-0">
            اتصال محصول تنها در صورتی انجام میشود که عنوان دو محصول در باسلام و ووکامرس دقیقا یکسان باشند.
        </p>

        <form method="POST" action="" id="BasalamConnectProductsForm" class="<?php echo esc_html($auto_connect_product_job_exist); ?>">
            <?php wp_nonce_field('connect_products_with_basalam_nonce', '_wpnonce'); ?>
            <button type="submit"
                class="basalam-primary-button basalam-p basalam-product-action-button basalam-dynamic-cursor"
                <?php echo esc_html($auto_connect_product_job_exist) ? 'disabled' : ''; ?>>
                اتصال خودکار محصولات
            </button>
        </form>

        <?php if ($auto_connect_product_job_exist): ?>
            <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')) ?>">
                <?php wp_nonce_field('cancel_connect_products_with_basalam_nonce', '_wpnonce'); ?>
                <input type="hidden" name="action" value="cancel_connect_products_with_basalam">
                <button type="submit" class="basalam-primary-button basalam-p basalam-width-red-bg">
                    <img class="basalam-img-20" src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/icons/trash.svg'); ?>">
                    متوقف کردن فرایند
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>