<?php
if (! defined('ABSPATH')) exit;
?>
<div class="basalam-modal-header">
</div>
<div class="basalam-modal-content">
    <p class="basalam-p">لطفا شناسه محصول باسلام را وارد کنید:</p>
    <input type="number" id="Basalam-connect-product-id" class="basalam-input" style="max-width: -webkit-fill-available;margin-bottom: 10px;">
    <input type="hidden" id="woo-product-id" value="<?php echo esc_attr($product_id); ?>">
    <?php wp_nonce_field('basalam_connect_product_action', 'asalam_connect_product_nonce', true, false) ?>
    <button type="button" id="connect-product-btn" class="basalam-button basalam-button-single-product-page basalam-p basalam-a">اتصال</button>
</div>