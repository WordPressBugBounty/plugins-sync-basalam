<?php

use SyncBasalam\Admin\FinancialManagement\FinancialManagementHistory;

defined('ABSPATH') || exit;
?>
<div
    class="basalam-status-card basalam-history-section"
    data-active-page="<?php echo esc_attr((string) $activePage); ?>"
    data-history-page="<?php echo esc_attr((string) $historyCurrentPage); ?>"
    aria-live="polite"
>
    <div class="basalam-status-header">
        <h2 class="basalam-h">
            <span class="dashicons dashicons-backup"></span>
            <?php echo esc_html($historyTitle); ?>
        </h2>
        <?php if (!empty($historyResult['success']) && !empty($historyItems)): ?>
            <span class="basalam-badge-success">
                <?php echo esc_html(sprintf('صفحه %s از %s', number_format_i18n($historyCurrentPage), number_format_i18n($historyTotalPages))); ?>
            </span>
        <?php endif; ?>
    </div>

    <?php if (empty($historyResult['success'])): ?>
        <?php FinancialManagementHistory::renderApiError($historyResult); ?>
    <?php elseif (empty($historyItems)): ?>
        <div class="basalam-empty-state">
            <span class="dashicons dashicons-info-outline"></span>
            <p>تاریخچه تسویه‌ای برای نمایش وجود ندارد.</p>
        </div>
    <?php else: ?>
        <div class="basalam-history-table-wrap">
            <table class="basalam-history-table">
                <thead>
                    <tr>
                        <th>عنوان</th>
                        <th>تاریخ و ساعت</th>
                        <th>نوع پرداخت</th>
                        <th>مبلغ(تومان)</th>
                        <th>توضیحات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($historyItems as $item): ?>
                        <?php if (!is_array($item)) {
                            continue;
                        } ?>
                        <?php $statusClass = FinancialManagementHistory::getStatusClass($item); ?>
                        <tr>
                            <td class="basalam-history-table__title">
                                <div class="basalam-history-item-info">
                                    <div><strong>شناسه:</strong> <?php echo esc_html((string) ($item['id'] ?? '-')); ?></div>
                                    <?php if (!empty($item['payment_request']['payment_tracking_code'])): ?>
                                        <div><strong>پیگیری:</strong> <?php echo esc_html((string) $item['payment_request']['payment_tracking_code']); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($item['payment_request']['iban'])): ?>
                                        <div><strong>شبا:</strong> <?php echo esc_html((string) $item['payment_request']['iban']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="basalam-history-table__date">
                                <div class="basalam-history-item-date">
                                    <div><strong>ثبت:</strong> <?php echo esc_html(FinancialManagementHistory::formatJalaliTimestamp($item['created_at'] ?? null)); ?></div>
                                    <div><strong>پرداخت:</strong> <?php echo esc_html(FinancialManagementHistory::formatJalaliTimestamp($item['paid_at'] ?? null)); ?></div>
                                </div>
                            </td>
                            <td>
                                <div class="basalam-history-item-method">
                                    <div><?php echo esc_html(FinancialManagementHistory::getMethodLabel($item)); ?></div>
                                    <span class="basalam-status-badge basalam-status-badge--<?php echo esc_attr($statusClass); ?>">
                                        <?php echo esc_html(FinancialManagementHistory::getStatusLabel($item)); ?>
                                    </span>
                                </div>
                            </td>
                            <td class="basalam-history-table__amount">
                                <?php echo esc_html(FinancialManagementHistory::formatAmount($item['amount'] ?? null)); ?>
                            </td>
                            <td class="basalam-history-table__description">
                                <?php echo esc_html((string) ($item['status_description'] ?? '-')); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($historyTotalPages > 1): ?>
            <div class="basalam-pagination" role="navigation" aria-label="صفحه‌بندی تاریخچه تسویه">
                <?php if ($historyCurrentPage > 1): ?>
                    <a
                        class="page-numbers prev-next"
                        href="<?php echo esc_url(FinancialManagementHistory::buildPageUrl($activePage, $historyCurrentPage - 1)); ?>"
                        data-history-page="<?php echo esc_attr((string) ($historyCurrentPage - 1)); ?>"
                    >
                        قبلی
                    </a>
                <?php endif; ?>

                <?php $historyStart = max(1, $historyCurrentPage - 2); ?>
                <?php $historyEnd = min($historyTotalPages, $historyCurrentPage + 2); ?>
                <?php for ($i = $historyStart; $i <= $historyEnd; $i++): ?>
                    <a
                        class="page-numbers <?php echo $i === $historyCurrentPage ? 'current' : ''; ?>"
                        href="<?php echo esc_url(FinancialManagementHistory::buildPageUrl($activePage, $i)); ?>"
                        data-history-page="<?php echo esc_attr((string) $i); ?>"
                    >
                        <?php echo esc_html(number_format_i18n($i)); ?>
                    </a>
                <?php endfor; ?>

                <?php if ($historyCurrentPage < $historyTotalPages): ?>
                    <a
                        class="page-numbers prev-next"
                        href="<?php echo esc_url(FinancialManagementHistory::buildPageUrl($activePage, $historyCurrentPage + 1)); ?>"
                        data-history-page="<?php echo esc_attr((string) ($historyCurrentPage + 1)); ?>"
                    >
                        بعدی
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
