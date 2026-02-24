<?php
use SyncBasalam\Services\Products\FetchUnsyncProducts;
use SyncBasalam\Admin\Components\ProductListComponents;

defined('ABSPATH') || exit;

$sync_basalam_sync_status_checker = new FetchUnsyncProducts();

$cursor = isset($_GET['unsync_cursor']) ? sanitize_text_field(wp_unslash($_GET['unsync_cursor'])) : null;
$history = isset($_GET['unsync_history']) && is_array($_GET['unsync_history'])
    ? array_values(array_filter(array_map('sanitize_text_field', wp_unslash($_GET['unsync_history']))))
    : [];

$hasPrev = false;
$prevCursor = null;
$prevHistory = $history;

if (!empty($history)) {
    $hasPrev = true;
    $prevCursor = end($history);
    array_pop($prevHistory);
} elseif (!empty($cursor)) {
    // If user opens a cursor page directly, previous page is the initial page (without cursor).
    $hasPrev = true;
}

$nextHistory = $history;
if (!empty($cursor)) $nextHistory[] = $cursor;

$nextCursor = null;

$unsync_products = $sync_basalam_sync_status_checker->getUnsyncBasalamProducts($cursor, $nextCursor);

if (!empty($unsync_products)) {
    $data = ProductListComponents::renderUnsyncBasalamProductsTable($unsync_products);
    echo esc_html($data);
    ?>

    <div class="basalam-pagination basalam-pagination-flex">
        <?php if ($hasPrev):
            $prevArgs = ['page' => 'basalam-show-products'];
            if (!empty($prevCursor)) $prevArgs['unsync_cursor'] = $prevCursor;
            if (!empty($prevHistory)) $prevArgs['unsync_history'] = $prevHistory;
        ?>
            <a href="<?php echo esc_url(add_query_arg($prevArgs, admin_url('admin.php'))); ?>">قبلی</a>
        <?php endif; ?>

        <?php if (!empty($nextCursor)): ?>
            <?php
            $nextArgs = ['page' => 'basalam-show-products', 'unsync_cursor' => $nextCursor];
            if (!empty($nextHistory)) $nextArgs['unsync_history'] = $nextHistory;
            ?>
            <a href="<?php echo esc_url(add_query_arg($nextArgs, admin_url('admin.php'))); ?>">بعدی</a>
        <?php endif; ?>
    </div>
<?php
} else echo '<p class="basalam-p">محصولی یافت نشد</p>';
