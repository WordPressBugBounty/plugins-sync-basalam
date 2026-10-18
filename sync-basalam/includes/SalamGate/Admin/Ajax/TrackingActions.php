<?php

namespace SyncBasalam\SalamGate\Admin\Ajax;

use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\TrackingService;

defined('ABSPATH') || exit;

final class TrackingActions
{
    public static function lookup(): void
    {
        $nonce = isset($_POST['_wpnonce']) && is_string($_POST['_wpnonce']) ? wp_unslash($_POST['_wpnonce']) : '';
        if (!current_user_can('manage_options') && !current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'دسترسی به پیگیری مرسوله مجاز نیست.', 'code' => 'forbidden'], 403);
            return;
        }
        if (!wp_verify_nonce($nonce, 'basalam_pro_nonce')) {
            wp_send_json_error(['message' => 'اعتبار صفحه به پایان رسیده؛ صفحه را تازه و دوباره پیگیری کنید.', 'code' => 'expired_nonce'], 403);
            return;
        }
        $input = $_POST['tracking_code'] ?? null;
        if (!is_string($input) || strlen($input) > 256) {
            wp_send_json_error(['message' => 'کد رهگیری باید شامل ۲۴ رقم باشد.', 'code' => 'validation'], 400);
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
            wp_send_json_error(['message' => $messages[$error->getKind()] ?? 'دریافت سابقه مرسوله انجام نشد؛ کمی بعد دوباره تلاش کنید.', 'code' => $error->getKind()]);
            return;
        } catch (\Throwable $error) {
            wp_send_json_error(['message' => 'دریافت سابقه مرسوله انجام نشد؛ کمی بعد دوباره تلاش کنید.', 'code' => 'unavailable']);
            return;
        }
        wp_send_json_success($result);
    }
}
