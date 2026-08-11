<?php

namespace SyncBasalam\Utilities;

defined('ABSPATH') || exit;

/**
 * Builds the dedicated site-access API payload without modifying ticket prose.
 * Passwords are never written to options/logs and ordinary spaces are kept.
 */
class TicketAccessData
{
    public static function fromRequest(array $request): array
    {
        $payload = ['domain' => untrailingslashit(get_site_url())];

        $wordpress = self::section($request, [
            'login_url' => 'dashboard_login_url',
            'username' => 'dashboard_username',
            'password' => 'dashboard_password',
        ], 'پیشخوان وردپرس');

        $hostPanel = self::section($request, [
            'login_url' => 'host_panel_login_url',
            'username' => 'host_panel_username',
            'password' => 'host_panel_password',
        ], 'کنترل پنل هاست');

        if ($wordpress !== null) $payload['wordpress'] = $wordpress;
        if ($hostPanel !== null) $payload['host_panel'] = $hostPanel;

        return count($payload) > 1 ? $payload : [];
    }

    private static function section(array $request, array $fields, string $label): ?array
    {
        $rawLoginUrl = isset($request[$fields['login_url']])
            ? trim((string) wp_unslash($request[$fields['login_url']]))
            : '';
        $loginUrl = $rawLoginUrl === '' ? null : esc_url_raw($rawLoginUrl);
        if ($rawLoginUrl !== '' && $loginUrl === '') {
            throw new \InvalidArgumentException('آدرس ورود ' . $label . ' معتبر نیست.');
        }

        $username = isset($request[$fields['username']])
            ? trim(sanitize_text_field(wp_unslash($request[$fields['username']])))
            : '';
        $password = isset($request[$fields['password']])
            ? self::password($request[$fields['password']])
            : '';

        $hasAnyValue = $rawLoginUrl !== '' || $username !== '' || $password !== '';
        if (!$hasAnyValue) return null;

        if ($username === '' || $password === '') {
            throw new \InvalidArgumentException(
                'برای ' . $label . ' نام کاربری و رمز عبور را کامل وارد کنید.'
            );
        }

        return [
            'login_url' => $loginUrl,
            'username' => $username,
            'password' => $password,
        ];
    }

    private static function password($value): string
    {
        $password = wp_check_invalid_utf8(wp_unslash((string) $value));
        if (mb_strlen($password) > 1024) {
            throw new \InvalidArgumentException('رمز عبور طولانی‌تر از حد مجاز است.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/u', $password)) {
            throw new \InvalidArgumentException('رمز عبور دارای نویسه کنترلی نامعتبر است.');
        }
        return $password;
    }
}
