<?php

namespace SyncBasalam\SalamGate\Admin\Ajax;

use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\BasalamOrdersService;
use SyncBasalam\SalamGate\Utils\Digits;

defined('ABSPATH') || exit;

require_once __DIR__ . '/../../Services/BasalamOrdersService.php';

final class BasalamOrdersActions
{
    public static function listOrders(): void
    {
        self::guard();
        try {
            $search = Digits::toEnglish(trim(self::stringInput('search')));
            $cursor = self::stringInput('cursor');
            $exactId = null;
            if ($search !== '' && preg_match('/^[0-9]+$/D', $search)) {
                $exactId = BasalamOrdersService::positiveId($search);
                if ($exactId === null) {
                    throw new SalamGateException('شماره کامل و معتبر سفارش باسلام را وارد کنید.', 422, [], SalamGateException::KIND_VALIDATION);
                }
                $search = '';
            }
            $result = (new BasalamOrdersService())->listOrders($cursor !== '' ? $cursor : null, $exactId, 90, $search);
        } catch (SalamGateException $error) { wp_send_json_error(self::errorPayload($error)); }
        catch (\Throwable $error) { wp_send_json_error(['message' => 'دریافت سفارش‌های باسلام انجام نشد؛ دوباره تلاش کنید.']); }
        wp_send_json_success($result);
    }

    public static function selectOrder(): void
    {
        self::guard();
        try {
            $invoiceId = BasalamOrdersService::positiveId(Digits::toEnglish(self::stringInput('basalam_order_id')));
            $parcelId = BasalamOrdersService::positiveId(Digits::toEnglish(self::stringInput('basalam_parcel_id')));
            if ($invoiceId === null || $parcelId === null) {
                throw new SalamGateException('سفارش باسلام را دوباره از فهرست انتخاب کنید.', 422, [], SalamGateException::KIND_VALIDATION);
            }
            $importConfirmed = self::stringInput('import_confirmed') === '1';
            $result = (new BasalamOrdersService())->selectOrder($invoiceId, $parcelId, $importConfirmed);
        } catch (SalamGateException $error) { wp_send_json_error(self::errorPayload($error)); }
        catch (\Throwable $error) { wp_send_json_error(['message' => 'انتخاب سفارش کامل نشد؛ وضعیت سفارش‌های ووسلام را بررسی کنید و دوباره همین سفارش را انتخاب کنید.']); }
        if (isset($result['code'])) { wp_send_json_error($result); }
        wp_send_json_success($result);
    }

    private static function stringInput(string $key): string
    {
        $value = $_POST[$key] ?? '';
        if (!is_string($value) && !is_int($value)) {
            throw new SalamGateException('ورودی انتخاب سفارش باسلام معتبر نیست.', 422, [], SalamGateException::KIND_VALIDATION);
        }
        return (string) wp_unslash((string) $value);
    }

    private static function guard(): void
    {
        if (!current_user_can('manage_options') || !current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'اجازه انتخاب سفارش‌های باسلام را ندارید.'], 403);
        }
        $nonce = $_POST['_wpnonce'] ?? '';
        if (!is_string($nonce) || !wp_verify_nonce(wp_unslash($nonce), 'basalam_pro_nonce')) {
            wp_send_json_error(['message' => 'اعتبار صفحه تمام شده است؛ صفحه را تازه‌سازی کنید.'], 403);
        }
    }

    private static function errorPayload(SalamGateException $error): array
    {
        return ['message' => $error->firstMessage(), 'errors' => $error->getErrors(), 'kind' => $error->getKind()];
    }
}
