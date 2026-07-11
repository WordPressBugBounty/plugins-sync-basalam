<?php

namespace SyncBasalam\Admin\FinancialManagement;

use SyncBasalam\Services\FinancialManagementService;

defined('ABSPATH') || exit;

class BalanceSettlement
{
    public const AJAX_ACTION = 'sync_basalam_balance_settlement';
    public const AJAX_ACTION_BANK_ACCOUNTS = 'sync_basalam_bank_accounts';

    public static function register(): void
    {
        add_action('wp_ajax_' . self::AJAX_ACTION, [__CLASS__, 'handleAjax']);
        add_action('wp_ajax_' . self::AJAX_ACTION_BANK_ACCOUNTS, [__CLASS__, 'handleBankAccountsAjax']);
    }

    public static function handleAjax(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => 'شما به این بخش دسترسی ندارید.',
            ], 403);
        }

        check_ajax_referer(self::AJAX_ACTION, 'nonce');

        $amount = isset($_POST['amount']) ? (int) $_POST['amount'] : 0;
        $method = isset($_POST['method']) ? (int) $_POST['method'] : 0;

        if ($amount <= 0) {
            wp_send_json_error([
                'message' => 'مبلغ وارد شده معتبر نیست.',
            ], 400);
        }

        if ($method <= 0) {
            wp_send_json_error([
                'message' => 'روش تسویه مشخص نشده است.',
            ], 400);
        }

        $investmentOptionId = isset($_POST['investment_option_id']) && $_POST['investment_option_id'] !== '' ? (int) $_POST['investment_option_id'] : null;
        $bankAccountId = isset($_POST['bank_account_id']) && $_POST['bank_account_id'] !== '' ? (int) $_POST['bank_account_id'] : null;

        $service = new FinancialManagementService();
        $result = $service->createSettlement($amount, $method, $investmentOptionId, $bankAccountId);

        if (empty($result['success'])) {
            wp_send_json_error([
                'message' => $result['message'] ?? 'خطا در ثبت درخواست تسویه.',
            ], $result['status_code'] ?? 500);
        }

        wp_send_json_success([
            'message' => 'درخواست تسویه با موفقیت ثبت شد.',
            'data' => $result['data'],
        ]);
    }

    public static function handleBankAccountsAjax(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => 'شما به این بخش دسترسی ندارید.',
            ], 403);
        }

        check_ajax_referer(self::AJAX_ACTION, 'nonce');

        $service = new FinancialManagementService();
        $result = $service->getBankAccounts();

        if (empty($result['success'])) {
            wp_send_json_error([
                'message' => $result['message'] ?? 'خطا در دریافت لیست حساب‌های بانکی.',
            ], $result['status_code'] ?? 500);
        }

        $accounts = isset($result['data']['data']) && is_array($result['data']['data']) ? $result['data']['data'] : [];

        wp_send_json_success([
            'accounts' => $accounts,
        ]);
    }
}
