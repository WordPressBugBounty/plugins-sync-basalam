<?php

namespace SyncBasalam\SalamGate\Admin\Ajax;

use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\WebsiteOrdersService;
use SyncBasalam\SalamGate\Utils\Digits;

defined('ABSPATH') || exit;

require_once __DIR__ . '/../../Services/WebsiteOrdersService.php';

final class WebsiteOrdersActions
{
    public static function listOrders(): void
    {
        self::guard();
        try {
            $cursor = self::stringInput('cursor');
            $result = (new WebsiteOrdersService())->listOrders($cursor !== '' ? $cursor : null, self::stringInput('search'));
        } catch (SalamGateException $error) { wp_send_json_error(self::errorPayload($error)); }
        catch (\Throwable $error) { wp_send_json_error(['message' => 'دریافت سفارش‌های ووکامرس انجام نشد؛ دوباره تلاش کنید.']); }
        wp_send_json_success($result);
    }

    public static function selectOrder(): void
    {
        self::guard();
        try {
            $orderId = WebsiteOrdersService::positiveId(Digits::toEnglish(self::stringInput('order_id')));
            if ($orderId === null) {
                throw new SalamGateException('سفارش ووکامرس را دوباره از فهرست انتخاب کنید.', 422, [], SalamGateException::KIND_VALIDATION);
            }
            $result = (new WebsiteOrdersService())->selectOrder($orderId);
        } catch (SalamGateException $error) { wp_send_json_error(self::errorPayload($error)); }
        catch (\Throwable $error) { wp_send_json_error(['message' => 'انتخاب سفارش ووکامرس کامل نشد؛ سفارش را دوباره از فهرست انتخاب کنید.']); }
        wp_send_json_success($result);
    }

    private static function stringInput(string $key): string
    {
        $value = $_POST[$key] ?? '';
        if (!is_string($value) && !is_int($value)) {
            throw new SalamGateException('ورودی انتخاب سفارش ووکامرس معتبر نیست.', 422, [], SalamGateException::KIND_VALIDATION);
        }
        return (string) wp_unslash((string) $value);
    }

    private static function guard(): void
    {
        if (!current_user_can('manage_options') || !current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'اجازه انتخاب سفارش‌های ووکامرس را ندارید.'], 403);
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
