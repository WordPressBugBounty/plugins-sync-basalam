<?php
if (! defined('ABSPATH')) exit;
$sync_basalam_sync_status_checker = new sync_basalam_Check_Unsync_Basalam_Products();

$page = isset($_GET['unsync_page']) ? intval($_GET['unsync_page']) : 1;

$unsync_products = $sync_basalam_sync_status_checker->get_unsync_basalam_products($page);

if (!empty($unsync_products)) {
    $data = sync_basalam_Admin_UI::render_unsync_basalam_products_table($unsync_products);
    echo esc_html($data);
?>

    <div class="basalam-pagination" style="display: flex;flex-direction: row;justify-content: center;align-items: center;">
        <?php if ($page > 1): ?>
            <a href="/wp-admin/admin.php?page=basalam-show-products&unsync_page=<?php echo (esc_html($page) - 1); ?>">قبلی</a>
        <?php endif; ?>
        <a href="/wp-admin/admin.php?page=basalam-show-products&unsync_page=<?php echo esc_html($page + 1); ?>">بعدی</a>
    </div>
<?php
} else {
    echo '<p class="basalam-p">محصولی یافت نشد</p>';
}
