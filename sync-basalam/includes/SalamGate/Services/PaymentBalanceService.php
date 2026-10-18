<?php

namespace SyncBasalam\SalamGate\Services;

use SyncBasalam\Admin\Settings\OAuthTokenRefresher;
use SyncBasalam\SalamGate\ClientFactory;
use SyncBasalam\SalamGate\Contracts\PayMethods;
use SyncBasalam\SalamGate\SalamGateException;

defined('ABSPATH') || exit;

/** Read-only balances for the connected account; never a payment authorization. */
final class PaymentBalanceService
{
    public const VENDOR_URL = 'https://services.basalam.com/web/v1/accounting/financial/client/transaction/balance';
    public const WALLET_URL = 'https://services.basalam.com/web/v1/wallet/public/balance/settlement';
    public const CACHE_TTL = 30;
    private const MAX_SAFE_RIAL = 9007199254740991;

    /**
     * @return array{method:int, balance_rial:int, balance_toman:int|float, fetched_at:int}
     * @throws SalamGateException
     */
    public function get(int $method, bool $fresh = false): array
    {
        if (!in_array($method, [PayMethods::PARCEL_VENDOR_BALANCE, PayMethods::PARCEL_WALLET], true)) {
            throw new SalamGateException('روش پرداخت نامعتبر است.', 422, [], SalamGateException::KIND_VALIDATION);
        }

        // Reuse the shared OAuth manager, including its safe expiry preflight.
        // No token, URL, account ID or browser cookie is accepted from the form.
        $token = ClientFactory::make()->token();
        if (!is_string($token) || $token === '') {
            throw new SalamGateException('برای دریافت موجودی، اتصال باسلام را برقرار کنید.', 401);
        }

        $key = self::cacheKey($method, $token);
        $cached = get_transient($key);
        if (!$fresh && is_array($cached) && ($cached['method'] ?? null) === $method
            && self::rial($cached['balance_rial'] ?? null) !== null
            && is_int($cached['fetched_at'] ?? null)
            && $cached['fetched_at'] > time() - self::CACHE_TTL) {
            return self::result($method, (int) $cached['balance_rial'], $cached['fetched_at']);
        }

        $url = $method === PayMethods::PARCEL_WALLET ? self::WALLET_URL : self::VENDOR_URL;
        $args = [
            'method' => 'GET',
            'timeout' => 20,
            'sslverify' => true,
            // Authenticated financial requests must never forward credentials.
            'redirection' => 0,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'User-Agent' => 'Woosalam-Pro',
            ],
        ];
        $response = wp_remote_request($url, $args);
        if (!is_wp_error($response)
            && (int) wp_remote_retrieve_response_code($response) === 401
            && class_exists(OAuthTokenRefresher::class)) {
            $freshToken = OAuthTokenRefresher::refresh($token);
            if ($freshToken !== null && $freshToken !== '' && $freshToken !== $token) {
                $token = $freshToken;
                $args['headers']['Authorization'] = 'Bearer ' . $token;
                // A single retry after a definite 401, never on timeout or 403.
                $response = wp_remote_request($url, $args);
                $key = self::cacheKey($method, $token);
            }
        }

        if (is_wp_error($response)) {
            throw new SalamGateException('دریافت موجودی از باسلام انجام نشد؛ کمی بعد دوباره تلاش کنید.', 0);
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            throw self::requestError($status);
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body) || json_last_error() !== JSON_ERROR_NONE
            || ($body['backend_http_status_code'] ?? null) !== 200) {
            // A gateway may send HTTP 200 while its underlying API failed.
            $backendStatus = is_array($body) && is_int($body['backend_http_status_code'] ?? null)
                ? $body['backend_http_status_code'] : 0;
            if ($backendStatus >= 400 && $backendStatus <= 599) {
                throw self::requestError($backendStatus);
            }
            throw self::invalidResponse();
        }

        $value = $method === PayMethods::PARCEL_WALLET
            ? (is_array($body['customer'] ?? null) ? ($body['customer']['total'] ?? null) : null)
            : ($body['balance'] ?? null);
        $rial = self::rial($value);
        if ($rial === null) {
            throw self::invalidResponse();
        }

        $result = self::result($method, $rial, time());
        set_transient($key, $result, self::CACHE_TTL);
        return $result;
    }

    private static function cacheKey(int $method, string $token): string
    {
        // A rotated token or another connection cannot reuse the old balance.
        // Only a digest is stored in the transient key; never the credential.
        return 'salam_gate_balance_' . $method . '_' . hash('sha256', $token);
    }

    private static function result(int $method, int $rial, int $fetchedAt): array
    {
        return [
            'method' => $method,
            'balance_rial' => $rial,
            'balance_toman' => $rial / 10,
            'fetched_at' => $fetchedAt,
        ];
    }

    private static function rial($value): ?int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value))) {
            return null;
        }
        if ((float) $value > self::MAX_SAFE_RIAL || (float) $value < -self::MAX_SAFE_RIAL) {
            return null;
        }
        return (int) $value;
    }

    private static function invalidResponse(): SalamGateException
    {
        return new SalamGateException('موجودی معتبر از باسلام دریافت نشد؛ کمی بعد دوباره تلاش کنید.', 200, [], SalamGateException::KIND_UNEXPECTED_RESPONSE);
    }

    private static function requestError(int $status): SalamGateException
    {
        $message = $status === 401
            ? 'دریافت موجودی انجام نشد؛ اعتبار اتصال باسلام را بررسی کنید.'
            : ($status === 403
                ? 'دسترسی اتصال فعلی به موجودی باسلام مجاز نیست.'
                : 'دریافت موجودی از باسلام انجام نشد؛ کمی بعد دوباره تلاش کنید.');
        return new SalamGateException($message, $status);
    }
}
