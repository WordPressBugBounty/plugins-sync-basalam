<?php
if (! defined('ABSPATH')) exit;
?>
<a id="Basalam-connect-product-btn" class="basalam-button basalam-button-single-product-page basalam-p basalam-a" onclick="openModal()">اتصال به محصول موجود در باسلام</a>

<div id="Basalam-connect-modal" class="basalam-modal">
    <div class="basalam-modal-content">
        <span class="basalam-modal-close" onclick="closeModal()">&times;</span>

        <!-- Search Box -->
        <div class="basalam-search-box">
            <input type="text" id="basalam-product-search" class="basalam-p" value="<?php echo esc_attr(get_the_title()); ?>" placeholder="نام محصول را وارد کنید">
            <input type="hidden" id="Basalam-woo-product-id" value="<?php echo esc_attr(get_the_ID()); ?>">
            <button id="basalam-search-btn" class="basalam-button basalam-p">جستجو</button>
        </div>

        <!-- Results -->
        <div class="basalam-modal-body basalam-p">
            <div id="basalam-product-results" class="basalam-modal-results">
                <?php
                $checker = new sync_basalam_Auto_Connect_Products();
                $current_product = get_post();
                $productId = isset($_POST['woo_product_id']) ? intval($_POST['woo_product_id']) : get_the_id();
                $products = $checker->check_same_product(get_the_title($productId), 1);

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
                    echo '<p class="basalam--no-match">محصول مشابهی یافت نشد.</p>';
                }
                ?>
            </div>
        </div>
    </div>
</div>