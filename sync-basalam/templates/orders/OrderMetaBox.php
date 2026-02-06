<div class="basalam-order-info basalam-p">
    <div class="basalam-info-item">
        <span class="basalam-info-label">تعداد سفارشات کاربر:</span>
        <span class="basalam-info-value">
            <span class="basalam-purchase-count"><?php echo esc_html($purchase_count); ?> بار</span>
            از شما خرید کرده
        </span>
    </div>

    <div class="basalam-info-item">
        <span class="basalam-info-label">کارمزد سفارش:</span>
        <span class="basalam-info-value"><?php echo esc_html($fee_formatted); ?></span>
    </div>

    <div class="basalam-info-item">
        <span class="basalam-info-label">مبلغ اضافه شده به تراز:</span>
        <span class="basalam-info-value"><?php echo esc_html($balance_formatted); ?></span>
    </div>

    <div class="basalam-info-item">
        <span class="basalam-info-value" style="display: flex; gap: 7px;">
            <a href="<?php echo esc_url("https://vendor.basalam.com/orders/$invoice_id"); ?>" target="_blank" class="basalam-button" style="height: 30px; border-radius: 2px; font-size: 12px;">
                مشاهده سفارش
            </a>
            <?php if (!empty($hash_id)): ?>
                <a href="<?php echo esc_url("https://vendor.basalam.com/orders/print-post-invoice/$hash_id"); ?>" target="_blank" class="basalam-button" style="height: 30px; border-radius: 2px;font-size: 12px;">
                    مشاهده فاکتور
                </a>
            <?php endif; ?>
        </span>
    </div>
</div>