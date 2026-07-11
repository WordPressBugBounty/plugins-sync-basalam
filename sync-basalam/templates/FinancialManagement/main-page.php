<?php

use SyncBasalam\Admin\FinancialManagement\FinancialManagementHistory;
use SyncBasalam\Services\FinancialManagementService;
use SyncBasalam\Utilities\DateConverter;

defined('ABSPATH') || exit;

$service = new FinancialManagementService();
$perPage = $service->getDefaultPerPage();

$activePage = isset($_GET['sbp_active_page']) ? max(1, absint(wp_unslash($_GET['sbp_active_page']))) : 1;
$historyPage = isset($_GET['sbp_history_page']) ? max(1, absint(wp_unslash($_GET['sbp_history_page']))) : 1;

$balanceResult = $service->getBalance();
$activeSettlementsResult = $service->getActiveSettlements($activePage, $perPage);
$historyResult = $service->getSettlementHistory($historyPage, $perPage);

$balanceData = (isset($balanceResult['data']) && is_array($balanceResult['data'])) ? $balanceResult['data'] : [];
$activeSettlementsData = (isset($activeSettlementsResult['data']) && is_array($activeSettlementsResult['data'])) ? $activeSettlementsResult['data'] : [];
$historyData = (isset($historyResult['data']) && is_array($historyResult['data'])) ? $historyResult['data'] : [];

$activeItems = (isset($activeSettlementsData['data']) && is_array($activeSettlementsData['data'])) ? $activeSettlementsData['data'] : [];
$historyItems = (isset($historyData['data']) && is_array($historyData['data'])) ? $historyData['data'] : [];

$activeTotalPages = max(1, (int) ($activeSettlementsData['last_page'] ?? 1));
$historyTotalPages = max(1, (int) ($historyData['last_page'] ?? 1));

// Convert from rials to Toman (divide by 10)
$formatAmount = static function ($amount): string {
    if (!is_numeric($amount)) {
        return '-';
    }
    $value = (float) $amount;
    return number_format($value / 10, 0, '', ',');
};

$formatAmountWithUnit = static function ($amount) use ($formatAmount): string {
    $formatted = $formatAmount($amount);
    if ($formatted === '-') {
        return $formatted;
    }
    return $formatted . ' تومان';
};

$maskLastDigit = static function ($amount) use ($formatAmount): string {
    if (!is_numeric($amount)) {
        return '-';
    }
    $intAmount = (int) $amount;
    $negativePrefix = $intAmount < 0 ? '-' : '';
    $digits = ltrim((string) abs($intAmount), '0');

    if ($digits === '') {
        return '0';
    }

    $withoutLastDigit = strlen($digits) > 1 ? substr($digits, 0, -1) : '';
    $formattedPart = $withoutLastDigit === '' ? '0' : $formatAmount((int) $withoutLastDigit * 10);

    return $negativePrefix . $formattedPart;
};

// Format timestamp to Jalali (Shamsi) date using DateConverter
$formatJalaliTimestamp = static function ($timestamp): string {
    if (!is_numeric($timestamp)) {
        return '-';
    }
    $value = (int) $timestamp;
    if ($value <= 0) {
        return '-';
    }

    $dateTime = new DateTime('@' . $value);
    $dateTime->setTimezone(wp_timezone());

    $gy = (int) $dateTime->format('Y');
    $gm = (int) $dateTime->format('m');
    $gd = (int) $dateTime->format('d');
    $time = $dateTime->format('H:i');

    [$jy, $jm, $jd] = DateConverter::gregorianToJalaliArray($gy, $gm, $gd);

    return sprintf('%04d/%02d/%02d - %s', $jy, $jm, $jd, $time);
};

// Format timestamp to Gregorian date
$formatGregorianTimestamp = static function ($timestamp): string {
    if (!is_numeric($timestamp)) {
        return '-';
    }
    $value = (int) $timestamp;
    if ($value <= 0) {
        return '-';
    }
    return wp_date('Y/m/d H:i', $value);
};

$getStatusLabel = static function (array $item): string {
    if (!empty($item['status_label']) && is_string($item['status_label'])) {
        return $item['status_label'];
    }
    if (!empty($item['status']['description']) && is_string($item['status']['description'])) {
        return $item['status']['description'];
    }
    return '-';
};

$getStatusClass = static function (array $item): string {
    $name = '';
    if (!empty($item['status']['name']) && is_string($item['status']['name'])) {
        $name = strtolower($item['status']['name']);
    }
    $map = [
        'created' => 'created',
        'prepared' => 'prepared',
        'confirmed' => 'confirmed',
        'system_payment_created' => 'prepared',
        'system_succeeded' => 'paid',
        'wallet_charged' => 'paid',
        'paid' => 'paid',
        'rejected' => 'rejected',
        'cancelled' => 'rejected',
        'system_failed' => 'rejected',
    ];
    return $map[$name] ?? 'default';
};

$getMethodLabel = static function (array $item): string {
    if (!empty($item['method']['description']) && is_string($item['method']['description'])) {
        return $item['method']['description'];
    }
    return '-';
};

$renderApiError = static function (array $result): void {
    $statusCode = isset($result['status_code']) ? (int) $result['status_code'] : 0;
    $message = isset($result['message']) && is_string($result['message']) ? $result['message'] : 'خطای نامشخص در دریافت اطلاعات.';
?>
    <div class="notice notice-error inline">
        <p>
            <?php echo esc_html($message); ?>
            <?php if ($statusCode > 0): ?>
                <span>(HTTP <?php echo esc_html((string) $statusCode); ?>)</span>
            <?php endif; ?>
        </p>
    </div>
<?php
};

$buildPageUrl = static function (int $active, int $history): string {
    return add_query_arg([
        'page' => 'sync_basalam_financial_management',
        'sbp_active_page' => max(1, $active),
        'sbp_history_page' => max(1, $history),
    ], admin_url('admin.php'));
};

$activeTitle = 'درخواست‌های تسویه فعال';
$historyTitle = 'ترازهای تسویه شده';
?>

<div class="basalam-container">
    <div class="basalam-dashboard">

        <!-- Balance Section -->
        <div class="basalam-status-card">
            <div class="basalam-status-header">
                <h2 class="basalam-h">
                    <span class="dashicons dashicons-chart-line"></span>
                    مدیریت مالی غرفه
                </h2>
            </div>

            <?php if (empty($balanceResult['success'])): ?>
                <?php $renderApiError($balanceResult); ?>
            <?php else: ?>
                <div class="basalam-balance-grid">
                    <div class="basalam-balance-card">
                        <div class="basalam-balance-card__icon">
                            <span class="dashicons dashicons-chart-area"></span>
                        </div>
                        <div class="basalam-balance-card__content">
                            <h3>تراز غرفه</h3>
                            <div class="basalam-balance-card__value"><?php echo esc_html($maskLastDigit($balanceData['balance'] ?? null)); ?> تومان</div>
                            <p>تراز غرفه تا ساعت <?php echo esc_html($formatJalaliTimestamp($balanceData['calculated_at'] ?? null)); ?></p>
                        </div>
                    </div>

                    <div class="basalam-balance-card basalam-balance-card--highlight">
                        <div class="basalam-balance-card__icon">
                            <span class="dashicons dashicons-building"></span>
                        </div>
                        <div class="basalam-balance-card__content">
                            <h3>تسویه بانکی سال جاری</h3>
                            <div class="basalam-balance-card__value basalam-balance-card__value--small"><?php echo esc_html($formatAmountWithUnit($balanceData['settled']['cash'] ?? null)); ?></div>
                        </div>
                    </div>

                    <div class="basalam-balance-card">
                        <div class="basalam-balance-card__icon">
                            <span class="dashicons dashicons-money"></span>
                        </div>
                        <div class="basalam-balance-card__content">
                            <h3>تسویه کیف پول سال جاری</h3>
                            <div class="basalam-balance-card__value basalam-balance-card__value--small"><?php echo esc_html($formatAmountWithUnit($balanceData['settled']['credit'] ?? null)); ?></div>
                        </div>
                    </div>
                </div>

                <div class="basalam-balance-actions">
                    <button
                        type="button"
                        class="basalam-balance-action basalam-balance-action--wallet"
                        data-method="2">
                        انتقال به کیف پول
                    </button>
                    <button
                        type="button"
                        class="basalam-balance-action basalam-balance-action--bank"
                        data-method="1">
                        انتقال به حساب بانکی
                    </button>
                </div>
            <?php endif; ?>
        </div>

        <!-- Active Settlements -->
        <div class="basalam-status-card">
            <div class="basalam-status-header">
                <h2 class="basalam-h">
                    <span class="dashicons dashicons-list-view"></span>
                    <?php echo esc_html($activeTitle); ?>
                </h2>
                <?php if (!empty($activeSettlementsResult['success']) && !empty($activeItems)): ?>
                    <span class="basalam-badge-success">
                        <?php echo esc_html(sprintf('تعداد %s مورد', number_format_i18n((int) ($activeSettlementsData['total'] ?? count($activeItems))))); ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php if (empty($activeSettlementsResult['success'])): ?>
                <?php $renderApiError($activeSettlementsResult); ?>
            <?php elseif (empty($activeItems)): ?>
                <div class="basalam-empty-state">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <p>در حال حاضر درخواست تسویه فعالی وجود ندارد.</p>
                </div>
            <?php else: ?>
                <div class="basalam-settlement-list">
                    <?php foreach ($activeItems as $item): ?>
                        <?php if (!is_array($item)) {
                            continue;
                        } ?>
                        <?php $statusClass = $getStatusClass($item); ?>
                        <div class="basalam-settlement-card">
                            <div class="basalam-settlement-card__top">
                                <span class="basalam-settlement-card__id">#<?php echo esc_html((string) ($item['id'] ?? '-')); ?></span>
                                <span class="basalam-settlement-card__amount"><?php echo esc_html($formatAmountWithUnit($item['amount'] ?? null)); ?></span>
                            </div>

                            <div class="basalam-settlement-card__meta">
                                <span class="basalam-status-badge basalam-status-badge--<?php echo esc_attr($statusClass); ?>">
                                    <?php echo esc_html($getStatusLabel($item)); ?>
                                </span>
                                <span>
                                    <span class="dashicons dashicons-calendar-alt"></span>
                                    ثبت: <?php echo esc_html($formatJalaliTimestamp($item['created_at'] ?? null)); ?>
                                </span>
                                <span>
                                    <span class="dashicons dashicons-clock"></span>
                                    قابل پرداخت: <?php echo esc_html($formatJalaliTimestamp($item['payable_at'] ?? null)); ?>
                                </span>
                            </div>

                            <p class="basalam-settlement-card__description">
                                <?php echo esc_html((string) ($item['status_description'] ?? 'توضیح وضعیت موجود نیست.')); ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($activeTotalPages > 1): ?>
                    <div class="basalam-pagination" role="navigation" aria-label="صفحه‌بندی تسویه فعال">
                        <?php if ($activePage > 1): ?>
                            <a class="page-numbers" href="<?php echo esc_url($buildPageUrl($activePage - 1, $historyPage)); ?>">قبلی</a>
                        <?php endif; ?>

                        <?php $activeStart = max(1, $activePage - 2); ?>
                        <?php $activeEnd = min($activeTotalPages, $activePage + 2); ?>
                        <?php for ($i = $activeStart; $i <= $activeEnd; $i++): ?>
                            <a class="page-numbers <?php echo $i === $activePage ? 'current' : ''; ?>" href="<?php echo esc_url($buildPageUrl($i, $historyPage)); ?>">
                                <?php echo esc_html(number_format_i18n($i)); ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($activePage < $activeTotalPages): ?>
                            <a class="page-numbers prev-next" href="<?php echo esc_url($buildPageUrl($activePage + 1, $historyPage)); ?>">بعدی</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Settlement History -->
        <?php
        echo FinancialManagementHistory::render([
            'activePage' => $activePage,
            'historyPage' => $historyPage,
            'historyTitle' => $historyTitle,
            'historyResult' => $historyResult,
            'historyData' => $historyData,
            'historyItems' => $historyItems,
            'historyTotalPages' => $historyTotalPages,
        ]);
        ?>

    </div>

    <!-- Settlement Modal -->
    <div id="basalam-settlement-modal" class="basalam-plus-modal-overlay" role="dialog" aria-modal="true" aria-label="درخواست تسویه">
        <div class="basalam-plus-modal">
            <div class="basalam-plus-modal__header">
                <h3 class="basalam-plus-modal__title">انتقال به کیف پول</h3>
                <button type="button" id="basalam-settlement-close" class="basalam-plus-modal__close" aria-label="بستن">&times;</button>
            </div>

            <!-- Step 1: Amount -->
            <div id="basalam-settlement-step-1">
                <div class="basalam-plus-modal__body">
                    <div class="basalam-plus-modal__balance-info">
                        تراز غرفه: <strong><?php echo esc_html($formatAmount($balanceData['balance'] ?? 0)); ?> تومان</strong>
                    </div>
                    <label for="basalam-settlement-amount" class="basalam-plus-modal__label">مبلغ (تومان)</label>
                    <input
                        type="text"
                        id="basalam-settlement-amount"
                        class="basalam-plus-modal__input basalam-input"
                        inputmode="numeric"
                        placeholder="مثال: 100,000"
                        autocomplete="off"
                        data-max-balance="<?php echo esc_attr((int) (($balanceData['balance'] ?? 0) / 10)); ?>" />
                    <div id="basalam-settlement-balance-error" class="basalam-plus-modal__error basalam-plus-modal__balance-error" style="display:none;">
                        مبلغ نباید بیشتر از تراز غرفه باشد
                    </div>
                    <div id="basalam-settlement-error" class="basalam-plus-modal__error" style="display:none;"></div>
                    <input type="hidden" id="basalam-settlement-method" value="2" />
                    <input type="hidden" id="basalam-settlement-investment-option-id" value="1" />
                    <input type="hidden" id="basalam-settlement-bank-account-id" value="" />
                </div>
                <div class="basalam-plus-modal__footer">
                    <button type="button" id="basalam-settlement-cancel" class="basalam-plus-modal__btn basalam-plus-modal__btn--secondary">
                        انصراف
                    </button>
                    <button type="button" id="basalam-settlement-next" class="basalam-plus-modal__btn basalam-plus-modal__btn--primary" style="display:none;">
                        بعدی
                    </button>
                    <button type="button" id="basalam-settlement-submit" class="basalam-plus-modal__btn basalam-plus-modal__btn--primary">
                        ثبت درخواست
                    </button>
                </div>
            </div>

            <!-- Step 2: Bank Accounts -->
            <div id="basalam-settlement-step-2" style="display:none;">
                <div class="basalam-plus-modal__body">
                    <p class="basalam-plus-modal__label">حساب بانکی مقصد را انتخاب کنید</p>
                    <div id="basalam-bank-accounts-list" class="basalam-bank-accounts-list">
                        <div class="basalam-bank-accounts-loading">در حال بارگذاری...</div>
                    </div>
                    <div id="basalam-bank-accounts-error" class="basalam-plus-modal__error" style="display:none;"></div>
                </div>
                <div class="basalam-plus-modal__footer">
                    <button type="button" id="basalam-settlement-back" class="basalam-plus-modal__btn basalam-plus-modal__btn--secondary">
                        بازگشت
                    </button>
                    <button type="button" id="basalam-settlement-submit-bank" class="basalam-plus-modal__btn basalam-plus-modal__btn--primary" disabled>
                        ثبت درخواست
                    </button>
                </div>
            </div>

        </div>
    </div>
</div>