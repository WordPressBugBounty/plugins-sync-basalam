<?php
use SyncBasalam\Services\MonitoringDashboardView as View;
defined('ABSPATH') || exit;
?>
<?php if (!$data['data']): ?><p class="monitoring-empty">هنوز دور پایشی برای این اتصال ثبت نشده است.</p><?php else: ?>
<div class="monitoring-table-wrap" tabindex="0" role="region" aria-label="تاریخچهٔ دورهای پایش"><table class="monitoring-table"><thead><tr><th scope="col">شروع و پایان</th><th scope="col">وضعیت</th><th scope="col">بررسی‌شده</th><th scope="col">اختلاف اولیه</th><th scope="col">اصلاح‌شده</th><th scope="col">نیازمند رسیدگی</th><th scope="col">جزئیات</th></tr></thead><tbody>
<?php foreach ($data['data'] as $scan): $state = View::state($scan['status'] ?? '', !empty($scan['summary']['needs_attention'])); ?>
<tr data-scan="<?php echo esc_attr($scan['id']); ?>"><th scope="row"><?php echo View::date($scan['started_at'] ?? null); ?><small><?php echo !empty($scan['completed_at']) ? View::date($scan['completed_at']) : 'پایان ثبت نشده'; ?></small></th><td><span class="monitoring-pill monitoring-pill--<?php echo esc_attr($state[1]); ?>"><?php echo esc_html($state[0]); ?></span><?php if (!empty($scan['error'])): ?><small><?php echo esc_html(View::reason($scan['error'])); ?></small><?php endif; ?></td>
<?php foreach (['checked_products','mismatched_products','updated_products','needs_attention'] as $key): ?><td><?php echo esc_html(View::number($scan['summary'][$key] ?? null)); ?></td><?php endforeach; ?>
<td><button type="button" class="monitoring-button" data-action="changes" data-scan="<?php echo esc_attr($scan['id']); ?>" aria-label="<?php echo esc_attr('جزئیات اصلاح‌های دور ' . View::number($scan['id'])); ?>">مشاهدهٔ اصلاح‌ها</button><small><?php echo esc_html(View::number($scan['recorded_changes'] ?? null)); ?> تغییر ثبت‌شده</small></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<p class="monitoring-caption">فقط اصلاح‌هایی که پس از خواندن دوبارهٔ باسلام تأیید شده‌اند، در جزئیات نمایش داده می‌شوند.</p>
<?php endif; ?>
