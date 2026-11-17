<?php
use SyncBasalam\Services\MonitoringDashboardView as View;
defined('ABSPATH') || exit;
$available = is_array($result['data'] ?? null);
$sub = $result['data']['subscription'] ?? [];
$products = is_array($sub['products'] ?? null) ? $sub['products'] : [];
$summary = $products['summary'] ?? [];
$benefits = $products['benefits'] ?? [];
$aggregate = $products['history']['aggregate'] ?? [];
$plan = $sub['plan'] ?? [];
$active = !empty($sub['active']);
$repair = !empty($products['auto_repair_enabled']);
$stale = !empty($result['stale']);
$canChange = $available && !$stale && $active;
$units = $sub['connected_units'] ?? null;
$limit = $plan['product_limit'] ?? null;
$scanCompleted = $products['scan_completed_at'] ?? null;
$oldScan = is_string($scanCompleted) && strtotime($scanCompleted) && time() - strtotime($scanCompleted) > 1800;
?>
<div class="monitoring-meta" data-active="<?php echo $active ? '1' : '0'; ?>" data-plan="<?php echo esc_attr($plan['code'] ?? ''); ?>">
    <span class="monitoring-pill monitoring-pill--<?php echo $stale ? 'warning' : ($available ? 'success' : 'neutral'); ?>"><?php echo $stale ? 'گزارش ذخیره‌شده' : ($available ? 'گزارش دریافت‌شده' : 'در انتظار گزارش'); ?></span>
    <span>آخرین دریافت: <?php echo !empty($result['fetched_at']) ? View::date(gmdate('c', $result['fetched_at'])) : '—'; ?></span>
</div>
<?php if (!empty($result['error'])): ?><p class="monitoring-notice" role="status"><?php echo esc_html(View::error($result['error'])); ?></p><?php endif; ?>
<section id="monitoring-pane-performance" class="monitoring-pane" role="tabpanel" aria-labelledby="monitoring-tab-performance">
<?php if (!$available): ?>
    <div class="monitoring-empty"><h2>گزارش پایش فروشگاه</h2><p><?php echo empty($result['connected']) ? 'برای شروع، اتصال غرفه را در صفحهٔ اصلی ووسلام کامل کنید.' : 'گزارش پس از دریافت از سرویس پایش در همین بخش نمایش داده می‌شود.'; ?></p></div>
<?php else: ?>
    <div class="monitoring-section-heading"><div><p>آخرین دور پایش</p><h2>وضعیت قیمت و موجودی</h2></div><span class="monitoring-pill monitoring-pill--<?php echo !empty($products['scanning']) ? 'warm' : 'neutral'; ?>"><?php echo !empty($products['scanning']) ? 'پایش در حال انجام است' : ($scanCompleted ? 'آخرین دور پایان یافته' : 'در انتظار اولین دور'); ?></span></div>
    <?php if (!empty($products['scanning'])): ?><p class="monitoring-notice">این دور هنوز کامل نشده است؛ اعداد تا پایان بررسی همهٔ محصولات تغییر می‌کنند.</p><?php endif; ?>
    <?php if (!empty($products['scan_error'])): ?><p class="monitoring-notice"><?php echo esc_html(View::reason($products['scan_error'])); ?> نتایج فعلی ممکن است ناقص باشند.</p><?php endif; ?>
    <?php if ($oldScan): ?><p class="monitoring-notice">بیش از ۳۰ دقیقه از پایان آخرین دور گذشته است. این گزارش وضعیت لحظه‌ای همهٔ محصولات را تضمین نمی‌کند.</p><?php endif; ?>
    <?php if (!$active || !empty($sub['capacity_exceeded'])): ?><p class="monitoring-notice"><?php echo esc_html($sub['message'] ?? 'اشتراک فعال نیست.'); ?> <?php echo esc_html($sub['guidance'] ?? ''); ?></p><?php endif; ?>
    <div class="monitoring-kpis">
    <?php foreach (['checked_products'=>['بررسی‌شده','مقایسهٔ قیمت و موجودی'], 'mismatched_products'=>['اختلاف اولیه','پیش از تلاش برای اصلاح'], 'updated_products'=>['اصلاح تأییدشده','پس از خواندن دوبارهٔ باسلام'], 'needs_attention'=>['نیازمند رسیدگی','خطا یا اختلاف باقی‌مانده']] as $key => $labels): ?>
        <article class="monitoring-card monitoring-kpi<?php echo $key === 'updated_products' ? ' monitoring-card--mint' : ''; ?>"><h3><?php echo esc_html($labels[0]); ?></h3><strong><?php echo esc_html(View::number($summary[$key] ?? null)); ?></strong><small><?php echo esc_html($labels[1]); ?></small></article>
    <?php endforeach; ?>
    </div>
    <p class="monitoring-caption">شروع: <?php echo View::date($products['scan_started_at'] ?? null); ?> · پایان: <?php echo View::date($scanCompleted); ?> · پردازش‌شده: <?php echo esc_html(View::number($summary['processed_products'] ?? null)); ?></p>
    <div class="monitoring-section-heading"><div><p>از شروع ثبت آمار این اتصال</p><h2>اثر پایشگر بر فروشگاه شما</h2></div></div>
    <div class="monitoring-impact">
        <article class="monitoring-card monitoring-card--dark"><h3>اصلاح‌های تأییدشده</h3><strong class="monitoring-big-number"><?php echo esc_html(View::number($benefits['correction_events'] ?? null)); ?></strong><p><?php echo esc_html(View::number($benefits['price_corrections'] ?? null)); ?> اصلاح قیمت · <?php echo esc_html(View::number($benefits['stock_corrections'] ?? null)); ?> اصلاح موجودی</p><p>در <?php echo esc_html(View::number($benefits['restored_products'] ?? null)); ?> محصول متمایز</p><small>آخرین اصلاح: <?php echo View::date($benefits['last_correction_at'] ?? null); ?></small></article>
        <article class="monitoring-card"><h3>اختلاف قیمت برطرف‌شده</h3><dl class="monitoring-detail-list"><div><dt>قیمت کمتر از مرجع</dt><dd><?php echo esc_html(View::number(is_numeric($benefits['underpriced_gap_irr'] ?? null) ? $benefits['underpriced_gap_irr'] / 10 : null)); ?> تومان</dd></div><div><dt>قیمت بیشتر از مرجع</dt><dd><?php echo esc_html(View::number(is_numeric($benefits['overpriced_gap_irr'] ?? null) ? $benefits['overpriced_gap_irr'] / 10 : null)); ?> تومان</dd></div></dl><p class="monitoring-caption">مجموع اختلاف قیمت اصلاح‌شده در رویدادهاست؛ درآمد، سود یا زیان فروش نیست.</p></article>
        <article class="monitoring-card"><h3>نمای کلی دوره‌های ثبت‌شده</h3><dl class="monitoring-detail-list"><div><dt>تعداد دورها</dt><dd><?php echo esc_html(View::number($aggregate['total_scans'] ?? null)); ?></dd></div><div><dt>مجموع بررسی‌ها</dt><dd><?php echo esc_html(View::number($aggregate['checked_products'] ?? null)); ?></dd></div><div><dt>تطابق پیش از اصلاح</dt><dd><?php echo esc_html(View::number($aggregate['before_match_percent'] ?? null, 1)); ?>٪</dd></div><div><dt>تطابق پس از اصلاح</dt><dd><?php echo esc_html(View::number($aggregate['after_match_percent'] ?? null, 1)); ?>٪</dd></div></dl><p class="monitoring-caption">درصدها فقط بر مبنای مقایسه‌های معتبرِ دورهای تکمیل‌شده‌اند. بررسی یک محصول در چند دور، چند بار شمرده می‌شود.</p></article>
    </div>
    <div class="monitoring-section-heading"><div><p>آخرین نتایج ثبت‌شده؛ حداکثر ۲۰ مورد</p><h2>محصولات نیازمند رسیدگی</h2></div><button class="monitoring-button" type="button" data-go-tab="history">مشاهدهٔ تاریخچه</button></div>
    <?php if (empty($products['recent_issues'])): ?><p class="monitoring-empty">در گزارش فعلی موردی برای نمایش ثبت نشده است. برای نتیجهٔ کامل، وضعیت و زمان پایان دور را بررسی کنید.</p><?php else: ?>
    <div class="monitoring-table-wrap" tabindex="0" role="region" aria-label="محصولات نیازمند رسیدگی"><table class="monitoring-table"><thead><tr><th scope="col">محصول</th><th scope="col">مرجع ووکامرس</th><th scope="col">مشاهده‌شده در باسلام</th><th scope="col">توضیح</th></tr></thead><tbody>
    <?php foreach (array_slice($products['recent_issues'], 0, 20) as $issue): ?>
    <tr><th scope="row"><?php echo esc_html($issue['title'] ?? 'محصول بدون عنوان'); ?><small>شناسهٔ ووکامرس <?php echo esc_html(View::number($issue['woo_product_id'] ?? null)); ?></small><small><?php echo View::date($issue['checked_at'] ?? null); ?></small></th><td><?php echo esc_html(View::values(is_array($issue['desired'] ?? null) ? $issue['desired'] : [])); ?></td><td><?php echo esc_html(View::values(is_array($issue['observed'] ?? null) ? $issue['observed'] : [])); ?></td><td><?php echo esc_html(View::reason($issue['reason'] ?? '')); ?></td></tr>
    <?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php endif; ?>
</section>
<section id="monitoring-pane-settings" class="monitoring-pane" role="tabpanel" aria-labelledby="monitoring-tab-settings" hidden>
    <div class="monitoring-section-heading"><div><p>کنترل تغییرات محصولات</p><h2>اصلاح خودکار قیمت و موجودی</h2></div><span class="monitoring-pill monitoring-pill--<?php echo $repair ? 'success' : 'neutral'; ?>"><?php echo !$available ? 'وضعیت نامشخص' : ($repair ? 'روشن' : 'خاموش'); ?></span></div>
    <div class="monitoring-card monitoring-settings"><p>با روشن‌بودن این گزینه، افزونهٔ همین سایت قیمت و موجودی باسلام را بر اساس محاسبات فعلی ووسلام اصلاح می‌کند. موفقیت فقط پس از خواندن دوباره و تأیید نتیجه در باسلام ثبت می‌شود.</p><p><strong>خاموش‌کردن اصلاح خودکار، پایش اختلاف‌ها را خاموش نمی‌کند.</strong> انقضای اشتراک یا عبور از ظرفیت می‌تواند اجرای پایش محصولات و اصلاح را متوقف کند.</p>
    <?php if (!$canChange): ?><p class="monitoring-notice">برای تغییر این تنظیم، گزارش تازه و اشتراک فعال لازم است.</p><?php endif; ?>
    <?php if (!empty($sub['capacity_exceeded'])): ?><p class="monitoring-notice">ظرفیت اشتراک کافی نیست؛ روشن‌بودن این گزینه به‌تنهایی باعث اجرای اصلاح نمی‌شود.</p><?php endif; ?>
    <form id="monitoring-settings-form"><input type="hidden" name="enabled" value="<?php echo $repair ? '0' : '1'; ?>">
    <?php if (!$repair): ?><label class="monitoring-consent"><input type="checkbox" name="accepted" value="1" required <?php disabled(!$canChange); ?>><span>می‌پذیرم قیمت و موجودی محاسبه‌شده توسط ووسلام در این ووکامرس، مرجع اصلاح محصولات و تنوع‌های متصل در باسلام باشد.</span></label><?php endif; ?>
    <button type="submit" class="monitoring-button<?php echo !$repair ? ' monitoring-button--primary' : ''; ?>" <?php disabled(!$canChange); ?>><?php echo $repair ? 'توقف اصلاح خودکار' : 'فعال‌کردن اصلاح خودکار'; ?></button></form></div>
</section>
<section id="monitoring-pane-subscription" class="monitoring-pane" role="tabpanel" aria-labelledby="monitoring-tab-subscription" hidden>
    <div class="monitoring-section-heading"><div><p>اشتراک این فروشگاه</p><h2><?php echo esc_html($plan['title'] ?? 'وضعیت اشتراک'); ?></h2></div><span class="monitoring-pill monitoring-pill--<?php echo $active ? 'success' : 'neutral'; ?>"><?php echo !$available ? 'نامشخص' : ($active ? 'اشتراک فعال' : 'اشتراک غیرفعال'); ?></span></div>
    <div class="monitoring-subscription-grid"><article class="monitoring-card"><h3>ظرفیت اتصال</h3><p><strong class="monitoring-big-number"><?php echo esc_html(View::number($units)); ?></strong> از <?php echo esc_html(View::number($limit)); ?> واحد</p>
    <?php if (is_numeric($units) && is_numeric($limit) && $limit > 0): ?><meter min="0" max="<?php echo esc_attr($limit); ?>" value="<?php echo esc_attr(min($units, $limit)); ?>" aria-label="ظرفیت مصرف‌شده"></meter><?php endif; ?>
    <p class="monitoring-caption">هر محصول ساده و هر تنوع متصل یک واحد از ظرفیت را مصرف می‌کند.</p></article><article class="monitoring-card"><h3>زمان و وضعیت</h3><dl class="monitoring-detail-list"><div><dt>پایان اشتراک</dt><dd><?php echo View::date($sub['expires_at'] ?? null); ?></dd></div><div><dt>آخرین بررسی اتصال</dt><dd><?php echo View::date($sub['checked_at'] ?? null); ?></dd></div></dl><p><?php echo esc_html($sub['message'] ?? 'هنوز گزارشی دریافت نشده است.'); ?></p><p class="monitoring-caption"><?php echo esc_html($sub['guidance'] ?? ''); ?></p></article></div>
    <?php if (!empty($result['data']['payment'])): $payment = $result['data']['payment']; ?><p class="monitoring-notice">یک پرداخت در جریان ثبت شده است. وضعیت: <?php echo esc_html(['pending'=>'در انتظار پرداخت','processing'=>'در حال بررسی','verifying'=>'در حال تأیید','succeeded'=>'تأییدشده','failed'=>'ناموفق','expired'=>'منقضی‌شده'][$payment['status'] ?? ''] ?? 'در انتظار نتیجهٔ قطعی'); ?>. وضعیت اشتراک پس از تأیید سرویس به‌روز می‌شود.</p><?php endif; ?>
</section>
