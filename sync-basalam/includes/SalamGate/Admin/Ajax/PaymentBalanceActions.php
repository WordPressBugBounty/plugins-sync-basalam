<?php

namespace SyncBasalam\SalamGate\Admin\Ajax;

use SyncBasalam\SalamGate\Contracts\PayMethods;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\PaymentBalanceService;

defined('ABSPATH') || exit;

/** The authorized AJAX boundary exposes amounts only, never upstream credentials. */
final class PaymentBalanceActions
{
    public static function balance(): void
    {
        $nonce = isset($_POST['_wpnonce']) && is_string($_POST['_wpnonce'])
            ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
        if (!current_user_can('manage_woocommerce') || !wp_verify_nonce($nonce, 'basalam_pro_nonce')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز!'], 403);
            return;
        }

        $input = $_POST['method'] ?? null;
        if (!in_array($input, [PayMethods::PARCEL_VENDOR_BALANCE, PayMethods::PARCEL_WALLET, '20', '21'], true)) {
            wp_send_json_error(['message' => 'روش پرداخت نامعتبر است.', 'code' => 'validation'], 400);
            return;
        }
        $method = (int) $input;

        try {
            $fresh = in_array($_POST['fresh'] ?? null, [1, '1'], true);
            $result = (new PaymentBalanceService())->get($method, $fresh);
        } catch (SalamGateException $error) {
            // Treat upstream failure as a display failure, not an instruction
            // to disconnect or erase an otherwise working SalamGate account.
            wp_send_json_error(['method' => $method, 'message' => $error->getMessage(), 'code' => $error->getKind()]);
            return;
        } catch (\Throwable $error) {
            // Unexpected exceptions may contain raw transport/credential text.
            wp_send_json_error(['method' => $method, 'message' => 'دریافت موجودی انجام نشد؛ کمی بعد دوباره تلاش کنید.', 'code' => 'unavailable']);
            return;
        }
        wp_send_json_success($result);
    }
}
