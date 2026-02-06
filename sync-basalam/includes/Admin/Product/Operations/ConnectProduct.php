<?php

namespace SyncBasalam\Admin\Product\Operations;

use SyncBasalam\Services\Products\ConnectSingleProductService;
use SyncBasalam\Services\Products\AutoConnectProducts;
use SyncBasalam\JobManager;

defined('ABSPATH') || exit;

class ConnectProduct
{
    public function handleConnectProduct(): array
    {
        if (!current_user_can('manage_woocommerce')) {
            return [
                'success'     => false,
                'message'     => 'تنها مدیر کل امکان تغییر وضعیت سفارش را دارد.',
                'status_code' => 400,
            ];
        }

        $woo_product_id = isset($_POST['woo_product_id']) ? intval($_POST['woo_product_id']) : '';
        $sync_basalam_product_id = isset($_POST['basalam_product_id']) ? sanitize_text_field(wp_unslash($_POST['basalam_product_id'])) : '';

        if (!$woo_product_id || !$sync_basalam_product_id) {
            return [
                'success'     => false,
                'message'     => 'داده های ورودی ناقص است.',
                'status_code' => 400,
            ];
        }

        $connect_status = ConnectSingleProductService::connectProductById($woo_product_id, $sync_basalam_product_id);

        $job_manager = new JobManager();
        $job_manager->createJob(
            'sync_basalam_update_single_product',
            'pending',
            $woo_product_id,
        );

        if ($connect_status) {
            return [
                'success'     => true,
                'message'     => 'اتصال محصولات با موفقیت انجام شد.',
                'status_code' => 200,
            ];
        } else {
            return [
                'success'     => false,
                'message'     => 'این محصول به محصول دیگری متصل شده است.',
                'status_code' => 400,
            ];
        }
    }

    public function handleSearchProducts(): void
    {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error("دسترسی غیرمجاز.");
        }

        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';

        if (empty($title)) {
            echo '<p>عنوان محصول وارد نشده است.</p>';
            wp_die();
        }

        $checker = new AutoConnectProducts();
        $products = $checker->checkSameProduct($title, 1);
        $productId = isset($_POST['woo_product_id']) ? intval($_POST['woo_product_id']) : 0;

        $this->renderProductCards($products, $productId);
        wp_die();
    }

    private function renderProductCards(array $products, int $productId): void
    {
        if (!empty($products)) foreach ($products as $product) $this->renderProductCard($product, $productId);
        else echo '<p class="basalam--no-match">محصولی با این عنوان پیدا نشد.</p>';
    }

    private function renderProductCard(array $product, int $productId): void
    {
?>
        <div class="basalam-product-card basalam-p">
            <img class="basalam-product-image" src="<?php echo esc_url($product['photo']); ?>" alt="<?php echo esc_attr($product['title']); ?>">
            <div class="basalam-product-details">
                <p class="basalam-product-title basalam-p"><?php echo esc_html($product['title']); ?></p>
                <p class="basalam-product-id">شناسه محصول: <?php echo esc_html($product['id']); ?></p>
                <p class="basalam-product-price"><strong>قیمت: <?php echo number_format($product['price']) . ' ریال</strong>'; ?></p>
            </div>
            <div class="basalam-product-actions">
                <button
                    class="basalam-button basalam-button-single-product-page basalam-p basalam-a basalam-connect-btn"
                    data-basalam-product-id="<?php echo esc_attr($product['id']); ?>"
                    data-_wpnonce="<?php echo esc_attr(wp_create_nonce('basalam_connect_product_nonce')); ?>"
                    data-woo-product-id="<?php echo esc_attr($productId) ?>">
                    اتصال
                </button>
                <a
                    href="https://basalam.com/p/<?php echo esc_attr($product['id']); ?>"
                    target="_blank"
                    class="basalam-view-btn"
                    title="مشاهده محصول در باسلام">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z" fill="currentColor" />
                    </svg>
                </a>
            </div>
        </div>
<?php
    }
}
