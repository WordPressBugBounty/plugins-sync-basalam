<?php
use SyncBasalam\Services\MonitoringCheckoutClient as Checkout;
use SyncBasalam\Services\MonitoringDashboardView as View;
defined('ABSPATH') || exit;
$state = $payment['status'] ?? 'pending';
if ($state === 'none') return;
$active = !empty($payment['activated']);
$title = $active ? 'اشتراک فعال شد' : ($state === 'succeeded' ? 'پرداخت تأیید شد؛ فعال‌سازی در حال پیگیری است' :
    ($state === 'failed' ? 'این پرداخت ناموفق بود' : 'پرداخت در انتظار نتیجهٔ قطعی است'));
$key = $payment['idempotency_key'] ?? '';
$url = $payment['payment_url'] ?? null;
?>
<article class="monitoring-card monitoring-payment" data-payment-key="<?php echo esc_attr(Checkout::uuid($key) ? $key : ''); ?>" data-payment-state="<?php echo esc_attr($state); ?>" data-activated="<?php echo $active ? '1' : '0'; ?>">
<h3><?php echo esc_html($title); ?></h3>
<?php if (!empty($payment['sandbox'])): ?><p class="monitoring-notice">پرداخت آزمایشی — پول واقعی جابه‌جا نمی‌شود.</p><?php endif; ?>
<dl class="monitoring-payment-amounts">
<div><dt>مبلغ اشتراک</dt><dd><?php echo esc_html(View::number(isset($payment['amount']) ? $payment['amount'] / 10 : null, 1)); ?> تومان</dd></div>
</dl>
<p class="monitoring-caption">مبلغ نهایی و کارمزد احتمالی در صفحهٔ پرداخت باسلام نمایش داده می‌شود.</p>
<?php if ($active): ?><p>پایان دوره: <?php echo View::date($payment['subscription']['expires_at'] ?? null); ?></p>
<?php elseif ($state === 'succeeded'): ?><p>نیازی به پرداخت دوباره نیست. نتیجهٔ فعال‌سازی از همین صفحه قابل پیگیری است.</p>
<?php elseif (!empty($payment['needs_review'])): ?><p>نتیجهٔ پرداخت به بررسی پشتیبانی نیاز دارد. با شناسهٔ زیر پیگیری کنید و دوباره پرداخت نکنید.</p>
<?php elseif (!empty($payment['expired'])): ?><p>زمان لینک پرداخت تمام شده است؛ ابتدا نتیجهٔ همین فاکتور را بررسی کنید.</p>
<?php elseif ($state === 'pending'): ?><p>پس از پرداخت، نتیجه از سرویس پرداخت بررسی می‌شود؛ بسته‌شدن صفحه مانع پیگیری نیست.</p><?php endif; ?>
<p class="monitoring-caption">شناسهٔ پیگیری: <bdi dir="ltr"><?php echo esc_html($key); ?></bdi></p>
<?php if ($state === 'pending' && empty($payment['expired']) && empty($payment['needs_review']) && Checkout::validPaymentUrl($url)): ?>
<a class="monitoring-button monitoring-button--primary" href="<?php echo esc_url($url); ?>" rel="noreferrer">ادامهٔ پرداخت در باسلام</a>
<?php endif; ?>
<button type="button" class="monitoring-button" data-action="purchase-check">بررسی نتیجهٔ پرداخت</button>
<?php if ($active || $state === 'failed'): ?><button type="button" class="monitoring-button" data-action="purchase-new"><?php echo $active ? 'شروع تمدید جدید' : 'شروع خرید جدید'; ?></button><?php endif; ?>
</article>
