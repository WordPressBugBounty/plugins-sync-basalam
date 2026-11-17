<?php
use SyncBasalam\Services\MonitoringDashboardView as View;
defined('ABSPATH') || exit;
?>
<p>دور شروع‌شده در <?php echo View::date($data['started_at'] ?? null); ?> · <?php echo esc_html(View::number($data['total_changes'] ?? null)); ?> تغییر در <?php echo esc_html(View::number($data['total_products'] ?? null)); ?> محصول</p>
<?php if (!$data['data']): ?><p class="monitoring-empty">برای این دور، اصلاح تأییدشده‌ای ثبت نشده است. نبود اصلاح به‌تنهایی به معنی سالم‌بودن همهٔ محصولات نیست.</p><?php else: ?>
<div class="monitoring-table-wrap" tabindex="0" role="region" aria-label="مقایسهٔ قبل و بعد اصلاح"><table class="monitoring-table"><thead><tr><th scope="col">محصول / تنوع</th><th scope="col">فیلد</th><th scope="col">مرجع ووکامرس</th><th scope="col">قبل در باسلام</th><th scope="col">بعد از تأیید</th><th scope="col">زمان تأیید</th></tr></thead><tbody>
<?php foreach ($data['data'] as $change): foreach ($change['fields'] ?? [] as $field): $price = ($field['field'] ?? '') === 'primary_price'; ?>
<tr data-change="<?php echo esc_attr($change['id']); ?>"><th scope="row"><?php echo esc_html($change['title'] ?: 'محصول بدون عنوان'); ?><?php if (empty($change['title_is_snapshot'])): ?><small>عنوان فعلی؛ عنوان زمان اصلاح ثبت نشده</small><?php endif; ?><small>ووکامرس <?php echo esc_html(View::number($change['woo_product_id'] ?? null)); ?> · باسلام <?php echo esc_html(View::number($change['basalam_product_id'] ?? null)); ?></small><?php if (isset($change['variation_id'])): ?><small>تنوع <?php echo esc_html(View::number($change['variation_id'])); ?></small><?php endif; ?></th><td><?php echo $price ? 'قیمت (تومان)' : 'موجودی'; ?></td>
<?php foreach (['source','before','after'] as $key): $value = $field[$key] ?? null; ?><td><?php echo esc_html(View::number(is_numeric($value) && $price ? $value / 10 : $value, $price ? 1 : 0)); ?></td><?php endforeach; ?>
<td><?php echo View::date($change['verified_at'] ?? null); ?></td></tr>
<?php endforeach; endforeach; ?>
</tbody></table></div>
<?php endif; ?>
