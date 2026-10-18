<?php

if (! defined('ABSPATH')) exit;

/** @var array $data */

/**
 * احراز هویت رد شده (401): اتصال باید بررسی و در صورت نیاز دوباره برقرار شود.
 * خطای سلام‌گیت اتصال عمومی ووسلام را پاک نمی‌کند؛ کاربر خودش تصمیم می‌گیرد.
 */
$connectionLock = ['surface' => 'salam-gate', 'state' => 'error_auth'];
?>
<div class="sg-state sg-state--error-auth" data-state="error_auth">
    <?php include dirname(__DIR__, 2) . '/Shared/connection-lock.php'; ?>
</div>
