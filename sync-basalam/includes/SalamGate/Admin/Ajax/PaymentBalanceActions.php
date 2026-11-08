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
        ActionGuard::forController(self::class);

        $input = $_POST['method'] ?? null;
        if (!in_array($input, [PayMethods::PARCEL_VENDOR_BALANCE, PayMethods::PARCEL_WALLET, '20', '21'], true)) {
            ResponseBoundary::error(['message' => 'روش پرداخت نامعتبر است.', 'code' => 'validation'], 400);
            return;
        }
        $method = (int) $input;

        try {
            $fresh = in_array($_POST['fresh'] ?? null, [1, '1'], true);
            $result = (new PaymentBalanceService())->get($method, $fresh);
        } catch (SalamGateException $error) {
            // Treat upstream failure as a display failure, not an instruction
            // to disconnect or erase an otherwise working SalamGate account.
            ResponseBoundary::error(['method' => $method, 'message' => $error->getMessage(), 'code' => $error->getKind()]);
            return;
        } catch (\Throwable $error) {
            // Unexpected exceptions may contain raw transport/credential text.
            ResponseBoundary::error(['method' => $method, 'message' => 'دریافت موجودی انجام نشد؛ کمی بعد دوباره تلاش کنید.', 'code' => 'unavailable']);
            return;
        }
        ResponseBoundary::success($result);
    }
}
