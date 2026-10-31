<?php

defined('ABSPATH') || exit;
?>
<aside class="notice notice-info woosalam-salampay-promotion" dir="rtl" aria-label="<?php esc_attr_e('فعال‌سازی سلام‌پی', 'sync-basalam'); ?>">
    <img class="woosalam-salampay-promotion__logo" src="<?php echo esc_url(\SyncBasalam\SalamPay\Module::assetUrl('logo.svg')); ?>" alt="<?php esc_attr_e('سلام‌پی', 'sync-basalam'); ?>" width="90" height="32">
    <p><?php esc_html_e('می‌توانید درگاه پرداخت سلام‌پی را برای فروشگاه خود در ووسلام فعال کنید. برای شروع، از طریق لینک فعال‌سازی اقدام کنید.', 'sync-basalam'); ?></p>
    <div class="woosalam-salampay-promotion__actions">
        <a class="button button-primary" href="<?php echo esc_url($activationUrl); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('درخواست فعال‌سازی سلام‌پی', 'sync-basalam'); ?></a>
        <button type="submit" form="woosalam-salampay-dismiss" class="button" title="<?php esc_attr_e('این اعلان برای ۱۰ روز پنهان می‌شود.', 'sync-basalam'); ?>"><?php esc_html_e('دیگر نمایش نده', 'sync-basalam'); ?></button>
    </div>
</aside>
