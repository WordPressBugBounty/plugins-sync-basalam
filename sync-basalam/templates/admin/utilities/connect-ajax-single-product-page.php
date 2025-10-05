<?php
if (! defined('ABSPATH')) exit;
function sync_basalam_handle_connect_product_ajax()
{
    if (!current_user_can('manage_woocommerce')) {
        return [
            'success' => false,
            'message' =>  'تنها مدیر کل امکان تغییر وضعیت سفارش را دارد.',
            'status_code' => 400
        ];
    }

    $woo_product_id = isset($_POST['woo_product_id']) ? intval($_POST['woo_product_id']) : '';
    $sync_basalam_product_id = isset($_POST['basalam_product_id']) ? sanitize_text_field(wp_unslash($_POST['basalam_product_id'])) : '';

    if (!$woo_product_id || !$sync_basalam_product_id) {
        return [
            'success' => false,
            'message' =>  'داده های ورودی ناقض است.',
            'status_code' => 400
        ];
    }

    $connect_product_service = new Sync_basalam_connect_product_service;
    $connect_status = $connect_product_service->connect_product_by_id($woo_product_id, $sync_basalam_product_id);

    $job_manager = new SyncBasalamJobManager();
    $job_manager->create_job(
        'sync_basalam_update_single_product',
        'pending',
        $woo_product_id,
    );

    if ($connect_status) {
        return [
            'success' => true,
            'message' =>  'اتصال محصولات با موفقیت انجام شد.',
            'status_code' => 200
        ];
    } else {
        return [
            'success' => false,
            'message' =>  'این محصول به محصول دیگری متصل شده است.',
            'status_code' => 400
        ];
    }
}

function sync_basalam_handle_search_products_ajax()
{
    if (!current_user_can('edit_posts')) {
        wp_send_json_error("دسترسی غیرمجاز.");
    }

    $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';

    if (empty($title)) {
        echo '<p>عنوان محصول وارد نشده است.</p>';
        wp_die();
    }


    $checker = new sync_basalam_Auto_Connect_Products();
    $products = $checker->check_same_product($title, 1);
    $productId = isset($_POST['woo_product_id']) ? intval($_POST['woo_product_id']) : '';

    if (!empty($products)) {
        foreach ($products as $product) {
?>
            <div class="basalam-product-card basalam-p">
                <img class="basalam-product-image" src="<?php echo esc_url($product['photo']); ?>" alt="<?php echo esc_attr($product['title']); ?>">
                <div class="basalam-product-details">
                    <h2 class="basalam-product-title basalam-h"><?php echo esc_html($product['title']); ?></h2>
                    <p class="basalam-product-id">شناسه محصول: <?php echo esc_html($product['id']); ?></p>
                    <p class="basalam-product-price"><strong>قیمت: <?php echo number_format($product['price']) . ' ریال</strong>'; ?></p>
                </div>
                <button
                    class="basalam-button basalam-button-single-product-page basalam-p basalam-a basalam-connect-btn"
                    data-basalam-product-id="<?php echo esc_attr($product['id']); ?>"
                    data-_wpnonce="<?php echo esc_attr(wp_create_nonce('basalam_connect_product_nonce')); ?>"
                    data-woo-product-id="<?php echo esc_attr($productId) ?>">
                    اتصال
                </button>
            </div>
<?php
        }
    } else {
        echo '<p class="basalam--no-match">محصولی با این عنوان پیدا نشد.</p>';
    }
    wp_die();
}
