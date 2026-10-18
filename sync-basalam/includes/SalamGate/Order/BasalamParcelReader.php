<?php

namespace SyncBasalam\SalamGate\Order;

use SyncBasalam\SalamGate\ClientFactory;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\Admin\Settings\OAuthTokenRefresher;

defined('ABSPATH') || exit;

/** Only fixed, authenticated, read-only order-processing endpoints. */
final class BasalamParcelReader
{
    /** @internal Test injection; never accepts a URL or token from the browser. */
    public static $resolver = null;

    public static function order(int $vendorId, int $orderId): array
    {
        return self::get('/v2/vendors/' . $vendorId . '/orders/' . $orderId);
    }

    public static function parcel(int $parcelId): array
    {
        return self::get('/v3/vendor-parcels/' . $parcelId);
    }

    /** The same cursor contract already used by the base order importer. */
    public static function orders(int $days = 90, ?string $cursor = null): array
    {
        if ($days < 1 || $days > 90 || ($cursor !== null
            && (strlen($cursor) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $cursor)))) {
            throw new SalamGateException('درخواست فهرست سفارش‌های باسلام معتبر نیست.', 422, [], SalamGateException::KIND_VALIDATION);
        }
        $query = ['per_page' => 10, 'created_at[gte]' => gmdate('c', time() - $days * 86400)];
        if ($cursor !== null && $cursor !== '') { $query['cursor'] = $cursor; }
        return self::get('/v1/vendor-parcels?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986), true);
    }

    private static function get(string $path, bool $openapi = false): array
    {
        if (self::$resolver !== null) {
            $body = (self::$resolver)($path);
            if (!is_array($body)) {
                throw self::invalid();
            }
            return $body;
        }
        $token = ClientFactory::make()->token();
        if (!is_string($token) || $token === '') {
            throw new SalamGateException('برای بررسی سفارش، اتصال باسلام را برقرار کنید.', 401);
        }
        $args = [
            'method' => 'GET', 'timeout' => 20, 'sslverify' => true, 'redirection' => 0,
            'headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $token, 'User-Agent' => 'Woosalam-Pro'],
        ];
        $url = ($openapi ? 'https://openapi.basalam.com' : 'https://order-processing.basalam.com') . $path;
        $response = wp_remote_request($url, $args);
        if (!is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 401
            && class_exists(OAuthTokenRefresher::class)) {
            $fresh = OAuthTokenRefresher::refresh($token);
            if (is_string($fresh) && $fresh !== '' && $fresh !== $token) {
                $args['headers']['Authorization'] = 'Bearer ' . $fresh;
                $response = wp_remote_request($url, $args);
            }
        }
        if (is_wp_error($response)) {
            throw new SalamGateException('دریافت مشخصات سفارش باسلام انجام نشد؛ دوباره بررسی کنید.', 0, [], SalamGateException::KIND_TRANSPORT);
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            throw new SalamGateException('مشخصات مرسوله سفارش باسلام تأیید نشد؛ اتصال و دسترسی سفارش‌ها را بررسی کنید.', $code);
        }
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body) || json_last_error() !== JSON_ERROR_NONE) {
            throw self::invalid();
        }
        return $body;
    }

    private static function invalid(): SalamGateException
    {
        return new SalamGateException('پاسخ مشخصات سفارش باسلام معتبر نیست؛ دوباره بررسی کنید.', 200, [], SalamGateException::KIND_UNEXPECTED_RESPONSE);
    }
}
