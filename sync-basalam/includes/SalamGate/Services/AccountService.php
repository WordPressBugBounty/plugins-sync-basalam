<?php

namespace SyncBasalam\SalamGate\Services;

use SyncBasalam\SalamGate\ClientFactory;
use SyncBasalam\SalamGate\SalamGateClient;
use SyncBasalam\SalamGate\SalamGateException;

if (! defined('ABSPATH')) exit;

/**
 * حساب کاربری سلام‌گیت: پروفایل و آمار — کش per-account.
 *
 * سه حالت «ثبت‌نشده»، «خطای دریافت» و «لیست خالی» از هم تفکیک می‌شوند:
 * - profile(): ['user'=>..., 'registered'=>bool] — 404 یعنی هنوز ثبت‌نام نشده،
 *   نه خطای شبکه (خطای شبکه همان SalamGateException با kind transport می‌ماند).
 */
class AccountService
{
    public const PROFILE_CACHE = 'profile';
    public const STATS_CACHE   = 'stats';
    public const CACHE_TTL     = 300;
    private ?string $profileIdentity = null;

    /** Identity fence for a multi-read response. Token rotation is accepted only
     * after a fresh server profile proves it still belongs to the same user. */
    public function assertSnapshot(array $profile): void
    {
        if ($this->profileIdentity === null) {
            throw new SalamGateException('هویت اتصال تأیید نشد؛ دوباره تلاش کنید.', 0, [], SalamGateException::KIND_UNEXPECTED_RESPONSE);
        }
        try {
            ConnectionCache::assertCurrentIdentity($this->profileIdentity);
            return;
        } catch (SalamGateException $exception) {
            $previousIdentity = $this->profileIdentity;
            $current = $this->profile(true);
            if (($profile['registered'] ?? false) !== true || ($current['registered'] ?? false) !== true
                || ($profile['user']['id'] ?? null) !== ($current['user']['id'] ?? null)) {
                $this->profileIdentity = $previousIdentity;
                throw $exception;
            }
            ConnectionCache::assertCurrentIdentity($this->profileIdentity);
        }
    }

    /**
     * پروفایل کاربر (GET /v1/users/profile).
     *
     * @return array{user: ?array{id:int, mobile:?string, national_code:?string, national_card_serial:?string}, registered: bool}
     * @throws SalamGateException خطای غیر از 404
     */
    public function profile(bool $fresh = false): array
    {
        if (!$fresh) {
            $cached = ConnectionCache::get(self::PROFILE_CACHE);
            if ($cached !== null && isset($cached['data']['profile'])) {
                $this->profileIdentity = $cached['connection_hash'];
                return $cached['data']['profile'];
            }
        }

        $identity = ConnectionCache::identityHash();
        $client = ClientFactory::make();
        try {
            $response = $client->get('/v1/users/profile');
            $identity = ConnectionCache::responseIdentity($response, $identity);
            ConnectionCache::assertCurrentIdentity($identity);
            $body = is_array($response['body'] ?? null) ? $response['body'] : [];

            $userId = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($userId === false) {
                throw new SalamGateException(
                    'پاسخ پروفایل سلام‌گیت نامعتبر است؛ وضعیت ثبت‌نام تأیید نشد.',
                    (int) ($response['code'] ?? 200),
                    [],
                    SalamGateException::KIND_UNEXPECTED_RESPONSE
                );
            }

            $profile = [
                'user' => [
                    'id'                   => $userId,
                    'mobile'               => isset($body['mobile']) ? (string) $body['mobile'] : null,
                    'national_code'        => isset($body['national_code']) ? (string) $body['national_code'] : null,
                    'national_card_serial' => isset($body['national_card_serial']) ? (string) $body['national_card_serial'] : null,
                ],
                'registered' => true,
            ];
        } catch (SalamGateException $exception) {
            // 404 = هنوز ثبت‌نام نشده — حالت معتبر، نه خطا.
            if ($exception->isKind(SalamGateException::KIND_NOT_FOUND)) {
                $identity = $client->lastRequestIdentityHash() ?? $identity;
                ConnectionCache::assertCurrentIdentity($identity);
                $profile = ['user' => null, 'registered' => false];
            } else {
                throw $exception;
            }
        }

        ConnectionCache::setForIdentity(self::PROFILE_CACHE, ['profile' => $profile], self::CACHE_TTL, $identity);
        $this->profileIdentity = $identity;

        return $profile;
    }

    /**
     * آمار عملکرد کاربر (GET /v1/users/{own_id}/stats) — نیازمند شناسه پروفایل خود کاربر.
     *
     * @return array{order_count:int, avg_shipping_cost:?float, total_profit:?float}
     * @throws SalamGateException
     */
    public function stats(bool $fresh = false): array
    {
        if (!$fresh) {
            $cached = ConnectionCache::get(self::STATS_CACHE);
            if ($cached !== null && isset($cached['data']['stats'])) {
                return $cached['data']['stats'];
            }
        }

        $profile = $this->profile($fresh);
        $identity = $this->profileIdentity;
        ConnectionCache::assertCurrentIdentity($identity);
        $userId = (int) ($profile['user']['id'] ?? 0);
        if ($userId <= 0) {
            return ['order_count' => 0, 'avg_shipping_cost' => null, 'total_profit' => null];
        }

        $response = ClientFactory::make()->get(sprintf('/v1/users/%d/stats', $userId));
        $identity = ConnectionCache::responseIdentity($response, $identity);
        ConnectionCache::assertCurrentIdentity($identity);
        if ($identity !== $this->profileIdentity
            && (int) ($this->profile(true)['user']['id'] ?? 0) !== $userId) {
            throw new SalamGateException('اتصال باسلام هنگام دریافت آمار تغییر کرد؛ دوباره تلاش کنید.', 0, [], SalamGateException::KIND_UNEXPECTED_RESPONSE);
        }
        $raw = is_array($response['body']['stats'] ?? null) ? $response['body']['stats'] : [];

        $responseUserId = filter_var($response['body']['user_id'] ?? null, FILTER_VALIDATE_INT);
        $orderCount = filter_var($raw['order_count'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($responseUserId !== $userId || $orderCount === false
            || (isset($raw['avg_shipping_cost']) && !is_numeric($raw['avg_shipping_cost']))
            || (isset($raw['total_profit']) && !is_numeric($raw['total_profit']))) {
            throw new SalamGateException(
                'پاسخ آمار سلام‌گیت نامعتبر است؛ آمار حساب تأیید نشد.',
                (int) ($response['code'] ?? 200),
                [],
                SalamGateException::KIND_UNEXPECTED_RESPONSE
            );
        }

        $stats = [
            'order_count'      => $orderCount,
            'avg_shipping_cost' => isset($raw['avg_shipping_cost']) ? (float) $raw['avg_shipping_cost'] : null,
            'total_profit'     => isset($raw['total_profit']) ? (float) $raw['total_profit'] : null,
        ];

        ConnectionCache::setForIdentity(self::STATS_CACHE, ['stats' => $stats], self::CACHE_TTL, $identity);

        return $stats;
    }
}
