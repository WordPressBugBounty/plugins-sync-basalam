<?php

defined('ABSPATH') || exit;

use SyncBasalam\Services\Products\DuplicateConnectionReport;

$syncBasalamDuplicateReport = DuplicateConnectionReport::all();

if (!$syncBasalamDuplicateReport) return;

echo '<div class="notice notice-warning basalam-p" style="text-align:right;">';
echo '<p><strong>ووسلام: چند محصول ووکامرس به یک محصول باسلام متصل بودند.</strong></p>';
echo '<p>این حالت معمولا بعد از کپی گرفتن از یک محصول پیش می‌آید؛ در این وضعیت بروزرسانی محصول کپی شده، محصول اصلی را در باسلام تغییر می‌دهد (برگشتن قیمت قدیمی یا موجود شدن دوباره محصول ناموجود).</p>';
echo '<ul style="list-style:disc;padding-right:20px;">';

foreach ($syncBasalamDuplicateReport as $syncBasalamReportItem) {
    $syncBasalamProductLinks = [];

    foreach ($syncBasalamReportItem['product_ids'] as $syncBasalamProductId) {
        $syncBasalamProductLinks[] = sprintf(
            '<a href="%s" target="_blank">%s (#%d)</a>',
            esc_url(get_edit_post_link($syncBasalamProductId) ?: ''),
            esc_html(get_the_title($syncBasalamProductId) ?: 'محصول حذف شده'),
            (int) $syncBasalamProductId
        );
    }

    if ($syncBasalamReportItem['type'] === 'repaired') {
        $syncBasalamMessage = sprintf(
            'اتصال محصول‌های %s قطع شد و فقط اتصال محصول #%d حفظ شد. اگر محصول اشتباهی حفظ شده است، از باکس «تنظیمات باسلام» در صفحه ویرایش محصول دوباره اتصال را برقرار کنید.',
            implode('، ', $syncBasalamProductLinks),
            (int) $syncBasalamReportItem['kept']
        );
    } else {
        $syncBasalamMessage = sprintf(
            'محصول‌های %s اتصال مشترک دارند و همگام‌سازی آن‌ها تا اصلاح اتصال متوقف شده است. لطفا در صفحه ویرایش محصول کپی شده، دکمه «قطع اتصال محصول» را بزنید.',
            implode('، ', $syncBasalamProductLinks)
        );
    }

    echo '<li>' . wp_kses($syncBasalamMessage, ['a' => ['href' => [], 'target' => []]]) . '</li>';
}

echo '</ul>';
echo '<p><a class="button" href="' . esc_url(DuplicateConnectionReport::dismissUrl()) . '">متوجه شدم</a></p>';
echo '</div>';
