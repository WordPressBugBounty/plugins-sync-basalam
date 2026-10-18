<?php
defined('ABSPATH') || exit;
?>
<div class="sg-order-preview__summary">
    <span class="sg-order-preview__market"><?php echo $market === 'basalam' ? 'باسلام' : 'ووکامرس'; ?></span>
    <span class="sg-order-preview__status" data-tone="<?php echo esc_attr($summaryState['tone']); ?>"><?php echo esc_html($summaryState['label']); ?></span>
    <?php if ($createdDisplay !== '') : ?><time datetime="<?php echo esc_attr($createdIso); ?>"><?php echo esc_html($createdDisplay); ?></time><?php endif; ?>
</div>
<?php if ($readOnlyCancellation) : ?>
<div class="sg-order-preview__notice"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><p><?php echo esc_html($cancellationMessage); ?></p></div>
<?php endif; ?>
<section class="sg-order-preview__recipient" aria-label="مشخصات گیرنده">
    <div class="sg-order-preview__recipient-heading">
        <span class="dashicons dashicons-admin-users" aria-hidden="true"></span>
        <div>
            <h5>مشخصات گیرنده</h5>
            <strong><?php echo esc_html($recipient ?: 'نام گیرنده ثبت نشده'); ?></strong>
        </div>
    </div>
    <dl class="sg-order-preview__recipient-fields">
        <div><dt>استان و شهر</dt><dd><?php echo esc_html($destinationText ?: 'ثبت نشده'); ?></dd></div>
        <?php $phone = $order->get_shipping_phone() ?: $order->get_billing_phone(); ?>
        <div><dt>تلفن</dt><dd><?php if ($phone) : ?><bdi dir="ltr"><?php echo esc_html($phone); ?></bdi><?php else : ?>ثبت نشده<?php endif; ?></dd></div>
        <div><dt>کد پستی</dt><dd><?php if (!empty($address['postcode'])) : ?><bdi dir="ltr"><?php echo esc_html($address['postcode']); ?></bdi><?php else : ?>ثبت نشده<?php endif; ?></dd></div>
        <div class="sg-order-preview__address"><dt><span class="dashicons dashicons-location" aria-hidden="true"></span>نشانی کامل</dt><dd><?php echo esc_html($streetAddressText ?: 'آدرس ثبت نشده'); ?></dd></div>
    </dl>
</section>
<section class="sg-order-preview__items" aria-labelledby="sg-order-preview-items-title">
    <h5 id="sg-order-preview-items-title">اقلام سفارش <span><?php echo esc_html(\SyncBasalam\SalamGate\Utils\Digits::toPersian((string) count($items))); ?> قلم</span></h5>
    <ul>
        <?php foreach ($items as $item) : ?>
        <li>
            <span class="sg-order-preview__photo">
                <?php
                $thumbnail = \SyncBasalam\SalamGate\Order\BasalamOrderProducts::thumbnail('', $item->get_id(), $item);
                $product = $item->get_product();
                if ($thumbnail !== '') { echo $thumbnail; } // Escaped by the native thumbnail renderer.
                elseif ($product && $product->get_sku() !== 'placeholder-basalam-product' && $product->get_image_id()) {
                    echo wp_get_attachment_image($product->get_image_id(), 'thumbnail', false, ['alt' => $item->get_name(), 'loading' => 'lazy']);
                } else { echo '<span class="dashicons dashicons-archive" aria-hidden="true"></span>'; }
                ?>
            </span>
            <div class="sg-order-preview__item-name"><strong><?php echo esc_html($item->get_name()); ?></strong><span><?php echo esc_html(\SyncBasalam\SalamGate\Utils\Digits::toPersian((string) $item->get_quantity())); ?> عدد</span></div>
            <bdi class="sg-order-preview__item-price"><?php echo esc_html($money((float) $item->get_total() + (float) $item->get_total_tax())); ?></bdi>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
<dl class="sg-order-preview__totals">
    <?php if ($order->get_shipping_method()) : ?><div><dt>روش ارسال انتخاب‌شده توسط کاربر</dt><dd><?php echo esc_html($order->get_shipping_method()); ?></dd></div><?php endif; ?>
    <?php if ((float) $order->get_discount_total() > 0) : ?><div><dt>تخفیف سفارش</dt><dd><?php echo esc_html($money($order->get_discount_total())); ?></dd></div><?php endif; ?>
    <div class="sg-order-preview__grand-total"><dt>مبلغ نهایی سفارش</dt><dd><?php echo esc_html($money($order->get_total())); ?></dd></div>
</dl>
