<?php

defined('ABSPATH') || exit;

use SyncBasalam\Services\Products\UnmappedProducts\UnmappedProductsAlert;

if (empty($syncBasalamUnmappedSummary) || !is_array($syncBasalamUnmappedSummary)) return;

$syncBasalamUnmappedCount = (int) $syncBasalamUnmappedSummary['counts']['unresolved'];
?>
<div class="notice notice-warning basalam-p" style="text-align:right;">
    <p>
        <strong>
            ووسلام:
            <?php echo esc_html(number_format_i18n($syncBasalamUnmappedCount)); ?>
            محصول فعال باسلام با ووکامرس همگام نیست.
        </strong>
    </p>
    <p>
        این محصولات در غرفهٔ باسلام شما فعال هستند اما به هیچ محصولی در ووکامرس متصل نیستند؛
        می‌توانید هر کدام را آرشیو کنید، به محصول موجود در ووکامرس متصل کنید یا نادیده بگیرید.
        تا تعیین‌تکلیف همهٔ موارد، بررسی بعدی اجرا نمی‌شود.
    </p>
    <p>
        <a class="button button-primary" href="<?php echo esc_url(UnmappedProductsAlert::pageUrl()); ?>">مشاهده و اقدام</a>
        <a class="button" href="<?php echo esc_url(UnmappedProductsAlert::dismissUrl()); ?>">بعدا یادآوری کن</a>
    </p>
</div>
