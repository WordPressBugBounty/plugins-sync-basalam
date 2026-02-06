<?php

use SyncBasalam\Services\Products\AutoConnectProducts;

defined('ABSPATH') || exit;
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
                $checker = new AutoConnectProducts();
                $current_product = get_post();
                $productId = isset($_POST['woo_product_id']) ? intval($_POST['woo_product_id']) : ($current_product ? $current_product->ID : 0);

                if ($productId > 0) {
                    $products = $checker->checkSameProduct(get_the_title($productId), 1);
                } else $products = [];

                if (!empty($products)) {
                    foreach ($products as $product) {
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
                                        <path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z" fill="currentColor"/>
                                    </svg>
                                </a>
                            </div>
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