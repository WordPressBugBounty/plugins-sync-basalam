<?php
use SyncBasalam\Services\MonitoringDashboardView as View;
defined('ABSPATH') || exit;
?>
<form id="monitoring-plan-form"><div class="monitoring-plans">
<?php foreach ($data['data'] as $plan): ?>
<label class="monitoring-card monitoring-plan"><input type="radio" name="plan" value="<?php echo esc_attr($plan['code'] ?? ''); ?>"><span><?php echo esc_html($plan['title'] ?? ''); ?></span><strong><?php echo esc_html(View::number($plan['price_toman'] ?? null)); ?> <small>تومان</small></strong><span>برای <?php echo esc_html(View::number($plan['period_days'] ?? null)); ?> روز</span><p>تا <?php echo esc_html(View::number($plan['product_limit'] ?? null)); ?> واحد متصل</p><p>پایش قیمت و موجودی، اصلاح خودکار و تاریخچهٔ تغییرات</p></label>
<?php endforeach; ?>
</div><p id="monitoring-plan-selection" role="status">پلن متناسب با ظرفیت فروشگاه را انتخاب کنید.</p>
<p class="monitoring-caption">هر دوره ۳۰ روز است. تمدید با همان پلن، ۳۰ روز به زمان باقی‌مانده اضافه می‌کند. برداشت خودکار نداریم و تغییر پلن در دورهٔ فعال امکان‌پذیر نیست.</p>
<label class="monitoring-consent"><input type="checkbox" name="accepted" value="1" required> شرایط دورهٔ ۳۰روزه را می‌پذیرم؛ ووکامرس مرجع قیمت و موجودی است و در دورهٔ تازه اصلاح خودکار فعال می‌شود. تمدید دورهٔ فعال، توقف دستی اصلاح را حفظ می‌کند.</label>
<p class="monitoring-caption">پیش از پرداخت، اتصال و ظرفیت بررسی می‌شود. مبلغ فاکتور، هزینهٔ اشتراک است؛ مبلغ نهایی و کارمزد احتمالی در صفحهٔ پرداخت باسلام نمایش داده می‌شود.</p>
<button type="submit" class="monitoring-button monitoring-button--primary">بررسی فروشگاه و ساخت فاکتور</button></form>
