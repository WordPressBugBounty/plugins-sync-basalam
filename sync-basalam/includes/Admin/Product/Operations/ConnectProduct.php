<?php

namespace SyncBasalam\Admin\Product\Operations;

use SyncBasalam\Services\Products\ConnectSingleProductService;
use SyncBasalam\Services\Products\AutoConnectProducts;
use SyncBasalam\JobManager;
use SyncBasalam\Admin\Components\SingleProductPageComponents;

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

        $productId = isset($_POST['woo_product_id']) ? intval($_POST['woo_product_id']) : 0;
        $this->renderProductsByTitle($title, $productId);
        wp_die();
    }

    public function renderProductsByTitle(string $title, int $productId): void
    {
        $products = $this->getProductsByTitle($title);
        $this->renderProductCards($products, $productId);
    }

    private function getProductsByTitle(string $title): array
    {
        $title = trim($title);
        if ($title === '') return [];

        $checker = new AutoConnectProducts();
        $result = $checker->checkSameProduct($title);

        return is_array($result) && !isset($result['error']) ? $result : [];
    }

    private function renderProductCards(array $products, int $productId): void
    {
        if (!empty($products)) {
            foreach ($products as $product) {
                if (!is_array($product)) continue;
                SingleProductPageComponents::renderProductCard($product, $productId);
            }
        } else {
            echo '<p class="basalam--no-match">محصولی با این عنوان پیدا نشد.</p>';
        }
    }
}
