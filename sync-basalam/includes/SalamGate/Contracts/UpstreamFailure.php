<?php
namespace SyncBasalam\SalamGate\Contracts;

use SyncBasalam\SalamGate\SalamGateException;

defined('ABSPATH') || exit;

/** Only this compatibility adapter interprets provider messages; consumers use stable codes. */
final class UpstreamFailure
{
    public static function code(SalamGateException $error): string
    {
        if (strpos($error->firstMessage(), 'already-paid') !== false) return 'already_paid';
        foreach ($error->getErrors() as $field) {
            $message = $field['message'] . ' ' . $field['location'];
            if (strpos($message, 'already-paid') !== false || strpos($message, 'قبلاً پرداخت') !== false) return 'already_paid';
        }
        return $error->getKind();
    }

    public static function insufficientBalance(SalamGateException $error, int $method): bool
    {
        $expected = [PayMethods::PARCEL_VENDOR_BALANCE => ['vendor_balance', 'تراز غرفه کافی نیست.'],
            PayMethods::PARCEL_WALLET => ['user_wallet', 'موجودی کیف پول کافی نیست.']][$method] ?? null;
        $fields = $error->getErrors();
        return $expected !== null && count($fields) === 1 && $fields[0]['location'] === $expected[0]
            && trim($fields[0]['message']) === $expected[1];
    }
}
