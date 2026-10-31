<?php

namespace SyncBasalam\SalamGate\Services;

use SyncBasalam\SalamGate\ClientFactory;
use SyncBasalam\SalamGate\Contracts\ShopNormalizer;
use SyncBasalam\SalamGate\Contracts\ShopStatuses;
use SyncBasalam\SalamGate\SalamGateClient;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Utils\OptionLock;

defined('ABSPATH') || exit;

/**
 * چرخه فروشگاه سلام‌گیت — سه عملیات مستقل با قرارداد متفاوت:
 *
 * - ثبت اولیه: POST /v1/shops/register (اطلاعات هویتی کامل + آدرس + منطقه/مرکز + پرداخت 8/2)
 * - فعال‌سازی مجدد: POST /v1/shops/{id}/activate (فقط وضعیت 16 → 14؛ تأیید ثبت در دسترس نیست)
 * - ویرایش: PATCH /v1/shops/{id} (فقط provider_node_id و national_code_serial؛ تغییر واقعی → 15)
 *
 * کش غرفه‌ها per-account است (ConnectionCache) و با تعویض اتصال بی‌اثر می‌شود.
 */
class ShopsService
{
    private const REGISTRATION_PREFIX = 'salam_gate_shop_registration_';
    private bool $registrationSubmitted = false;
    /** نام کش نرمال‌شده غرفه‌ها برای اتصال فعلی. */
    public const CACHE_NAME = 'shops';
    public const CACHE_TTL = 600; // ۱۰ دقیقه
    private const RESULT_PREFIX = 'salam_gate_shop_registration_result_';

    /** نام option قدیمی — فقط برای پاک‌سازی مهاجرت. */
    public const LEGACY_CACHE_OPTION = 'salam_gate_shops_cache';

    /**
     * لیست نرمال‌شده همه غرفه‌ها (بدون فیلتر وضعیت) از کش حساب یا API.
     *
     * @return array<int, array> خروجی ShopNormalizer::normalize
     *
     * @throws SalamGateException
     */
    public function getShops(bool $fresh = false): array
    {
        $token = ClientFactory::make()->token();
        if ($token === null) {
            throw new SalamGateException('اتصال به باسلام برقرار نیست؛ از تنظیمات ووسلام دوباره متصل شوید.',
                401, [], SalamGateException::KIND_UNAUTHENTICATED, true);
        }
        $identity = substr(sha1($token), 0, 12);
        ConnectionCache::assertCurrentIdentity($identity);
        $failure = ShopCheckCache::forToken($token, $fresh);
        if ($failure !== null) throw $failure;
        $cached = $this->cachedShops($token, $fresh);
        if ($cached !== null) return $cached;

        $lockKey = 'salam_gate_shop_check_' . $identity;
        $lease = OptionLock::acquire($lockKey, 120);
        if ($lease === null) throw self::checkBusy();
        try {
            // Another worker may have completed between our first read and acquire.
            wp_cache_delete(ConnectionCache::key(ShopCheckCache::CACHE_NAME), 'options');
            wp_cache_delete(ConnectionCache::key(self::CACHE_NAME), 'options');
            $failure = ShopCheckCache::forToken($token, $fresh);
            if ($failure !== null) throw $failure;
            $cached = $this->cachedShops($token, $fresh);
            if ($cached !== null) return $cached;

            $renew = static function () use ($lockKey, &$lease): void {
                $next = OptionLock::renew($lockKey, $lease, 120);
                if ($next === null) throw self::checkBusy();
                $lease = $next;
            };
            try {
                $shops = $this->fetchShops($fresh, $identity, $renew);
            } catch (SalamGateException $exception) {
                $renew(); // An expired worker must not overwrite its successor's result.
                // A response is cached only for the credential that actually sent it.
                $failedIdentity = ClientFactory::make()->lastRequestIdentityHash() ?? $identity;
                ShopCheckCache::remember($exception, $failedIdentity);
                throw $exception;
            }
            ShopCheckCache::clear($identity);
            StatusSyncAccess::clear($identity);
            return $shops;
        } finally {
            OptionLock::release($lockKey, $lease);
        }
    }

    private function cachedShops(string $token, bool $fresh): ?array
    {
        $cached = ConnectionCache::getForToken(self::CACHE_NAME, $token);
        if (!isset($cached['data']['shops']) || !is_array($cached['data']['shops'])) return null;
        if ($fresh && (int) ($cached['data']['checked_at'] ?? 0) + ShopCheckCache::FRESH_COOLDOWN <= time()) return null;
        ConnectionCache::assertCurrentIdentity(substr(sha1($token), 0, 12));
        return $cached['data']['shops'];
    }

    private static function checkBusy(): SalamGateException
    {
        return new SalamGateException('بررسی وضعیت حساب در حال انجام است؛ کمی بعد دوباره تلاش کنید.',
            429, [], SalamGateException::KIND_RATE_LIMITED, true);
    }

    private function fetchShops(bool $fresh, string &$identity, callable $renew): array
    {
        $rawShops = [];
        $seenIds = [];
        $seenCursors = [];
        $cursor = null;
        $ownerId = null;
        $profileCache = ConnectionCache::get(AccountService::PROFILE_CACHE);
        $expectedUser = $profileCache['data']['profile']['user'] ?? null;
        $expectedOwner = self::exactId($profileCache['data']['profile']['user']['id'] ?? null);
        $profileVerifiedForShops = false;
        // The upstream contract defaults to ten and caps per_page at thirty.
        // Never publish a partial list as "no active shop" or cache it.
        for ($page = 0; $page < 100; $page++) {
            $renew();
            ConnectionCache::assertCurrentIdentity($identity);
            $query = ['per_page' => 30];
            if ($cursor !== null) $query['cursor'] = $cursor;
            $response = ClientFactory::make()->get(SalamGateClient::PATH_SHOPS, $query);
            $responseIdentity = ConnectionCache::responseIdentity($response, $identity);
            ConnectionCache::assertCurrentIdentity($responseIdentity);
            if ($responseIdentity !== $identity) {
                $renewedProfile = (new AccountService())->profile(true);
                if ($expectedOwner === null || ($renewedProfile['user']['id'] ?? null) !== $expectedOwner) throw self::unexpectedResponse();
                // A server-verified same-user renewal is not an account switch.
                ConnectionCache::assertCurrentIdentity($responseIdentity);
                $identity = $responseIdentity;
                $expectedUser = $renewedProfile['user'];
                $profileVerifiedForShops = true;
            }
            if (!is_array($response['body'] ?? null)) throw self::unexpectedResponse();
            $body = $response['body'];
            $items = self::extractShopList($body);
            foreach ($items as $shop) {
                self::validateShopResponse($shop);
                $user = $shop['user'] ?? null;
                if (!is_array($user)) throw self::unexpectedResponse();
                // GET ShopUserResponse deliberately omits id, unlike the
                // registration response. Verify its identity fields against
                // the server profile under this exact connection before
                // deriving an owner ID for receipt reconciliation.
                if (!array_key_exists('id', $user)) {
                    if (!$profileVerifiedForShops) {
                        if ($fresh || $expectedOwner === null) {
                            $ownerProfile = (new AccountService())->profile($fresh);
                            ConnectionCache::assertCurrentIdentity($identity);
                            $currentOwner = self::exactId($ownerProfile['user']['id'] ?? null);
                            if ($expectedOwner !== null && $currentOwner !== $expectedOwner) throw self::unexpectedResponse();
                            $expectedUser = $ownerProfile['user'] ?? null;
                            $expectedOwner = $currentOwner;
                        }
                        $profileVerifiedForShops = true;
                    }
                    foreach (['mobile', 'national_code'] as $field) {
                        if ($expectedOwner === null || !is_string($user[$field] ?? null) || $user[$field] === ''
                            || !is_string($expectedUser[$field] ?? null) || !hash_equals($expectedUser[$field], $user[$field])) throw self::unexpectedResponse();
                    }
                    $shop['user']['id'] = $expectedOwner;
                }
                $shopOwner = self::exactId($shop['user']['id'] ?? null);
                if ($shopOwner === null || ($ownerId !== null && $shopOwner !== $ownerId)
                    || ($expectedOwner !== null && $shopOwner !== $expectedOwner)) throw self::unexpectedResponse();
                $ownerId = $shopOwner;
                $id = $shop['id'];
                if (isset($seenIds[$id])) {
                    if ($seenIds[$id] !== $shop) throw self::unexpectedResponse();
                    continue;
                }
                $seenIds[$id] = $shop;
                $rawShops[] = $shop;
            }
            $next = $body['next_cursor'] ?? null;
            if ($next === null) break;
            if (!is_string($next) || $next === '' || strlen($next) > 4096 || preg_match('/[\x00-\x20\x7f]/', $next)
                || isset($seenCursors[$next]) || $items === [] || $page === 99) throw self::unexpectedResponse();
            $seenCursors[$next] = true;
            $cursor = $next;
        }
        $shops = ShopNormalizer::normalizeList($rawShops);

        $renew();
        ConnectionCache::setForIdentity(self::CACHE_NAME, ['shops' => $shops, 'checked_at' => time()], self::CACHE_TTL, $identity);

        return $shops;
    }

    /**
     * اولین غرفه فعال (status=14). انتخاب صریح فرستنده بعداً به این سرویس اضافه می‌شود.
     */
    public function getActiveShop(): ?array
    {
        foreach ($this->getShops() as $shop) {
            if ((int) ($shop['status_id'] ?? 0) === ShopStatuses::ACTIVE) {
                return $shop;
            }
        }

        return null;
    }

    /**
     * ثبت اولیه فروشگاه (POST /v1/shops/register).
     *
     * body مطابق RegisterShopRequest؛ pay_method فقط از PayMethods::SHOP_ACTIVATION_*.
     *
     * @return array خروجی ShopNormalizer::normalize — پاسخ async می‌تواند
     *               queued=true با idِ صف (نه غرفه) باشد؛ provider_shop_id تلقی نشود.
     */
    public function register(array $body, ?callable $beforeSubmit = null): array
    {
        $this->registrationSubmitted = false;
        $providerId = self::exactId($body['provider_id'] ?? null);
        $postalCode = $body['address']['postal_code'] ?? null;
        if ($providerId === null || !is_string($postalCode) || !preg_match('/^\d{10}$/D', $postalCode)) {
            throw new SalamGateException('شناسه سرویس یا کدپستی ثبت معتبر نیست.', 422, [], SalamGateException::KIND_VALIDATION);
        }

        // Scope is stable across OAuth token rotation. Before SalamGate has a
        // profile, use the connected vendor ID saved by the base plugin, never
        // an account ID supplied by the browser. With neither identity, the
        // explicitly conservative provider-wide fallback prevents replay.
        $profile = (new AccountService())->profile(true);
        $scope = self::registrationScope($providerId, $profile);
        $shops = $this->getShops(true);
        ConnectionCache::assertCurrentIdentity($scope['connection_hash']);
        $existing = self::matchingShop($shops, $providerId, self::registrationDigest($postalCode));

        $records = self::registrationRecords($scope['keys']);
        foreach ($records as $key => $previous) {
            $recovered = self::matchingShop($shops, $providerId, $previous['postal_hash'] ?? '');
            if ($recovered !== null && self::canReconcileRegistration($previous, $recovered)) {
                // A confirmed old registration at another address does not
                // prohibit the user's explicitly requested second sender.
                self::replaceRegistration($key, $previous, null);
                continue;
            }
            throw self::registrationBlocked(($previous['state'] ?? '') === 'queued' ? 'registration_pending' : 'register_unknown');
        }
        if ($existing !== null) return $existing;

        $record = ['state' => 'pending', 'owner' => bin2hex(random_bytes(16)), 'started_at' => time(),
            'provider_id' => $providerId, 'user_id' => $scope['user_id'],
            'postal_hash' => self::registrationDigest($postalCode),
            'payload_hash' => self::registrationDigest((string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))];
        $claimed = [];
        foreach ($scope['keys'] as $key) {
            if (!self::claimRegistration($key, $record)) {
                foreach ($claimed as $owned) self::replaceRegistration($owned, $record, null);
                throw self::registrationBlocked(self::readRegistration($key) === null ? 'registration_unavailable' : 'register_unknown');
            }
            $claimed[] = $key;
        }

        $writeStarted = false;
        try {
            // The owner may recheck the exact selected funding source under
            // this lock. Existing/uncertain registrations are resolved above,
            // so recovery never performs a second financial submission.
            if ($beforeSubmit !== null) $beforeSubmit();
            ConnectionCache::assertCurrentIdentity($scope['connection_hash']);
            $writeStarted = true;
            $this->registrationSubmitted = true;
            $response = ClientFactory::make()->post(SalamGateClient::PATH_SHOP_REGISTER, $body);
            $shop = is_array($response['body'] ?? null) ? $response['body'] : [];
            self::validateShopResponse($shop);
            if (!ShopStatuses::isValid($shop['status']['id'])
                || self::exactId($shop['provider']['id'] ?? null) !== $providerId
                || ($shop['address']['postal_code'] ?? null) !== $postalCode
                || self::exactId($shop['user']['id'] ?? null) === null
                || ($scope['user_id'] !== null && self::exactId($shop['user']['id']) !== $scope['user_id'])
                || (!is_string($shop['identifier'] ?? null) && ($shop['identifier'] ?? null) !== null)
                || (empty($shop['identifier']) && $shop['status']['id'] !== ShopStatuses::PENDING_APPROVAL)) {
                throw self::unexpectedResponse();
            }
            $identity = ConnectionCache::responseIdentity($response, $scope['connection_hash']);
            ConnectionCache::assertCurrentIdentity($identity);
            $normalized = ShopNormalizer::normalize($shop);
            $next = array_replace($record, ['state' => $normalized['queued'] ? 'queued' : 'registered',
                'user_id' => self::exactId($shop['user']['id']), 'shop_id' => $normalized['id']]);
            // Keep an independent owner-scoped receipt before changing the
            // attempt via CAS. A failed CAS must not strand a successful POST,
            // nor may a postal-code coincidence unlock an in-flight request.
            if (!$normalized['queued'] && !self::claimRegistration(self::RESULT_PREFIX . $record['owner'], $next)) {
                throw self::registrationBlocked('register_unknown');
            }
            foreach ($claimed as $key) {
                if (!self::replaceRegistration($key, $record, $next)) throw self::registrationBlocked('register_unknown');
            }
            // Preserve the preferred stable user identity even when the first
            // request only had a base-plugin vendor ID (profile was 404).
            $userKey = self::registrationKey($providerId, 'user', $next['user_id']);
            if (!in_array($userKey, $claimed, true)) self::claimRegistration($userKey, $next);
            $this->flushCache();
            ConnectionCache::delete(AccountService::PROFILE_CACHE);
            return $normalized;
        } catch (SalamGateException $exception) {
            $definite = !$writeStarted || in_array($exception->getKind(), [
                SalamGateException::KIND_VALIDATION, SalamGateException::KIND_UNAUTHENTICATED,
                SalamGateException::KIND_FORBIDDEN, SalamGateException::KIND_NOT_FOUND,
                SalamGateException::KIND_RATE_LIMITED,
            ], true);
            foreach ($claimed as $key) {
                self::replaceRegistration($key, $record, $definite ? null : array_replace($record, ['state' => 'unknown']));
            }
            // Cache errors/partial response after a successful write remain
            // conservative; CAS cannot erase a newer queued/registered record.
            throw $exception;
        } catch (\Throwable $exception) {
            foreach ($claimed as $key) self::replaceRegistration($key, $record, $writeStarted ? array_replace($record, ['state' => 'unknown']) : null);
            if (!$writeStarted) {
                throw new SalamGateException('بررسی نهایی فعال‌سازی انجام نشد؛ ثبت یا پرداختی انجام نشده است.',
                    0, [], SalamGateException::KIND_UNEXPECTED_RESPONSE);
            }
            throw self::registrationBlocked('register_unknown');
        }
    }

    public function registrationSubmitted(): bool
    {
        return $this->registrationSubmitted;
    }

    /** Read-only bootstrap/recovery state. An empty list never clears an unknown attempt. */
    public function registrationState(array $profile, ?array $shops): ?string
    {
        $scope = self::registrationScope(ReferenceDataService::POST_PROVIDER_ID, $profile);
        $state = null;
        foreach (self::registrationRecords($scope['keys']) as $key => $record) {
            $matched = $shops === null ? null : self::matchingShop($shops, ReferenceDataService::POST_PROVIDER_ID, $record['postal_hash'] ?? '');
            if ($matched !== null && self::canReconcileRegistration($record, $matched)) {
                // A completed attempt can disappear from the presentation
                // guard only after an exact owner-filtered provider+postal read.
                self::replaceRegistration($key, $record, null);
                continue;
            }
            $state = ($record['state'] ?? '') === 'queued' ? 'pending' : 'unknown';
        }
        return $state;
    }

    private static function canReconcileRegistration(array $record, array $shop): bool
    {
        if (($record['state'] ?? '') !== 'pending') {
            $userId = self::exactId($record['user_id'] ?? null);
            return $userId !== null && $userId === self::exactId($shop['owner_user_id'] ?? null)
                && (($record['state'] ?? '') !== 'registered'
                    || self::exactId($record['shop_id'] ?? null) === self::exactId($shop['id'] ?? null));
        }
        $owner = $record['owner'] ?? null;
        if (!is_string($owner) || !preg_match('/^[a-f0-9]{32}$/D', $owner)) return false;
        $proof = self::readRegistration(self::RESULT_PREFIX . $owner);
        return $proof !== null && ($proof['state'] ?? '') === 'registered'
            && ($proof['owner'] ?? null) === $owner
            && ($proof['payload_hash'] ?? null) === ($record['payload_hash'] ?? null)
            && ($proof['postal_hash'] ?? null) === ($record['postal_hash'] ?? null)
            && ($proof['provider_id'] ?? null) === ($record['provider_id'] ?? null)
            && self::exactId($proof['shop_id'] ?? null) === self::exactId($shop['id'] ?? null)
            && self::exactId($proof['user_id'] ?? null) === self::exactId($shop['owner_user_id'] ?? null)
            && self::exactId($proof['user_id'] ?? null) !== null;
    }

    private static function registrationScope(int $providerId, array $profile): array
    {
        $identity = ConnectionCache::identityHash();
        ConnectionCache::assertCurrentIdentity($identity);
        // profile() pins both success and404 to its actual response identity.
        // Reject an account switch between its return and this scope lookup;
        // never combine the former user's ID with a newly connected token.
        $profileCache = ConnectionCache::get(AccountService::PROFILE_CACHE);
        if (($profileCache['connection_hash'] ?? null) !== $identity
            || ($profileCache['data']['profile'] ?? null) !== $profile) {
            throw new SalamGateException('اتصال باسلام هنگام بررسی فعال‌سازی تغییر کرد؛ دوباره وضعیت را بررسی کنید.',
                0, [], SalamGateException::KIND_UNEXPECTED_RESPONSE);
        }
        $userId = ($profile['registered'] ?? false) === true ? self::exactId($profile['user']['id'] ?? null) : null;
        global $wpdb;
        $vendorId = null;
        if (is_object($wpdb) && isset($wpdb->options) && method_exists($wpdb, 'get_var') && method_exists($wpdb, 'prepare')) {
            $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s LIMIT 1", 'sync_basalam_settings'));
            $settings = is_string($raw) ? @unserialize($raw, ['allowed_classes' => false]) : $raw;
            $vendorId = is_array($settings) ? self::exactId($settings['vendor_id'] ?? null) : null;
        } elseif (function_exists('syncBasalamSettings')) {
            $vendorId = self::exactId(syncBasalamSettings()->getSettings('vendor_id'));
        }
        ConnectionCache::assertCurrentIdentity($identity);
        $keys = [];
        if ($userId !== null) $keys[] = self::registrationKey($providerId, 'user', $userId);
        if ($vendorId !== null) $keys[] = self::registrationKey($providerId, 'vendor', $vendorId);
        $fallback = self::REGISTRATION_PREFIX . $providerId . '_unresolved_identity';
        if ($keys === [] || self::readRegistration($fallback) !== null) $keys[] = $fallback;
        return ['keys' => $keys, 'user_id' => $userId, 'connection_hash' => $identity];
    }

    private static function registrationKey(int $providerId, string $kind, int $id): string
    {
        return self::REGISTRATION_PREFIX . $providerId . '_' . $kind . '_' . $id;
    }

    private static function registrationDigest(string $value): string
    {
        // Postal/identity payloads must not become recoverable plain hashes.
        return hash_hmac('sha256', $value, wp_salt('auth'));
    }

    private static function matchingShop(array $shops, int $providerId, string $postalHash): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $postalHash)) return null;
        $matches = [];
        foreach ($shops as $shop) {
            $postal = $shop['address']['postal_code'] ?? null;
            if (self::exactId($shop['provider']['id'] ?? null) === $providerId
                && is_string($postal) && preg_match('/^\d{10}$/D', $postal)
                && hash_equals($postalHash, self::registrationDigest($postal)) && empty($shop['queued'])) $matches[] = $shop;
        }
        return count($matches) === 1 ? $matches[0] : null;
    }

    private static function exactId($value): ?int
    {
        if (!is_int($value) && (!is_string($value) || !preg_match('/^[1-9]\d*$/D', $value))) return null;
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }

    private static function registrationRecords(array $keys): array
    {
        $records = [];
        foreach ($keys as $key) {
            $record = self::readRegistration($key);
            if ($record !== null) $records[$key] = $record;
        }
        return $records;
    }

    private static function readRegistration(string $key): ?array
    {
        global $wpdb;
        if (!is_object($wpdb) || !isset($wpdb->options) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) {
            return null; // Claim below still fails closed without a WordPress datastore.
        }
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s LIMIT 1", $key));
        $record = is_string($raw) ? @unserialize($raw, ['allowed_classes' => false]) : $raw;
        return is_array($record) ? $record : null;
    }

    private static function claimRegistration(string $key, array $record): bool
    {
        global $wpdb;
        if (!is_object($wpdb) || !isset($wpdb->options) || !method_exists($wpdb, 'query') || !method_exists($wpdb, 'prepare')) return false;
        $changed = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO `{$wpdb->options}` (option_name, option_value, autoload) VALUES (%s, %s, %s)", $key, serialize($record), 'no'));
        self::flushRegistrationOption($key);
        return (int) $changed === 1;
    }

    private static function replaceRegistration(string $key, array $previous, ?array $next): bool
    {
        global $wpdb;
        $sql = $next === null
            ? $wpdb->prepare("DELETE FROM `{$wpdb->options}` WHERE option_name = %s AND BINARY option_value = BINARY %s", $key, serialize($previous))
            : $wpdb->prepare("UPDATE `{$wpdb->options}` SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s", serialize($next), $key, serialize($previous));
        $changed = $wpdb->query($sql);
        self::flushRegistrationOption($key);
        return (int) $changed === 1;
    }

    private static function flushRegistrationOption(string $key): void
    {
        wp_cache_delete($key, 'options');
        wp_cache_delete('notoptions', 'options');
    }

    private static function registrationBlocked(string $code): SalamGateException
    {
        return new SalamGateException(
            $code === 'registration_unavailable'
                ? 'ثبت فعلاً در دسترس نیست؛ ثبت یا پرداختی انجام نشده است. دوباره تلاش کنید.'
                : ($code === 'registration_pending'
                ? 'فعال‌سازی قبلی هنوز در صف پردازش است؛ ثبت و پرداخت را تکرار نکنید و وضعیت را بررسی کنید.'
                : 'نتیجه فعال‌سازی قبلی هنوز مشخص نیست؛ ثبت و پرداخت را تکرار نکنید و وضعیت را بررسی کنید.'),
            200, [['location' => 'registration', 'message' => $code]], SalamGateException::KIND_UNEXPECTED_RESPONSE
        );
    }

    /**
     * فعال‌سازی مجدد فروشگاه غیرفعال (16 → 14). تأیید ثبت pending/rejected اینجا نیست.
     */
    public function activate(int $shopId): array
    {
        return $this->postShopAction($shopId, 'activate');
    }

    /**
     * غیرفعال‌سازی فروشگاه فعال.
     */
    public function deactivate(int $shopId): array
    {
        return $this->postShopAction($shopId, 'deactivate');
    }

    /**
     * ویرایش مجاز: فقط مرکز تحویل و سریال کارت ملی. تغییر واقعی وضعیت را به 15 برمی‌گرداند.
     *
     * @return array خروجی ShopNormalizer::normalize
     */
    public function update(int $shopId, ?int $providerNodeId = null, ?string $nationalCodeSerial = null): array
    {
        $body = [];
        if ($providerNodeId !== null) {
            if ($providerNodeId <= 0) {
                throw new SalamGateException('شناسه مرکز تحویل نامعتبر است.', 422, [
                    ['location' => 'provider_node_id', 'message' => 'شناسه مرکز تحویل نامعتبر است.'],
                ], SalamGateException::KIND_VALIDATION);
            }
            $body['provider_node_id'] = $providerNodeId;
        }
        if ($nationalCodeSerial !== null) {
            $nationalCodeSerial = strtoupper(trim($nationalCodeSerial));
            if (!preg_match('/^[A-Z0-9]{10}$/', $nationalCodeSerial)) {
                throw new SalamGateException('سریال کارت ملی نامعتبر است.', 422, [
                    ['location' => 'national_code_serial', 'message' => 'سریال کارت ملی نامعتبر است.'],
                ], SalamGateException::KIND_VALIDATION);
            }
            $body['national_code_serial'] = $nationalCodeSerial;
        }
        if ($body === []) {
            throw new SalamGateException('هیچ فیلد قابل ویرایشی ارسال نشده است.', 422, [], SalamGateException::KIND_VALIDATION);
        }

        $identity = ConnectionCache::identityHash();
        ConnectionCache::assertCurrentIdentity($identity);
        try {
            $response = ClientFactory::make()->patch(sprintf('/v1/shops/%d', $shopId), $body);
            if (ConnectionCache::responseIdentity($response, $identity) !== $identity) throw self::unexpectedResponse();
            ConnectionCache::assertCurrentIdentity($identity);
        } finally {
            // A transport failure can follow a committed PATCH. Invalidate the
            // original connection, not whichever account connected meanwhile.
            ConnectionCache::deleteForIdentity(self::CACHE_NAME, $identity);
            if ($nationalCodeSerial !== null) ConnectionCache::deleteForIdentity(AccountService::PROFILE_CACHE, $identity);
        }

        $shop = is_array($response['body'] ?? null) ? $response['body'] : [];
        self::validateShopResponse($shop);
        if ($shop['id'] !== $shopId) throw self::unexpectedResponse();

        return ShopNormalizer::normalize($shop);
    }

    public function flushCache(): void
    {
        ConnectionCache::delete(self::CACHE_NAME);
        // پاک‌سازی کش قدیمی بدون هویت (مهاجرت).
        delete_option(self::LEGACY_CACHE_OPTION);
    }

    /**
     * @throws SalamGateException
     */
    private function postShopAction(int $shopId, string $action): array
    {
        $response = ClientFactory::make()->post(sprintf(SalamGateClient::PATH_SHOP_ACTION, $shopId, $action));
        $this->flushCache();

        return $response;
    }

    /**
     * پاسخ واقعی سرویس: {"data": [shop, ...]} — به‌صورت دفاعی مپ و لیست خام هم پشتیبانی می‌شود.
     *
     * @return array<int, array>
     */
    public static function extractShopList(array $body): array
    {
        $shops = [];

        if (array_key_exists('data', $body)) {
            if (!is_array($body['data'])) {
                throw self::unexpectedResponse();
            }
            $body = $body['data'];
        }

        // لیست ساده: [shop, shop, ...] (سازگار با PHP 8.0 — بدون array_is_list)
        $isList = $body === [] || array_keys($body) === range(0, count($body) - 1);

        if ($isList) {
            foreach ($body as $shop) {
                if (!is_array($shop)) {
                    throw self::unexpectedResponse();
                }
                $shops[] = $shop;
            }

            return $shops;
        }

        // مپ کلید → لیست غرفه‌ها: {"post": [shop, ...]}
        foreach ($body as $group) {
            if (!is_array($group) || ($group !== [] && array_keys($group) !== range(0, count($group) - 1))) {
                throw self::unexpectedResponse();
            }

            foreach ($group as $shop) {
                if (!is_array($shop)) {
                    throw self::unexpectedResponse();
                }
                $shops[] = $shop;
            }
        }

        return $shops;
    }

    private static function validateShopResponse(array $shop): void
    {
        if (!is_int($shop['id'] ?? null) || $shop['id'] <= 0
            || !is_array($shop['status'] ?? null)
            || !is_int($shop['status']['id'] ?? null) || $shop['status']['id'] <= 0
        ) {
            throw self::unexpectedResponse();
        }
    }

    private static function unexpectedResponse(): SalamGateException
    {
        return new SalamGateException('پاسخ اطلاعات غرفه از سلام‌گیت معتبر نیست.', 200, [], SalamGateException::KIND_UNEXPECTED_RESPONSE);
    }
}
