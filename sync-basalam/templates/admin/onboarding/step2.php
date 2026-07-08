<?php

defined('ABSPATH') || exit;

?>
<div class="step-content">
    <div>
        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
            <input type="hidden" name="action" value="basalam_update_setting">
            <input type="hidden" name="get_token" value="1">
            <?php wp_nonce_field('basalam_update_setting_nonce', '_wpnonce'); ?>
            <button type="submit" class="basalam-primary-button basalam-p basalam-a">
                <img src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/icons/create.svg'); ?>" alt="">
                دریافت دسترسی از باسلام
            </button>
        </form>
    </div>
    <div class="step-instructions">
        <ol>
            <li>با کلیک روی گزینه دریافت دسترسی از باسلام به باسلام هدایت خواهید شد و با کلیک روی گزینه دسترسی میدهم ، فرایند دریافت دسترسی انجام خواهد شد.</li>
        </ol>
    </div>
</div>