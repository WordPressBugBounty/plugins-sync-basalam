<?php

namespace SyncBasalam\SalamGate\Services;

use SyncBasalam\SalamGate\Contracts\PayMethods;
use SyncBasalam\SalamGate\SalamGateException;

defined('ABSPATH') || exit;

/** Explicit pre-charge rejection, not a guess from an unsuccessful HTTP request. */
final class PaymentRejection
{
    /** A locally rejected token preflight did not send any HTTP payment request. */
    public static function notDispatchedPayload(SalamGateException $exception, int $parcelId): ?array
    {
        if (!$exception->isDefinitelyNotDispatched()) { return null; }
        return ['message' => $exception->firstMessage() . ' درخواست پرداخت ارسال نشد.',
            'code' => $exception->isKind(SalamGateException::KIND_UNAUTHENTICATED) ? 'connection_required' : 'preflight_unavailable',
            'kind' => $exception->getKind(), 'errors' => $exception->getErrors(), 'parcel_id' => $parcelId,
            'request_dispatched' => false, 'requires_confirmation' => true];
    }

    /**
     * Called only after ParcelService::pay throws. The public service's exact
     * 422 balance validations run before payhub create_payment/call_payment_url.
     * A generic vendor_balance error can also mean a missing vendor, and a
     * parcel_shipping_payment error may occur after a charge: neither is proof.
     * The claims must have been released by their original owner before the UI
     * may offer another method. This does NOT authorize an automatic retry.
     */
    public static function insufficientFundsPayload(SalamGateException $exception, int $parcelId, int $method, array $baseline): ?array
    {
        if (!self::isConfirmedPreCharge($exception, $parcelId, $method, $baseline)) { return null; }
        $errors = $exception->getErrors();
        // Never make an uncleared claim or a sibling's uncertain/paid attempt
        // retryable, even if its worker received this exact rejection.
        if (get_option(ParcelService::paymentOutcomeKey($parcelId), null) !== null
            || WebsitePaymentGuard::blockForParcel($baseline) !== null) {
            return null;
        }

        return [
            'message' => $method === PayMethods::PARCEL_VENDOR_BALANCE
                ? 'موجودی تراز غرفه کافی نیست؛ روش پرداخت را تغییر دهید یا موجودی را افزایش دهید.'
                : 'موجودی کیف پول کافی نیست؛ کیف پول را شارژ کنید یا روش پرداخت را تغییر دهید.',
            'code' => 'insufficient_funds', 'kind' => SalamGateException::KIND_VALIDATION,
            'errors' => $errors, 'parcel_id' => $parcelId, 'payment_method' => $method,
            'payment_state' => 'unpaid', 'safe_to_retry' => true, 'no_charge_confirmed' => true,
            'requires_confirmation' => true, 'recovery' => 'review_payment_method',
            // Salam Gate pay POST was dispatched; its pre-charge guard rejected it.
            'request_dispatched' => true,
        ];
    }

    /**
     * Only these exact documented upstream validations prove no charge was attempted.
     * HTTP 4xx alone (including parcel_shipping_payment) is not financial evidence.
     * This classifier does not inspect claims: ParcelService calls it while owning them.
     */
    public static function isConfirmedPreCharge(SalamGateException $exception, int $parcelId, int $method, array $baseline): bool
    {
        if ($exception->getStatus() !== 422 || !$exception->isKind(SalamGateException::KIND_VALIDATION)
            || ParcelService::positiveId($baseline['id'] ?? null) !== $parcelId
            || !in_array($baseline['market'] ?? null, ParcelsListService::ALL_MARKETS, true) || $baseline['market'] === 'basalam'
            || !PayMethods::isValidParcelMethodForMarket($method, $baseline['market'])) {
            return false;
        }
        $expected = [
            PayMethods::PARCEL_VENDOR_BALANCE => ['vendor_balance', 'تراز غرفه کافی نیست.'],
            PayMethods::PARCEL_WALLET => ['user_wallet', 'موجودی کیف پول کافی نیست.'],
        ][$method] ?? null;
        $errors = $exception->getErrors();
        if ($expected === null || count($errors) !== 1
            || $errors[0]['location'] !== $expected[0] || trim($errors[0]['message']) !== $expected[1]) {
            return false;
        }
        return true;
    }
}
