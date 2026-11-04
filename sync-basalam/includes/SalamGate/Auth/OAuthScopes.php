<?php

namespace SyncBasalam\SalamGate\Auth;

defined('ABSPATH') || exit;

/** Recognize missing SalamGate consent; requested scopes live in OAuthManager. */
final class OAuthScopes
{
    public const REQUIRED = ['vendor.salamgate.read', 'vendor.salamgate.write'];
    public const CONSENT_MESSAGE = 'دسترسی‌های حساب را تأیید کنید.';

    /** Recognize explicit provider evidence only; other 403s are not missing consent. */
    public static function isMissingRequiredScope(array $body): bool
    {
        $messages = [$body['message'] ?? null, $body['detail'] ?? null];
        if (is_array($body['detail'] ?? null)) $messages[] = $body['detail']['message'] ?? null;
        foreach (is_array($body['errors'] ?? null) ? $body['errors'] : [] as $error) {
            if (is_array($error)) $messages[] = $error['message'] ?? null;
        }
        foreach ($messages as $message) {
            if (!is_string($message)) continue;
            foreach (self::REQUIRED as $scope) {
                if (trim($message) === 'Missing required scope: ' . $scope) return true;
            }
        }
        return false;
    }
}
