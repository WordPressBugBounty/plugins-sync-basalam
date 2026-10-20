<?php

namespace SyncBasalam\SalamGate\Services;

use SyncBasalam\SalamGate\Utils\Arrays;
use SyncBasalam\SalamGate\Order\NumericIdentity;
use SyncBasalam\SalamGate\SalamGateException;

defined('ABSPATH') || exit;

/** Durable create-once records for standalone submissions and shared order workflows. */
final class CreationAttemptService
{
    private const MARKETS = ['website', 'instagram', 'eitaa', 'bale', 'telegram', 'digikala', 'mixin', 'other'];

    public static function validAttempt($attempt): bool
    {
        return is_string($attempt) && preg_match('/^[a-f0-9]{32}$/D', $attempt) === 1;
    }

    /**
     * $create(int $externalId): int submits once and returns the confirmed parcel ID.
     * $recover(int $externalId, string $market): ?int performs an owner-filtered lookup only.
     * The caller must validate the returned external ID and market, not trust query filters alone.
     */
    public function run(string $attempt, string $market, array $body, callable $create, callable $recover): array
    {
        if (!self::validAttempt($attempt) || !in_array($market, self::MARKETS, true)) {
            return $this->error('شناسه ثبت معتبر نیست؛ صفحه ثبت مرسوله را دوباره باز کنید.', 'validation');
        }
        return $this->runAtKey($attempt, $this->key($attempt), $market, $body, null, $create, $recover);
    }

    /**
     * One durable claim per site/market/external identity, not per browser/user/provider shop.
     * Foreground and bulk creation must share it. An unknown write has no expiry/replay path.
     */
    public function runForOrder(int $shopId, string $market, int $externalId, array $body, callable $create, callable $recover): array
    {
        if ($shopId < 1 || $externalId < 1 || !in_array($market, ['website', 'basalam'], true)) {
            return $this->error('ارتباط سفارش با غرفه و مرسوله معتبر نیست؛ ثبت انجام نشد.', 'validation');
        }
        // A settings/account/provider change must never rotate an unknown create claim.
        // wp_options already scopes the record to this WordPress site/blog.
        $digest = hash('sha256', $market . ':' . $externalId);
        return $this->runAtKey(substr($digest, 0, 32), 'salam_gate_create_order_' . $digest,
            $market, ['provider_shop_id' => $shopId, 'request' => $body], $externalId, $create, $recover);
    }

    /** Owner-filtered recovery validates the result, not just the API query filters. */
    public static function recoverOrderParcel(int $externalId, string $market): ?int
    {
        $response = \SyncBasalam\SalamGate\ClientFactory::make()->get(
            \SyncBasalam\SalamGate\SalamGateClient::PATH_PARCELS,
            ['market_name' => [$market], 'external_ids' => [$externalId], 'per_page' => 30]
        );
        $body = $response['body'] ?? null;
        if (!is_array($body) || !is_array($body['data'] ?? null) || !Arrays::isList($body['data']) || count($body['data']) > 1
            || (isset($body['next_cursor']) && $body['next_cursor'] !== '')) {
            throw new SalamGateException('نتیجه ثبت قبلی مرسوله قابل تأیید نیست.', 200, [], SalamGateException::KIND_UNEXPECTED_RESPONSE);
        }
        if ($body['data'] === []) return null;
        $item = reset($body['data']);
        $id = is_array($item) ? ParcelService::positiveId($item['id'] ?? null) : null;
        if ($id === null || ($item['market']['value'] ?? null) !== $market
            || ParcelService::positiveId($item['external_id'] ?? null) !== $externalId) {
            throw new SalamGateException('مرسوله بازیابی‌شده با سفارش مطابقت ندارد.', 200, [], SalamGateException::KIND_UNEXPECTED_RESPONSE);
        }
        return $id;
    }

    /**
     * Recover only a durable local creation identity, never a guessed Woo order number.
     * Older website UPDATE responses omit external_id before the new parcel registry exists.
     * This read-only compatibility lookup cannot authorize creation or metadata writes.
     */
    public static function externalIdForWebsiteParcel(int $parcelId): ?int
    {
        if ($parcelId < 1) { return null; }
        global $wpdb;
        $error = static fn (): SalamGateException => new SalamGateException(
            'ارتباط ثبت قبلی این مرسوله قابل تأیید نیست؛ برای جلوگیری از پرداخت تکراری، پرداخت انجام نمی‌شود.',
            422, [['location' => 'payment.identity', 'message' => 'legacy-website-identity-unconfirmed']],
            SalamGateException::KIND_VALIDATION
        );
        // Exact serialized scalar boundaries prevent id 12 from matching parcel 123.
        // Keep the candidate count bounded; ambiguous history never authorizes payment.
        $names = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM `{$wpdb->options}` WHERE option_name REGEXP %s AND option_value REGEXP %s ORDER BY option_name ASC LIMIT 2",
            '^salam_gate_create_(order_)?[a-f0-9]{64}$',
            's:9:"parcel_id";(i:' . $parcelId . ';|s:[0-9]+:"' . $parcelId . '";)'
        ));
        if (!is_array($names) || ($wpdb->last_error ?? '') !== '' || count($names) > 1) { throw $error(); }
        if ($names === []) { return null; }
        $name = reset($names);
        if (!is_string($name) || !preg_match('/^salam_gate_create_(order_)?([a-f0-9]{64})$/D', $name, $match)) {
            throw $error();
        }
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s LIMIT 1", $name
        ));
        if (($wpdb->last_error ?? '') !== '') { throw $error(); }
        // Isolated SQL fixtures use decoded values; real wp_options values are serialized strings.
        $record = is_array($raw) ? $raw : (is_string($raw) ? @unserialize($raw, ['allowed_classes' => false]) : null);
        $externalId = is_array($record) ? ParcelService::positiveId($record['external_id'] ?? null) : null;
        if (!is_array($record) || ($record['state'] ?? '') !== 'created' || ($record['market'] ?? '') !== 'website'
            || ParcelService::positiveId($record['parcel_id'] ?? null) !== $parcelId || $externalId === null
            || (($match[1] ?? '') === 'order_' && $match[2] !== hash('sha256', 'website:' . $externalId))) {
            throw $error();
        }
        return $externalId;
    }

    private function runAtKey(string $attempt, string $key, string $market, array $body, ?int $externalId, callable $create, callable $recover): array
    {
        $hash = hash('sha256', (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $existing = $this->read($key);
        if ($existing !== null) {
            if (($existing['market'] ?? '') !== $market || ($existing['payload_hash'] ?? '') !== $hash
                || ($externalId !== null && ParcelService::positiveId($existing['external_id'] ?? null) !== $externalId)) {
                if ($externalId !== null && ($existing['market'] ?? '') === $market
                    && ParcelService::positiveId($existing['external_id'] ?? null) === $externalId) {
                    // Changed input cannot authorize an upsert, but may recover the
                    // original identity so the caller can reopen the real saved draft.
                    $status = $this->recover($attempt, $key, $existing, $recover);
                    return $this->error('اطلاعات تازه ثبت نشد؛ ابتدا اطلاعات مرسوله قبلی را دوباره باز و بررسی کنید.',
                        'create_unknown', $status['payload']);
                }
                return $this->error('این درخواست به ثبت قبلی تعلق دارد؛ نتیجه آن را بررسی کنید و اطلاعات تازه را جداگانه ثبت کنید.', 'create_unknown', $this->payload($attempt, $existing));
            }
            return $this->recover($attempt, $key, $existing, $recover);
        }

        $externalId = $externalId ?? NumericIdentity::forKey('standalone:' . $market . ':' . $attempt);
        if ($externalId === null) {
            return $this->error('شناسه مرسوله ساخته نشد؛ ثبت انجام نشده است. دوباره تلاش کنید.', 'creation_unavailable');
        }
        $record = ['state' => 'pending', 'market' => $market, 'external_id' => $externalId,
            'payload_hash' => $hash, 'started_at' => time(), 'owner' => bin2hex(random_bytes(16))];
        if (!$this->claim($key, $record)) {
            // A concurrent request won. Never turn a failed claim into another create.
            $existing = $this->read($key);
            if ($existing === null) {
                return $this->error('ثبت مرسوله فعلاً در دسترس نیست؛ درخواست ثبت ارسال نشده است.', 'creation_unavailable');
            }
            if (($existing['market'] ?? '') !== $market || ($existing['payload_hash'] ?? '') !== $hash
                || ParcelService::positiveId($existing['external_id'] ?? null) !== $externalId) {
                return $this->error('یک ثبت قبلی با این شناسه در حال بررسی است؛ دوباره ثبت نکنید.', 'create_unknown', $this->payload($attempt, $existing));
            }
            return $this->recover($attempt, $key, $existing, $recover);
        }

        try {
            $parcelId = ParcelService::positiveId($create($externalId));
            if ($parcelId === null) {
                throw new SalamGateException('شناسه مرسوله در پاسخ ثبت تأیید نشد.', 200, [], SalamGateException::KIND_UNEXPECTED_RESPONSE);
            }
            $created = array_replace($record, ['state' => 'created', 'parcel_id' => $parcelId]);
            if (!$this->replace($key, $record, $created)) {
                $saved = $this->read($key);
                if (($saved['state'] ?? '') !== 'created' || ($saved['parcel_id'] ?? null) !== $parcelId) {
                    return $this->error('مرسوله ثبت شده، اما ذخیره نتیجه کامل نشد؛ وضعیت ثبت را بررسی کنید و دوباره ثبت نکنید.', 'create_unknown', $this->payload($attempt, $record));
                }
                $created = $saved;
            }
            return ['ok' => true, 'payload' => $this->payload($attempt, $created) + ['reused' => false]];
        } catch (SalamGateException $exception) {
            if (ParcelService::isResultUnknown($exception)) {
                $unknown = array_replace($record, ['state' => 'unknown']);
                $this->replace($key, $record, $unknown);
                return $this->error('نتیجه ثبت مرسوله هنوز مشخص نیست؛ وضعیت ثبت را بررسی کنید و دوباره ثبت نکنید.', 'create_unknown', $this->payload($attempt, $unknown));
            }
            // Only a definitive rejection permits corrected input to reuse the attempt.
            $this->remove($key, $record);
            return ['ok' => false, 'payload' => ['message' => $exception->firstMessage(),
                'errors' => $exception->getErrors(), 'kind' => $exception->getKind(), 'creation_rejected' => true]];
        } catch (\Throwable $exception) {
            // The HTTP write may already have happened before a local failure.
            $unknown = array_replace($record, ['state' => 'unknown']);
            $this->replace($key, $record, $unknown);
            return $this->error('نتیجه ثبت مرسوله کامل دریافت نشد؛ وضعیت ثبت را بررسی کنید و دوباره ثبت نکنید.', 'create_unknown', $this->payload($attempt, $unknown));
        }
    }

    /** A missing row is not proof that a timed-out request will never arrive at the server. */
    public function status(string $attempt, callable $recover): array
    {
        if (!self::validAttempt($attempt)) {
            return $this->error('شناسه ثبت مرسوله معتبر نیست.', 'validation');
        }
        $key = $this->key($attempt);
        $record = $this->read($key);
        if ($record === null) {
            return $this->error('درخواست قبلی هنوز در سرور پیدا نشد؛ کمی بعد وضعیت ثبت را بررسی کنید و دوباره ثبت نکنید.', 'create_unknown', ['creation_id' => $attempt, 'creation_state' => 'unknown']);
        }
        return $this->recover($attempt, $key, $record, $recover);
    }

    private function recover(string $attempt, string $key, array $record, callable $recover): array
    {
        if (($record['state'] ?? '') === 'created' && ParcelService::positiveId($record['parcel_id'] ?? null) !== null) {
            return ['ok' => true, 'payload' => $this->payload($attempt, $record) + ['reused' => true]];
        }
        $externalId = ParcelService::positiveId($record['external_id'] ?? null);
        $allowed = self::MARKETS;
        if (str_starts_with($key, 'salam_gate_create_order_')) $allowed[] = 'basalam';
        if ($externalId !== null && in_array($record['market'] ?? '', $allowed, true)) {
            try {
                $parcelId = ParcelService::positiveId($recover($externalId, $record['market']));
                if ($parcelId !== null) {
                    $created = array_replace($record, ['state' => 'created', 'parcel_id' => $parcelId]);
                    if ($this->replace($key, $record, $created)) {
                        return ['ok' => true, 'payload' => $this->payload($attempt, $created) + ['reused' => true]];
                    }
                    $latest = $this->read($key);
                    if (($latest['state'] ?? '') === 'created' && ($latest['parcel_id'] ?? null) === $parcelId) {
                        return ['ok' => true, 'payload' => $this->payload($attempt, $latest) + ['reused' => true]];
                    }
                }
            } catch (\Throwable $exception) { /* Read failure never authorizes another create. */ }
        }
        return $this->error('نتیجه ثبت قبلی هنوز مشخص نیست؛ وضعیت ثبت را بررسی کنید و دوباره ثبت نکنید.', 'create_unknown', $this->payload($attempt, $record));
    }

    private function payload(string $attempt, array $record): array
    {
        return ['creation_id' => $attempt, 'creation_state' => $record['state'] ?? 'unknown',
            'market' => $record['market'] ?? '', 'parcel_id' => ParcelService::positiveId($record['parcel_id'] ?? null)];
    }

    private function error(string $message, string $code, array $extra = []): array
    {
        return ['ok' => false, 'payload' => ['message' => $message, 'code' => $code] + $extra];
    }

    private function key(string $attempt): string
    {
        return 'salam_gate_create_' . hash('sha256', get_current_user_id() . ':' . $attempt);
    }

    private function read(string $key): ?array
    {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s LIMIT 1", $key));
        if (is_array($raw)) { return $raw; } // Isolated SQL fixtures use decoded values.
        $value = is_string($raw) ? @unserialize($raw, ['allowed_classes' => false]) : null;
        return is_array($value) ? $value : null;
    }

    private function claim(string $key, array $record): bool
    {
        global $wpdb;
        $changed = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO `{$wpdb->options}` (option_name, option_value, autoload) VALUES (%s, %s, %s)", $key, serialize($record), 'no'));
        $this->flush($key);
        return (int) $changed === 1;
    }

    private function replace(string $key, array $old, array $next): bool
    {
        global $wpdb;
        $changed = $wpdb->query($wpdb->prepare("UPDATE `{$wpdb->options}` SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s", serialize($next), $key, serialize($old)));
        $this->flush($key);
        return (int) $changed === 1;
    }

    private function remove(string $key, array $record): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->options}` WHERE option_name = %s AND BINARY option_value = BINARY %s", $key, serialize($record)));
        $this->flush($key);
    }

    private function flush(string $key): void
    {
        wp_cache_delete($key, 'options');
        wp_cache_delete('notoptions', 'options');
    }
}
