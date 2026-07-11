<?php

namespace SyncBasalam\Admin\FinancialManagement;

use DateTime;
use SyncBasalam\Services\FinancialManagementService;
use SyncBasalam\Utilities\DateConverter;

defined('ABSPATH') || exit;

class FinancialManagementHistory
{
    public const AJAX_ACTION = 'sync_basalam_financial_history_page';

    public static function register(): void
    {
        add_action('wp_ajax_' . self::AJAX_ACTION, [__CLASS__, 'handleAjax']);
    }

    public static function buildViewData(int $activePage, int $historyPage): array
    {
        $service = new FinancialManagementService();
        $perPage = $service->getDefaultPerPage();
        $historyResult = $service->getSettlementHistory($historyPage, $perPage);
        $historyData = (isset($historyResult['data']) && is_array($historyResult['data'])) ? $historyResult['data'] : [];
        $historyItems = (isset($historyData['data']) && is_array($historyData['data'])) ? $historyData['data'] : [];

        return [
            'activePage' => max(1, $activePage),
            'historyPage' => max(1, $historyPage),
            'historyTitle' => 'ترازهای تسویه شده',
            'historyResult' => $historyResult,
            'historyData' => $historyData,
            'historyItems' => $historyItems,
            'historyTotalPages' => max(1, (int) ($historyData['last_page'] ?? 1)),
        ];
    }

    public static function render(array $viewData): string
    {
        $viewData = wp_parse_args($viewData, [
            'activePage' => 1,
            'historyPage' => 1,
            'historyTitle' => 'ترازهای تسویه شده',
            'historyResult' => [],
            'historyData' => [],
            'historyItems' => [],
            'historyTotalPages' => 1,
        ]);

        $activePage = max(1, (int) $viewData['activePage']);
        $historyPage = max(1, (int) $viewData['historyPage']);
        $historyTitle = is_string($viewData['historyTitle']) ? $viewData['historyTitle'] : 'ترازهای تسویه شده';
        $historyResult = is_array($viewData['historyResult']) ? $viewData['historyResult'] : [];
        $historyData = is_array($viewData['historyData']) ? $viewData['historyData'] : [];
        $historyItems = is_array($viewData['historyItems']) ? $viewData['historyItems'] : [];
        $historyTotalPages = max(1, (int) $viewData['historyTotalPages']);
        $historyCurrentPage = max(1, (int) ($historyData['current_page'] ?? $historyPage));

        ob_start();
        require dirname(__DIR__, 3) . '/templates/FinancialManagement/history-section.php';

        return (string) ob_get_clean();
    }

    public static function renderApiError(array $result): void
    {
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
    }

    public static function buildPageUrl(int $activePage, int $historyPage): string
    {
        return add_query_arg([
            'page' => Menu::PAGE_SLUG,
            'sbp_active_page' => max(1, $activePage),
            'sbp_history_page' => max(1, $historyPage),
        ], admin_url('admin.php'));
    }

    public static function formatAmount($amount): string
    {
        if (!is_numeric($amount)) {
            return '-';
        }

        return number_format(((float) $amount) / 10, 0, '', ',');
    }

    public static function formatJalaliTimestamp($timestamp): string
    {
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
    }

    public static function getStatusLabel(array $item): string
    {
        if (!empty($item['status_label']) && is_string($item['status_label'])) {
            return $item['status_label'];
        }

        if (!empty($item['status']['description']) && is_string($item['status']['description'])) {
            return $item['status']['description'];
        }

        return '-';
    }

    public static function getStatusClass(array $item): string
    {
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
    }

    public static function getMethodLabel(array $item): string
    {
        if (!empty($item['method']['description']) && is_string($item['method']['description'])) {
            return $item['method']['description'];
        }

        return '-';
    }

    public static function handleAjax(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => 'شما به این بخش دسترسی ندارید.',
            ], 403);
        }

        check_ajax_referer(self::AJAX_ACTION, 'nonce');

        $activePage = isset($_POST['active_page']) ? max(1, absint(wp_unslash($_POST['active_page']))) : 1;
        $historyPage = isset($_POST['history_page']) ? max(1, absint(wp_unslash($_POST['history_page']))) : 1;
        $viewData = self::buildViewData($activePage, $historyPage);
        $currentPage = max(1, (int) ($viewData['historyData']['current_page'] ?? $historyPage));

        wp_send_json_success([
            'html' => self::render($viewData),
            'historyPage' => $currentPage,
        ]);
    }
}
