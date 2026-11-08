<?php
namespace SyncBasalam\SalamGate\Infrastructure;

use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\ParcelService;

defined('ABSPATH') || exit;

/** Additive reverse lookup. Original create/payment records remain authoritative and untouched. */
final class CreationIdentityIndex
{
    public const CURSOR = 'salam_gate_identity_backfill_v1';

    public static function remember(string $source, array $record): void
    {
        if (($record['state'] ?? '') !== 'created' || ($record['market'] ?? '') !== 'website') return;
        $parcel = ParcelService::positiveId($record['parcel_id'] ?? null);
        $external = ParcelService::positiveId($record['external_id'] ?? null);
        if ($parcel === null || $external === null || !self::validSource($source, $external)) return;
        $key = 'salam_gate_creation_index_' . $parcel;
        $next = ['source' => $source, 'external_id' => $external];
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $old = AttemptRepository::read($key);
            if ($old !== null) {
                if (!empty($old['conflict'])) return;
                if (($old['source'] ?? null) === $source && ($old['external_id'] ?? null) === $external) return;
                // Retain conflict evidence permanently, including duplicate legacy claims.
                $next = ['conflict' => true];
            }
            if (AttemptRepository::exchange($key, $old, $next)) return;
        }
        throw new \RuntimeException('Creation identity index contention.');
    }

    public static function lookup(int $parcel): ?int
    {
        $index = AttemptRepository::read('salam_gate_creation_index_' . $parcel);
        if ($index === null) return null;
        if (!empty($index['conflict'])) throw self::error();
        $external = ParcelService::positiveId($index['external_id'] ?? null);
        $source = $index['source'] ?? '';
        if ($external === null || !is_string($source) || !self::validSource($source, $external)) throw self::error();
        // A partial legacy backfill cannot conceal a conflicting row on a later page.
        $checkpoint = get_option(self::CURSOR, []);
        if (empty($checkpoint['complete'])) return null;
        $record = AttemptRepository::read($source);
        if (($record['state'] ?? '') !== 'created' || ($record['market'] ?? '') !== 'website'
            || ParcelService::positiveId($record['parcel_id'] ?? null) !== $parcel
            || ParcelService::positiveId($record['external_id'] ?? null) !== $external) throw self::error();
        return $external;
    }

    /** Caller holds the maintenance lease; progress is committed only after every row succeeds. */
    public static function backfill(int $limit = 100): array
    {
        global $wpdb;
        $state = get_option(self::CURSOR, []);
        if (!empty($state['complete'])) return $state;
        $after = is_string($state['after'] ?? null) ? $state['after'] : '';
        $limit = max(1, min(100, $limit));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, option_value FROM `{$wpdb->options}` WHERE option_name LIKE %s AND option_name > %s ORDER BY option_name ASC LIMIT %d",
            $wpdb->esc_like('salam_gate_create_') . '%', $after, $limit
        ), ARRAY_A);
        if (!is_array($rows) || !empty($wpdb->last_error)) throw new \RuntimeException('Creation backfill read failed.');
        foreach ($rows as $row) {
            $record = @unserialize($row['option_value'], ['allowed_classes' => false]);
            if (is_array($record)) self::remember($row['option_name'], $record);
            $after = $row['option_name'];
        }
        $state = ['after' => $after, 'complete' => count($rows) < $limit];
        update_option(self::CURSOR, $state, false);
        return $state;
    }

    private static function validSource(string $key, int $external): bool
    {
        return preg_match('/^salam_gate_create_(order_)?([a-f0-9]{64})$/D', $key, $match)
            && (($match[1] ?? '') !== 'order_' || $match[2] === hash('sha256', 'website:' . $external));
    }

    private static function error(): SalamGateException
    {
        return new SalamGateException('ارتباط ثبت قبلی مرسوله متعارض است؛ پرداخت انجام نمی‌شود.', 422,
            [['location' => 'payment.identity', 'message' => 'creation-index-conflict']]);
    }
}
