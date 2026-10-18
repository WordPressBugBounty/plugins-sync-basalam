<?php

namespace SyncBasalam\SalamGate\Services;

use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Utils\Digits;

defined('ABSPATH') || exit;

/** Postal navigation only; no tracking API, credentials, order or payment writes. */
final class TrackingService
{
    public static function normalizeCode(string $input): string
    {
        // Preserve leading zeros. Accept copied whitespace and Persian/Arabic digits,
        // but never turn arbitrary text, URLs or punctuation into a tracking code.
        $code = preg_replace('/[\s\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', '', Digits::toEnglish($input));
        if (!is_string($code) || !preg_match('/^[0-9]{24}$/D', $code)) {
            throw new SalamGateException('کد رهگیری باید شامل ۲۴ رقم باشد.', 422, [], SalamGateException::KIND_VALIDATION);
        }
        return $code;
    }

    public function lookup(string $input): array
    {
        $code = self::normalizeCode($input);
        return [
            'tracking_code' => $code,
            'post_tracking_url' => ParcelService::postTrackingUrl($code),
            'provider' => 'post',
        ];
    }

    /** Flatten every provider group and return newest dated events first. */
    public static function normalizeEvents($body): array
    {
        if ($body === null || $body === []) return [];
        if (!is_array($body)) self::invalidResponse();
        $groups = array_keys($body) === range(0, count($body) - 1) ? [$body] : array_values($body);
        $events = [];
        $seen = [];
        foreach ($groups as $group) {
            if (!is_array($group)) self::invalidResponse();
            foreach ($group as $event) {
                if (!is_array($event) || !is_string($event['state'] ?? null)
                    || !is_array($event['status'] ?? null) || !is_string($event['status']['title'] ?? null)
                    || (isset($event['description']) && !is_string($event['description']))
                    || (isset($event['occurred_at']) && !is_string($event['occurred_at']))) {
                    self::invalidResponse();
                }
                $at = $event['occurred_at'] ?? null;
                // An invalid/missing timestamp must never silently become "now".
                $timestamp = null;
                if (is_string($at) && $at !== '') {
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $at)) self::invalidResponse();
                    try {
                        $date = new \DateTimeImmutable($at);
                        $errors = \DateTimeImmutable::getLastErrors();
                        if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) self::invalidResponse();
                        $timestamp = $date->getTimestamp();
                    } catch (\Throwable $error) { self::invalidResponse(); }
                }
                $normalized = [
                    'state' => $event['state'],
                    'status_title' => trim($event['status']['title']),
                    'description' => $event['description'] ?? null,
                    'occurred_at' => $at ?: null,
                ];
                // Only exact duplicates are removed; distinct same-time events remain.
                $key = json_encode([$event['id'] ?? null, $normalized]);
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $events[] = ['data' => $normalized, 'time' => $timestamp, 'position' => count($events)];
            }
        }
        usort($events, static function (array $a, array $b): int {
            if ($a['time'] === $b['time']) return $a['position'] <=> $b['position'];
            if ($a['time'] === null) return 1;
            if ($b['time'] === null) return -1;
            return $b['time'] <=> $a['time'];
        });
        return array_column($events, 'data');
    }

    private static function invalidResponse(): void
    {
        throw new SalamGateException('سابقه معتبر از سلام‌گیت دریافت نشد؛ دوباره تلاش کنید.', 200, [], SalamGateException::KIND_UNEXPECTED_RESPONSE);
    }
}
