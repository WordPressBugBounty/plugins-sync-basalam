<?php

namespace SyncBasalam\SalamGate\Services;

use SyncBasalam\SalamGate\ClientFactory;
use SyncBasalam\SalamGate\SalamGateException;

if (! defined('ABSPATH')) exit;

/**
 * کش وابسته به هویت اتصال (حساب بسالم فعال).
 *
 * داده‌های حساب (غرفه‌ها، پروفایل، stats، دسته‌ها با is_default وابسته به کاربر)
 * نباید میان حساب‌ها به اشتراک گذاشته شوند. کلید کش = نام + هش کوتاه توکن اتصال؛
 * توکن خام هرگز در نام کش یا log ذخیره نمی‌شود (فقط ۱۲ کاراکتر اول SHA-1).
 * تعویض حساب → کلید متفاوت → کش حساب قبلی به‌طور خودکار بی‌اثر می‌شود.
 */
final class ConnectionCache
{
    private const PREFIX = 'salam_gate_conn_';

    /**
     * کلید کش برای اتصال فعلی.
     */
    public static function key(string $name): string
    {
        return self::PREFIX . $name . '_' . self::identityHash();
    }

    public static function get(string $name)
    {
        $identity = self::identityHash();
        $cached = self::read(self::PREFIX . $name . '_' . $identity);
        if ($cached !== null) {
            self::assertCurrentIdentity($identity);
            $cached['connection_hash'] = $identity;
        }
        return $cached;
    }

    /**
     * Presentation-only cache lookup without token refresh or HTTP. The saved
     * token must be read server-side, never accepted from a browser request.
     */
    public static function getForToken(string $name, string $token)
    {
        if ($token === '') {
            return null;
        }

        return self::read(self::PREFIX . $name . '_' . substr(sha1($token), 0, 12));
    }

    private static function read(string $key)
    {
        $value = get_option($key, null);

        if (!is_array($value)) {
            return null;
        }

        // TTL داخلی (سازگار با کش‌هایی که قبلاً expires_at داشتند)
        if (isset($value['expires_at']) && (int) $value['expires_at'] <= time()) {
            return null;
        }

        return $value;
    }

    public static function set(string $name, array $value, int $ttl): void
    {
        update_option(self::key($name), [
            'expires_at' => time() + $ttl,
            'data'       => $value,
        ], false);
    }

    /** Cache only under the identity that actually authenticated the response. */
    public static function setForIdentity(string $name, array $value, int $ttl, string $identity): void
    {
        self::assertCurrentIdentity($identity);
        update_option(self::PREFIX . $name . '_' . $identity, [
            'expires_at' => time() + $ttl,
            'data' => $value,
        ], false);
        // Reconnection during an option write still cannot publish the old response.
        self::assertCurrentIdentity($identity);
    }

    public static function responseIdentity(array $response, string $fallback): string
    {
        $identity = $response['connection_hash'] ?? $fallback;
        return is_string($identity) && preg_match('/^[a-f0-9]{12}$/D', $identity) ? $identity : $fallback;
    }

    public static function assertCurrentIdentity(string $identity): void
    {
        if (!preg_match('/^[a-f0-9]{12}$/D', $identity) || self::savedIdentityHash() !== $identity) {
            throw new SalamGateException(
                'اتصال باسلام هنگام دریافت اطلاعات تغییر کرد؛ دوباره تلاش کنید.',
                0,
                [],
                SalamGateException::KIND_UNEXPECTED_RESPONSE
            );
        }
    }

    /** No HTTP/preflight here; bypass a worker's stale WordPress option cache. */
    private static function savedIdentityHash(): string
    {
        global $wpdb;
        if (is_object($wpdb) && isset($wpdb->options) && method_exists($wpdb, 'get_var') && method_exists($wpdb, 'prepare')) {
            $raw = $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                'sync_basalam_settings'
            ));
            $settings = $raw === null ? [] : maybe_unserialize($raw);
            $token = is_array($settings) ? ($settings['token'] ?? null) : null;
            return substr(sha1(is_string($token) && $token !== '' ? $token : 'anonymous'), 0, 12);
        }
        // Test adapters and old environments without a WordPress datastore.
        return self::identityHash();
    }

    public static function delete(string $name): void
    {
        delete_option(self::key($name));
    }

    public static function deleteForIdentity(string $name, string $identity): void
    {
        if (preg_match('/^[a-f0-9]{12}$/D', $identity)) delete_option(self::PREFIX . $name . '_' . $identity);
    }

    /**
     * هش کوتاه هویت اتصال — ۱۲ کاراکتر هگز SHA-1 توکن (غیرقابل بازگشت به توکن).
     */
    public static function identityHash(): string
    {
        try {
            $token = ClientFactory::make()->token() ?? 'anonymous';
        } catch (\Throwable $exception) {
            $token = 'anonymous';
        }

        return substr(sha1($token), 0, 12);
    }
}
