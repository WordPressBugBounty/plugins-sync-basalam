<?php

namespace SyncBasalam\SalamGate\Services;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\SalamGate\ClientFactory;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\ShopsService;
use SyncBasalam\SalamGate\Services\ConnectionCache;
use SyncBasalam\SalamGate\Services\AccountService;
use SyncBasalam\SalamGate\Services\SiteActivation;
use SyncBasalam\SalamGate\Services\ShopCheckCache;

if (! defined('ABSPATH')) exit;

final class AccountStateService extends \SyncBasalam\SalamGate\Domain\AccountStateResolver
{
    public static function resolveForPage(): array
    {
        if (!SiteActivation::isEnabled()) {
            return ['state' => self::STATE_DISABLED, 'shops' => [], 'message' => ''];
        }
        $token = syncBasalamSettings()->getSettings(SettingsConfig::TOKEN);
        if (!is_string($token) || trim($token) === '') {
            return ['state' => self::STATE_NOT_CONNECTED, 'shops' => [], 'message' => ''];
        }

        $failure = ShopCheckCache::forToken($token);
        if ($failure !== null) return self::errorState($failure);

        $cached = ConnectionCache::getForToken(ShopsService::CACHE_NAME, $token);
        if (isset($cached['data']['shops']) && is_array($cached['data']['shops'])) {
            $shops = $cached['data']['shops'];
            if ($shops === [] && in_array($cached['data']['registration_state'] ?? null, ['pending', 'unknown'], true)) {
                return self::registrationWaiting($cached['data']['registration_state']);
            }
            return ['state' => self::stateForShops($shops), 'shops' => $shops, 'message' => ''];
        }

        return ['state' => self::STATE_LOADING, 'shops' => [], 'message' => ''];
    }

    public static function resolveCurrent(bool $fresh = false): array
    {
        if (!SiteActivation::isEnabled()) {
            return ['state' => self::STATE_DISABLED, 'shops' => [], 'message' => ''];
        }
        try {
            $token = ClientFactory::make()->token();
        } catch (\Throwable $exception) {
            $token = null;
        }

        if ($token === null) {
            return ['state' => self::STATE_NOT_CONNECTED, 'shops' => [], 'message' => ''];
        }

        try {
            $service = new ShopsService();
            $identity = ConnectionCache::identityHash();
            $shops = $service->getShops($fresh);
            if ($shops === []) {
                $registration = $service->registrationState((new AccountService())->profile($fresh), $shops);
                ConnectionCache::assertCurrentIdentity($identity);
                if ($registration !== null) {
                    ConnectionCache::setForIdentity(ShopsService::CACHE_NAME, ['shops' => $shops, 'registration_state' => $registration], ShopsService::CACHE_TTL, $identity);
                    return self::registrationWaiting($registration);
                }
            }
        } catch (SalamGateException $exception) {
            return self::errorState($exception);
        }

        if (!SiteActivation::isEnabled()) {
            return ['state' => self::STATE_DISABLED, 'shops' => [], 'message' => ''];
        }
        return [
            'state' => self::stateForShops($shops),
            'shops' => $shops,
            'message' => '',
        ];
    }

    private static function errorState(SalamGateException $exception): array
    {
        $state = self::STATE_ERROR_NETWORK;
        if ($exception->isKind(SalamGateException::KIND_UNAUTHENTICATED)) $state = self::STATE_ERROR_AUTH;
        if ($exception->isKind(SalamGateException::KIND_FORBIDDEN)) $state = self::STATE_ERROR_FORBIDDEN;
        // A failed read is never proof of an unregistered account.
        return ['state' => $state, 'shops' => [], 'message' => $exception->getMessage(),
            'missing_scope' => $exception->errorsAt('auth.scope') !== []];
    }

    private static function registrationWaiting(string $registration): array
    {
        return [
            'state' => self::STATE_PENDING,
            'shops' => [],
            'message' => $registration === 'pending'
                ? 'درخواست فعال‌سازی ثبت شده و هنوز نتیجه نهایی آن در فهرست حساب‌ها ظاهر نشده است. دوباره ثبت یا پرداخت نکنید؛ وضعیت را بررسی کنید.'
                : 'نتیجه درخواست فعال‌سازی قبلی هنوز مشخص نیست. برای جلوگیری از پرداخت دوباره، ثبت را تکرار نکنید و وضعیت را بررسی کنید.',
        ];
    }
}
