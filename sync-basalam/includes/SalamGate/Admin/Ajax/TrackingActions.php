<?php

namespace SyncBasalam\SalamGate\Admin\Ajax;

use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\TrackingService;

defined('ABSPATH') || exit;

final class TrackingActions
{
    public static function lookup(): void
    {
        ActionGuard::forController(self::class);
        $input = $_POST['tracking_code'] ?? null;
        if (!is_string($input) || strlen($input) > 256) {
            ResponseBoundary::error(['message' => 'کد رهگیری باید شامل ۲۴ رقم باشد.', 'code' => 'validation'], 400);
            return;
        }
        try {
            $result = (new TrackingService())->lookup(wp_unslash($input));
        } catch (SalamGateException $error) {
            // Do not expose raw provider/transport details or mistake errors for no events.
            $messages = [
                SalamGateException::KIND_VALIDATION => 'کد رهگیری معتبر نیست؛ کد روی برچسب پستی را بررسی کنید.',
                SalamGateException::KIND_NOT_FOUND => 'اطلاعاتی برای این کد رهگیری پیدا نشد.',
                SalamGateException::KIND_RATE_LIMITED => 'تعداد درخواست‌های پیگیری زیاد است؛ کمی بعد دوباره تلاش کنید.',
                SalamGateException::KIND_FORBIDDEN => 'سرویس پیگیری فعلاً اجازه دریافت اطلاعات این مرسوله را نمی‌دهد.',
            ];
            ResponseBoundary::error(['message' => $messages[$error->getKind()] ?? 'دریافت سابقه مرسوله انجام نشد؛ کمی بعد دوباره تلاش کنید.', 'code' => $error->getKind()]);
            return;
        } catch (\Throwable $error) {
            ResponseBoundary::error(['message' => 'دریافت سابقه مرسوله انجام نشد؛ کمی بعد دوباره تلاش کنید.', 'code' => 'unavailable']);
            return;
        }
        ResponseBoundary::success($result);
    }
}
