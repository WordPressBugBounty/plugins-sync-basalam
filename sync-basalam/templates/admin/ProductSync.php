<?php
use SyncBasalam\Services\Products\FetchUnsyncProducts;
use SyncBasalam\Admin\Components;

defined('ABSPATH') || exit;

$sync_basalam_sync_status_checker = new FetchUnsyncProducts();

$page = isset($_GET['unsync_page']) ? intval($_GET['unsync_page']) : 1;

$unsync_products = $sync_basalam_sync_status_checker->getUnsyncBasalamProducts($page);

if (!empty($unsync_products)) {
    $data = Components::renderUnsyncBasalamProductsTable($unsync_products);
    echo esc_html($data);
    ?>

    <div class="basalam-pagination basalam-pagination-flex">
        <?php if ($page > 1): ?>
            <a href="/wp-admin/admin.php?page=basalam-show-products&unsync_page=<?php echo esc_html($page) - 1; ?>">قبلی</a>
        <?php endif; ?>
        <a href="/wp-admin/admin.php?page=basalam-show-products&unsync_page=<?php echo esc_html($page + 1); ?>">بعدی</a>
    </div>
<?php
} else echo '<p class="basalam-p">محصولی یافت نشد</p>';