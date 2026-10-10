<?php

defined('ABSPATH') || exit;
?>
<div class="notice <?php echo esc_attr($notice['class']); ?>" role="alert" dir="rtl">
    <p><strong><?php echo esc_html($notice['title']); ?></strong></p>
    <p><?php echo esc_html($notice['message']); ?></p>
    <p><?php echo esc_html($notice['guidance']); ?></p>
    <?php if ($notice['job_id']): ?>
        <p><?php echo esc_html('شناسهٔ کار متوقف‌شده: ' . $notice['job_id']); ?></p>
    <?php endif; ?>
    <p><a href="<?php echo esc_url(admin_url('admin.php?page=sync_basalam_logs')); ?>">مشاهدهٔ لاگ‌های همگام‌سازی</a></p>
    <?php if (strpos($notice['guidance'], 'noabort') !== false): ?>
        <details>
            <summary>راهنمای فنی برای پشتیبانی هاست</summary>
            <p>پیش از تغییر، از فایل نسخهٔ پشتیبان تهیه کنید. این قاعده را پیش از قواعد دیگر قرار دهید:</p>
            <pre dir="ltr" style="overflow:auto"><code><?php echo esc_html('# BEGIN SyncBasalam Async
<IfModule LiteSpeed>
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteCond %{REQUEST_URI} /wp-admin/admin-ajax\.php$
RewriteCond %{QUERY_STRING} (^|&)action=sync_basalam_run_jobs_async(&|$)
RewriteRule .* - [E=noabort:1]
</IfModule>
</IfModule>
# END SyncBasalam Async'); ?></code></pre>
        </details>
    <?php endif; ?>
</div>
