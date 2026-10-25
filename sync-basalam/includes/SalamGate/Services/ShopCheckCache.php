<?php

namespace SyncBasalam\SalamGate\Services;

use SyncBasalam\SalamGate\Auth\OAuthScopes;
use SyncBasalam\SalamGate\SalamGateException;

defined('ABSPATH') || exit;

/** Token-bound backoff for account reads, not an authorization or payment cache. */
final class ShopCheckCache
{
    public const CACHE_NAME = 'shop_check_failure';
    public const MISSING_SCOPE_TTL = 21600; // Automatic probes: at most once per six hours.
    public const RETRY_DELAY = 60;          // Explicit retries cannot hammer the same token.
    public const TRANSIENT_DELAY = 30;
    public const FRESH_COOLDOWN = 10;

    public static function forToken(string $token, bool $fresh = false): ?SalamGateException
    {
        $cached = ConnectionCache::getForToken(self::CACHE_NAME, $token);
        $data = $cached['data'] ?? null;
        if (!is_array($data)) return null;
        // Only explicit account rechecks may bypass the longer consent cache.
        if ($fresh && !empty($data['missing_scope']) && (int) ($data['retry_at'] ?? PHP_INT_MAX) <= time()) return null;
        $kind = $data['kind'] ?? '';
        $missing = $kind === SalamGateException::KIND_FORBIDDEN && !empty($data['missing_scope']);
        if ($kind === SalamGateException::KIND_FORBIDDEN) {
            $message = $missing ? OAuthScopes::CONSENT_MESSAGE
                : 'مجوز دسترسی به سلام‌گیت تأیید نشد. ' . OAuthScopes::CONSENT_MESSAGE;
        } elseif ($kind === SalamGateException::KIND_UNAUTHENTICATED) {
            $message = 'اعتبار اتصال باسلام تأیید نشد؛ از تنظیمات ووسلام دوباره متصل شوید.';
        } else {
            $message = 'بررسی وضعیت سلام‌گیت انجام نشد؛ کمی بعد دوباره تلاش کنید.';
        }
        return new SalamGateException($message, (int) ($data['status'] ?? 0),
            $missing ? [['location' => 'auth.scope', 'message' => $message]] : [], $kind, true);
    }

    public static function remember(SalamGateException $error, string $identity): void
    {
        ConnectionCache::assertCurrentIdentity($identity);
        $kind = $error->getKind();
        $missing = $kind === SalamGateException::KIND_FORBIDDEN && $error->errorsAt('auth.scope') !== [];
        $auth = in_array($kind, [SalamGateException::KIND_FORBIDDEN, SalamGateException::KIND_UNAUTHENTICATED], true);
        if ($auth) {
            // A known denial must also hide the last successful order-screen snapshot.
            ConnectionCache::deleteForIdentity(ShopsService::CACHE_NAME, $identity);
        }
        ConnectionCache::setForIdentity(self::CACHE_NAME, [
            'kind' => $kind, 'status' => $error->getStatus(), 'missing_scope' => $missing,
            'retry_at' => time() + self::RETRY_DELAY,
        ], $missing ? self::MISSING_SCOPE_TTL : ($auth ? self::RETRY_DELAY : self::TRANSIENT_DELAY), $identity);
    }

    public static function clear(string $identity): void
    {
        ConnectionCache::deleteForIdentity(self::CACHE_NAME, $identity);
    }
}
