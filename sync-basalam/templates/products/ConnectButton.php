<?php

use SyncBasalam\Admin\Product\Operations\ConnectProduct;

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
                $connectProduct = new ConnectProduct();
                $current_product = get_post();
                $productId = isset($_POST['woo_product_id']) ? intval($_POST['woo_product_id']) : ($current_product ? $current_product->ID : 0);

                if ($productId > 0) {
                    $connectProduct->renderProductsByTitle((string) get_the_title($productId), $productId);
                } else {
                    echo '<p class="basalam--no-match">محصول مشابهی یافت نشد.</p>';
                }
                ?>
            </div>
        </div>
    </div>
</div>
