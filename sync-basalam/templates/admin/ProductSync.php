<?php

use SyncBasalam\Admin\Components\ProductListComponents;
use SyncBasalam\Services\Products\UnmappedProducts\UnmappedProductsManager;
use SyncBasalam\Services\Products\UnmappedProducts\UnmappedProductsRepository;
use SyncBasalam\Services\Products\UnmappedProducts\UnmappedScanState;

defined('ABSPATH') || exit;

UnmappedProductsRepository::ensureTable();

$sync_basalam_unmapped_manager = new UnmappedProductsManager();

// زنجیرهٔ مرده نباید صفحه را در «در حال بررسی» نگه دارد.
$sync_basalam_unmapped_manager->reconcile();

$sync_basalam_unmapped_vendor = $sync_basalam_unmapped_manager->vendorId();

// اتصال‌هایی که بیرون از این گزارش برقرار شده‌اند، پیش از نمایش بسته می‌شوند.
if ($sync_basalam_unmapped_vendor !== '') {
    $sync_basalam_unmapped_manager->repository()->resolveRowsThatBecameMapped($sync_basalam_unmapped_vendor);
    $sync_basalam_unmapped_manager->releaseLockIfResolved();
}

$sync_basalam_unmapped_summary = $sync_basalam_unmapped_manager->statusSummary();

?>
<div class="basalam-container basalam-unmapped-container" dir="rtl">
    <div class="basalam-dashboard basalam-unmapped-page">
<?php

ProductListComponents::renderUnmappedScanSummary($sync_basalam_unmapped_summary);

$sync_basalam_unmapped_per_page = 20;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination parameter.
$sync_basalam_unmapped_page = isset($_GET['unmapped_page']) ? max(1, absint($_GET['unmapped_page'])) : 1;

$sync_basalam_unmapped_total = max(0, (int) $sync_basalam_unmapped_summary['counts']['total'] - (int) $sync_basalam_unmapped_summary['counts']['archived']);
$sync_basalam_unmapped_total_pages = (int) ceil($sync_basalam_unmapped_total / $sync_basalam_unmapped_per_page);

$sync_basalam_unmapped_page = min($sync_basalam_unmapped_page, max(1, $sync_basalam_unmapped_total_pages));

$sync_basalam_unmapped_rows = $sync_basalam_unmapped_vendor !== ''
    ? $sync_basalam_unmapped_manager->repository()->paginate($sync_basalam_unmapped_vendor, $sync_basalam_unmapped_page, $sync_basalam_unmapped_per_page)
    : [];

if ($sync_basalam_unmapped_summary['status'] === UnmappedScanState::STATUS_SCANNING) {
    // پیشرفت زندهٔ پیمایش داخل همان کارت وضعیت نمایش داده می‌شود؛ فهرست بعد از پایان می‌آید.
} elseif (!empty($sync_basalam_unmapped_rows) || (int) $sync_basalam_unmapped_summary['counts']['total'] > 0) {
    ProductListComponents::renderUnmappedProductsTable(
        $sync_basalam_unmapped_rows,
        ($sync_basalam_unmapped_page - 1) * $sync_basalam_unmapped_per_page,
        $sync_basalam_unmapped_page,
        $sync_basalam_unmapped_total_pages
    );

    ProductListComponents::renderUnmappedPagination($sync_basalam_unmapped_page, $sync_basalam_unmapped_total_pages);
} else {
    echo '<section class="basalam-status-card basalam-unmapped-empty">'
        . '<span class="dashicons dashicons-archive" aria-hidden="true"></span>'
        . '<h2 class="basalam-h">محصول متصل‌نشده‌ای ثبت نشده است</h2><p class="basalam-p">'
        . ((int) $sync_basalam_unmapped_summary['finished_at'] > 0
            ? 'در آخرین بررسی، همهٔ محصولات فعال غرفه با ووکامرس همگام بودند.'
            : 'برای شروع، روی «بررسی محصولات غرفه» بزنید.')
        . '</p></section>';
}

echo '</div></div>';
?>

<!-- مودال اتصال محصول باسلام به محصول موجود ووکامرس -->
<div id="BasalamUnmappedConnectModal" class="basalam-modal">
    <div class="basalam-modal-content basalam-max-width-400">
        <span class="basalam-modal-close" id="basalam-unmapped-connect-close">
            <img class="basalam-img-20" src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/icons/close.svg'); ?>" alt="بستن">
        </span>

        <h3 class="basalam-h basalam-font-24-important">اتصال به محصول ووکامرس</h3>

        <p class="basalam-p basalam-padding-10-0">
            محصول باسلام:
            <strong id="basalam-unmapped-connect-product-title"></strong>
        </p>

        <input type="text" id="basalam-unmapped-connect-search" class="basalam-input basalam-input-max-width"
            placeholder="عنوان محصول ووکامرس را جستجو کنید...">
        <button type="button" id="basalam-unmapped-connect-search-btn" class="basalam-primary-button basalam-p">جستجو</button>

        <div id="basalam-unmapped-connect-results" class="basalam-modal-results"></div>
    </div>
</div>

<!-- مودال تایید آرشیو گروهی -->
<div id="BasalamUnmappedArchiveAllModal" class="basalam-modal">
    <div class="basalam-modal-content basalam-max-width-400">
        <span class="basalam-modal-close" id="basalam-unmapped-archive-all-close">
            <img class="basalam-img-20" src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/icons/close.svg'); ?>" alt="بستن">
        </span>

        <h3 class="basalam-h basalam-font-24-important">آرشیو همه محصولات متصل‌نشده</h3>

        <p class="basalam-p basalam-padding-10-0">
            همهٔ محصولات «در انتظار اقدام» این گزارش در باسلام آرشیو می‌شوند و از دید خریداران خارج خواهند شد.
            این عملیات به‌صورت تدریجی در پس‌زمینه انجام می‌شود و هر محصول بعدا از باسلام قابل خارج‌کردن از آرشیو است.
        </p>

        <button type="button" id="basalam-unmapped-archive-all-confirm" class="basalam-primary-button basalam-p basalam-width-red-bg">
            بله، همه را آرشیو کن
        </button>
    </div>
</div>
