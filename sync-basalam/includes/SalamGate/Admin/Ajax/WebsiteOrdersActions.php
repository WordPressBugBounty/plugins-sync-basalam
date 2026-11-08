<?php

namespace SyncBasalam\SalamGate\Admin\Ajax;

use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\WebsiteOrdersService;
use SyncBasalam\SalamGate\Utils\Digits;

defined('ABSPATH') || exit;

final class WebsiteOrdersActions
{
    public static function listOrders(): void
    {
        self::guard();
        try {
            $cursor = self::stringInput('cursor');
            $result = (new WebsiteOrdersService())->listOrders($cursor !== '' ? $cursor : null, self::stringInput('search'));
        } catch (SalamGateException $error) { ResponseBoundary::error(self::errorPayload($error)); }
        catch (\Throwable $error) { ResponseBoundary::error(['message' => 'دریافت سفارش‌های ووکامرس انجام نشد؛ دوباره تلاش کنید.']); }
        ResponseBoundary::success($result);
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
        } catch (SalamGateException $error) { ResponseBoundary::error(self::errorPayload($error)); }
        catch (\Throwable $error) { ResponseBoundary::error(['message' => 'انتخاب سفارش ووکامرس کامل نشد؛ سفارش را دوباره از فهرست انتخاب کنید.']); }
        ResponseBoundary::success($result);
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
        ActionGuard::forController(self::class, null);
    }

    private static function errorPayload(SalamGateException $error): array
    {
        return ['message' => $error->firstMessage(), 'errors' => $error->getErrors(), 'kind' => $error->getKind()];
    }
}
