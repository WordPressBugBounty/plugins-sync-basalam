<?php

namespace SyncBasalam\Admin\Components;

defined('ABSPATH') || exit;

class SingleProductPageComponents
{
    public static function renderProductCard(array $product, int $productId): void
    {
?>
        <div class="basalam-product-card basalam-p">
            <img class="basalam-product-image" src="<?php echo esc_url($product['photo']['md']); ?>" alt="<?php echo esc_attr($product['title'] ?? ''); ?>">
            <div class="basalam-product-details">
                <p class="basalam-product-title basalam-p"><?php echo esc_html($product['title'] ?? ''); ?></p>
                <p class="basalam-product-id">شناسه محصول: <?php echo esc_html($product['id'] ?? ''); ?></p>
                <p class="basalam-product-price"><strong>قیمت: <?php echo number_format($product['price'] ?? 0) . ' ریال</strong>'; ?></p>
            </div>
            <div class="basalam-product-actions">
                <button
                    class="basalam-button basalam-button-single-product-page basalam-p basalam-a basalam-connect-btn"
                    data-basalam-product-id="<?php echo esc_attr($product['id'] ?? ''); ?>"
                    data-_wpnonce="<?php echo esc_attr(wp_create_nonce('basalam_connect_product_nonce')); ?>"
                    data-woo-product-id="<?php echo esc_attr($productId) ?>">
                    اتصال
                </button>
                <a
                    href="https://basalam.com/p/<?php echo esc_attr($product['id'] ?? ''); ?>"
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
