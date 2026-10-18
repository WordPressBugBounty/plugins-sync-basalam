<?php

if (! defined('ABSPATH')) exit;

/**
 * مجوز عملیات سلام‌گیت ناکافی است (403)، نه الزاماً اتصال باسلام یا توکن نامعتبر.
 * پیام و پیش‌نمایش ثابت‌اند؛ داده حساب و خطای خام API در پشت بلر رندر نمی‌شوند.
 */
$connectionLock = ['surface' => 'salam-gate', 'state' => 'error_forbidden'];
?>
<div class="sg-state sg-state--error-forbidden" data-state="error_forbidden">
    <?php include dirname(__DIR__, 2) . '/Shared/connection-lock.php'; ?>
</div>
