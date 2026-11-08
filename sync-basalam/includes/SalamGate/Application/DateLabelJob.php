<?php
namespace SyncBasalam\SalamGate\Application;

use SyncBasalam\SalamGate\ClientFactory;
use SyncBasalam\SalamGate\Contracts\LabelEligibility;
use SyncBasalam\SalamGate\Infrastructure\AttemptRepository;
use SyncBasalam\SalamGate\Infrastructure\OperationLogger;
use SyncBasalam\SalamGate\SalamGateClient;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\ConnectionCache;
use SyncBasalam\SalamGate\Services\ParcelService;
use SyncBasalam\SalamGate\Services\ParcelsListService;
use SyncBasalam\SalamGate\Utils\OptionLock;

defined('ABSPATH') || exit;

/** One list page per request. Discovery may resume; generation has a durable single claim. */
final class DateLabelJob
{
    public function step(string $from, string $to, array $markets, string $template, bool $generate = false): array
    {
        sort($markets, SORT_STRING);
        $identity = ConnectionCache::identityHash();
        $scope = [$from, $to, array_values(array_unique($markets)), $template, get_current_user_id(), $identity];
        $id = hash('sha256', (string) json_encode($scope));
        $key = 'salam_gate_date_label_' . $id;
        $lock = $key . '_lock';
        $lease = OptionLock::acquire($lock);
        if ($lease === null) return OperationResult::failure(['code' => 'label_busy', 'message' => 'بررسی چاپ در حال اجراست؛ کمی بعد ادامه دهید.', 'request_dispatched' => false]);
        try {
            $record = AttemptRepository::read($key);
            if ($record === null) {
                $record = ['state' => 'scanning', 'verified' => 0, 'verified_at' => 0, 'scope' => $scope, 'ids' => [], 'cursor' => null, 'seen' => [], 'pages' => 0, 'expires_at' => time() + 86400];
                if (!AttemptRepository::exchange($key, null, $record)) throw new \RuntimeException('Date label claim failed.');
            }
            if (($record['scope'] ?? null) !== $scope) throw new \RuntimeException('Date label scope mismatch.');
            if (in_array($record['state'], ['dispatching', 'unknown'], true)) {
                return OperationResult::failure(['code' => 'label_unknown', 'message' => 'نتیجه درخواست چاپ قبلی مشخص نیست؛ آخرین درخواست چاپ را بررسی کنید.',
                    'job_id' => $id, 'kind' => 'unexpected_response', 'request_dispatched' => true]);
            }
            if ($record['state'] === 'requested') return OperationResult::success($record['result'] + ['job_id' => $id]);
            if ($record['expires_at'] < time()) {
                // Only read-only discovery expires. A dispatched/ambiguous job above never does.
                $next = array_replace($record, ['state' => 'scanning', 'verified' => 0, 'verified_at' => 0, 'ids' => [], 'cursor' => null, 'seen' => [], 'pages' => 0, 'expires_at' => time() + 86400]);
                self::save($key, $record, $next);
                $record = $next;
            }
            if ($record['state'] === 'ready' && $record['ids'] !== [] && ($record['verified_at'] ?? 0) < time() - 60) {
                $next = array_replace($record, ['state' => 'verifying', 'verified' => 0]);
                self::save($key, $record, $next);
                $record = $next;
            }
            if ($record['state'] === 'verifying') {
                $ids = array_slice($record['ids'], $record['verified'], 30);
                $body = ClientFactory::make()->get(SalamGateClient::PATH_PARCELS,
                    ['ids' => $ids, 'market_name' => $markets, 'per_page' => 30])['body'] ?? null;
                if (!is_array($body) || !is_array($body['data'] ?? null) || count($body['data']) !== count($ids)
                    || !empty($body['next_cursor'])) throw self::invalid();
                $matched = [];
                foreach ($body['data'] as $parcel) {
                    if (!is_array($parcel) || !self::matches($parcel, $from, $to, $markets)
                        || !in_array((int) $parcel['id'], $ids, true) || isset($matched[(int) $parcel['id']])) throw self::invalid();
                    $matched[(int) $parcel['id']] = true;
                }
                $next = array_replace($record, ['verified' => $record['verified'] + count($ids)]);
                if ($next['verified'] === count($next['ids'])) {
                    $next['state'] = 'ready';
                    $next['verified_at'] = time();
                }
                ConnectionCache::assertCurrentIdentity($identity);
                self::save($key, $record, $next);
                return self::progress($id, $next);
            }
            if ($generate && $record['state'] === 'ready') {
                if ($record['ids'] === []) return OperationResult::failure(['code' => 'label_not_ready', 'message' => 'در این بازه مرسوله آماده چاپ پیدا نشد.', 'request_dispatched' => false]);
                $renewed = OptionLock::renew($lock, $lease);
                if ($renewed === null) throw new \RuntimeException('Date label lease lost.');
                $lease = $renewed;
                ConnectionCache::assertCurrentIdentity($identity);
                $pending = array_replace($record, ['state' => 'dispatching']);
                self::save($key, $record, $pending);
                try {
                    $result = (new ParcelService())->getLabelUrl($record['ids'], $template) + ['request_dispatched' => true];
                    self::save($key, $pending, array_replace($pending, ['state' => 'requested', 'result' => $result]));
                    return OperationResult::success($result + ['job_id' => $id]);
                } catch (\Throwable $error) {
                    // Keep the pre-dispatch marker even if persisting the error itself fails.
                    OperationLogger::failure('date_label_generation', $error);
                    return OperationResult::failure(['code' => 'label_unknown', 'message' => 'نتیجه تولید فایل مشخص نیست؛ آخرین درخواست چاپ را بررسی کنید.',
                        'job_id' => $id, 'kind' => 'unexpected_response', 'request_dispatched' => true]);
                }
            }
            if ($record['state'] === 'ready') return self::progress($id, $record);
            if ($record['pages'] >= 10000) throw self::invalid();
            $query = ParcelsListService::buildListQuery(['market_name' => $markets, 'sort' => 'desc']);
            if ($record['cursor'] !== null) $query['cursor'] = $record['cursor'];
            $body = ClientFactory::make()->get(SalamGateClient::PATH_PARCELS, $query)['body'] ?? null;
            if (!is_array($body) || !is_array($body['data'] ?? null) || count($body['data']) > 30) throw self::invalid();
            $next = $record;
            foreach ($body['data'] as $parcel) {
                if (!is_array($parcel) || !LabelEligibility::validListShape($parcel)
                    || !in_array($parcel['market']['value'] ?? null, $markets, true)) throw self::invalid();
                if (self::matches($parcel, $from, $to, $markets)) $next['ids'][] = (int) $parcel['id'];
            }
            $next['ids'] = array_values(array_unique($next['ids']));
            if (count($next['ids']) > 100) throw new SalamGateException('این بازه بیش از ۱۰۰ مرسوله دارد؛ بازه را کوتاه‌تر کنید.', 422);
            $cursor = $body['next_cursor'] ?? null;
            if ($cursor !== null && !is_string($cursor)) throw self::invalid();
            if ($cursor === null || $cursor === '') $next['state'] = $next['ids'] === [] ? 'ready' : 'verifying';
            else {
                $hash = hash('sha256', $cursor);
                if (isset($next['seen'][$hash]) || $next['pages'] >= 10000 || strlen($cursor) > 4096) throw self::invalid();
                $next['seen'][$hash] = true;
                $next['cursor'] = $cursor;
            }
            $next['pages']++;
            ConnectionCache::assertCurrentIdentity($identity);
            self::save($key, $record, $next);
            return self::progress($id, $next);
        } catch (\Throwable $error) {
            $correlation = OperationLogger::failure('date_label_discovery', $error);
            return OperationResult::failure(['code' => 'label_discovery_failed', 'message' => $error instanceof SalamGateException ? $error->firstMessage() : 'بررسی فهرست کامل نشد؛ برای ادامه دوباره اقدام کنید.',
                'job_id' => $id, 'correlation_id' => $correlation, 'request_dispatched' => false]);
        } finally { OptionLock::release($lock, $lease); }
    }

    private static function matches(array $parcel, string $from, string $to, array $markets): bool
    {
        if (!LabelEligibility::validListShape($parcel) || !in_array($parcel['market']['value'] ?? null, $markets, true)) throw self::invalid();
        if (!LabelEligibility::canPrintListItem($parcel)) return false;
        $stamp = is_string($parcel['updated_at'] ?? null) ? strtotime($parcel['updated_at']) : false;
        if ($stamp === false) throw self::invalid();
        return $stamp >= strtotime($from) && $stamp <= strtotime($to);
    }

    private static function save(string $key, array $old, array $next): void
    {
        if (!AttemptRepository::exchange($key, $old, $next)) throw new \RuntimeException('Date label checkpoint changed.');
    }

    private static function progress(string $id, array $record): array
    {
        return OperationResult::success(['job_id' => $id, 'discovery_state' => $record['state'], 'pages_checked' => $record['pages'],
            'matched_count' => count($record['ids']), 'request_dispatched' => false]);
    }

    private static function invalid(): SalamGateException
    {
        return new SalamGateException('فهرست مرسوله‌ها برای چاپ کامل و معتبر نیست؛ بررسی را ادامه دهید یا بازه را کوتاه‌تر کنید.', 422);
    }
}
