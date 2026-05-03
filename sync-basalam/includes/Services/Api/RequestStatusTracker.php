<?php

namespace SyncBasalam\Services\Api;

defined('ABSPATH') || exit;

class RequestStatusTracker
{
    private const OPTION_KEY = 'sync_basalam_request_status_stats';
    private const RETENTION_SECONDS = 259200;
    private const BUCKET_SECONDS = 3600;

    public static function recordWpError(\WP_Error $response, string $url = ''): string
    {
        $errorCode = (string) $response->get_error_code();
        $errorMessage = (string) $response->get_error_message();
        $category = self::categorizeWpError($errorCode, $errorMessage);

        self::recordEvent($category, [
            'url'           => $url,
            'wp_error_code' => $errorCode,
        ]);

        return $category;
    }

    public static function recordHttpStatus(int $statusCode, string $url = ''): string
    {
        $category = self::categorizeHttpStatus($statusCode);

        self::recordEvent($category, [
            'url'         => $url,
            'status_code' => $statusCode,
        ]);

        return $category;
    }

    public static function recordCategory(string $category, array $context = []): void
    {
        self::recordEvent($category, $context);
    }

    public static function getSummary(): array
    {
        $state = self::getState();
        $aggregated = [
            'total_requests' => 0,
            'categories'     => [],
            'http_statuses'  => [],
            'wp_error_codes' => [],
            'hosts'          => [],
            'hosts_by_category' => [],
        ];

        foreach ($state['buckets'] as $bucket) {
            $aggregated['total_requests'] += (int) ($bucket['total_requests'] ?? 0);
            self::mergeCounts($aggregated['categories'], $bucket['categories'] ?? []);
            self::mergeCounts($aggregated['http_statuses'], $bucket['http_statuses'] ?? []);
            self::mergeCounts($aggregated['wp_error_codes'], $bucket['wp_error_codes'] ?? []);
            self::mergeCounts($aggregated['hosts'], $bucket['hosts'] ?? []);
            self::mergeNestedCounts($aggregated['hosts_by_category'], $bucket['hosts_by_category'] ?? []);
        }

        arsort($aggregated['categories']);
        arsort($aggregated['http_statuses']);
        arsort($aggregated['wp_error_codes']);
        arsort($aggregated['hosts']);

        return [
            'window_hours'          => 72,
            'retention_seconds'     => self::RETENTION_SECONDS,
            'collected_until'       => gmdate('c'),
            'tracking_since'        => self::resolveTrackingSince($state['buckets']),
            'total_requests'        => $aggregated['total_requests'],
            'categories'            => $aggregated['categories'],
            'http_statuses'         => $aggregated['http_statuses'],
            'wp_error_codes'        => $aggregated['wp_error_codes'],
            'hosts'                 => $aggregated['hosts'],
            'hosts_by_category'     => self::sortNestedCounts($aggregated['hosts_by_category']),
            'bucket_count'          => count($state['buckets']),
            'storage_option'        => self::OPTION_KEY,
            'expires_before'        => gmdate('c', time() - self::RETENTION_SECONDS),
        ];
    }

    private static function categorizeWpError(string $errorCode, string $errorMessage): string
    {
        $haystack = strtolower($errorCode . ' ' . $errorMessage);

        if (self::containsAny($haystack, [
            'http_request_not_executed',
            'blocked requests through http',
            'blocked the http request',
            'درخواست http را بلوکه',
            'درخواست http توسط وردپرس مسدود',
        ])) {
            return 'blocked_http';
        }

        if (self::containsAny($haystack, [
            'timeout',
            'timed out',
            'operation timed out',
            'curl error 28',
            'connection timeout',
        ])) {
            return 'timeout';
        }

        if (self::containsAny($haystack, [
            'dns',
            'could not resolve host',
            'couldn\'t resolve host',
            'getaddrinfo',
            'name or service not known',
            'temporary failure in name resolution',
            'curl error 6',
        ])) {
            return 'dns_error';
        }

        if (self::containsAny($haystack, [
            'ssl',
            'tls',
            'certificate',
            'cert',
            'curl error 35',
            'curl error 51',
            'curl error 58',
            'curl error 60',
        ])) {
            return 'ssl_error';
        }

        if (self::containsAny($haystack, [
            'connection refused',
            'failed to connect',
            'connection reset',
            'socket',
            'proxy connect aborted',
            'curl error 7',
            'curl error 52',
            'curl error 56',
        ])) {
            return 'connection_error';
        }

        if (self::containsAny($haystack, [
            'network',
            'connection',
            'curl error',
        ])) {
            return 'network_error';
        }

        return 'wp_error';
    }

    private static function categorizeHttpStatus(int $statusCode): string
    {
        if (in_array($statusCode, [200, 201, 202], true)) {
            return 'success';
        }

        if ($statusCode === 0) {
            return 'invalid_response';
        }

        if (in_array($statusCode, [408, 504], true)) {
            return 'timeout';
        }

        if ($statusCode === 429) {
            return 'rate_limit';
        }

        if ($statusCode === 401) {
            return 'unauthorized';
        }

        if (in_array($statusCode, [500, 502, 503], true)) {
            return 'server_error';
        }

        if ($statusCode >= 400 && $statusCode < 500) {
            return 'client_error';
        }

        if ($statusCode >= 500) {
            return 'server_error';
        }

        return 'unexpected_status';
    }

    private static function recordEvent(string $category, array $context = []): void
    {
        $state = self::getState();
        $bucketKey = self::getBucketKey();

        if (!isset($state['buckets'][$bucketKey])) {
            $state['buckets'][$bucketKey] = [
                'total_requests' => 0,
                'categories'     => [],
                'http_statuses'  => [],
                'wp_error_codes' => [],
                'hosts'          => [],
                'hosts_by_category' => [],
            ];
        }

        $state['buckets'][$bucketKey]['total_requests']++;
        self::incrementCount($state['buckets'][$bucketKey]['categories'], $category);

        if (array_key_exists('status_code', $context)) {
            self::incrementCount($state['buckets'][$bucketKey]['http_statuses'], (string) $context['status_code']);
        }

        if (!empty($context['wp_error_code'])) {
            self::incrementCount($state['buckets'][$bucketKey]['wp_error_codes'], (string) $context['wp_error_code']);
        }

        $host = self::extractHost($context['url'] ?? '');
        if ($host !== null) {
            self::incrementCount($state['buckets'][$bucketKey]['hosts'], $host);
            if (!isset($state['buckets'][$bucketKey]['hosts_by_category'][$host])) {
                $state['buckets'][$bucketKey]['hosts_by_category'][$host] = [];
            }
            self::incrementCount($state['buckets'][$bucketKey]['hosts_by_category'][$host], $category);
        }

        update_option(self::OPTION_KEY, $state, false);
    }

    private static function getState(): array
    {
        $state = get_option(self::OPTION_KEY, []);

        if (!is_array($state)) {
            $state = [];
        }

        $originalBuckets = $state['buckets'] ?? [];
        $state['buckets'] = self::pruneBuckets($originalBuckets);

        if ($state['buckets'] !== $originalBuckets) {
            update_option(self::OPTION_KEY, $state, false);
        }

        return $state;
    }

    private static function pruneBuckets(array $buckets): array
    {
        $minTimestamp = time() - self::RETENTION_SECONDS;

        foreach ($buckets as $timestamp => $bucket) {
            if ((int) $timestamp < $minTimestamp) {
                unset($buckets[$timestamp]);
            }
        }

        ksort($buckets);

        return $buckets;
    }

    private static function getBucketKey(): int
    {
        return (int) (floor(time() / self::BUCKET_SECONDS) * self::BUCKET_SECONDS);
    }

    private static function incrementCount(array &$counts, string $key): void
    {
        if (!isset($counts[$key])) {
            $counts[$key] = 0;
        }

        $counts[$key]++;
    }

    private static function mergeCounts(array &$target, array $source): void
    {
        foreach ($source as $key => $count) {
            if (!isset($target[$key])) {
                $target[$key] = 0;
            }

            $target[$key] += (int) $count;
        }
    }

    private static function mergeNestedCounts(array &$target, array $source): void
    {
        foreach ($source as $groupKey => $counts) {
            if (!isset($target[$groupKey]) || !is_array($target[$groupKey])) {
                $target[$groupKey] = [];
            }

            self::mergeCounts($target[$groupKey], is_array($counts) ? $counts : []);
        }
    }

    private static function sortNestedCounts(array $nestedCounts): array
    {
        foreach ($nestedCounts as &$counts) {
            if (is_array($counts)) {
                arsort($counts);
            }
        }
        unset($counts);

        uksort($nestedCounts, static function ($left, $right) use ($nestedCounts) {
            $leftTotal = array_sum($nestedCounts[$left] ?? []);
            $rightTotal = array_sum($nestedCounts[$right] ?? []);

            if ($leftTotal === $rightTotal) {
                return strcmp((string) $left, (string) $right);
            }

            return $rightTotal <=> $leftTotal;
        });

        return $nestedCounts;
    }

    private static function resolveTrackingSince(array $buckets): ?string
    {
        $firstBucket = array_key_first($buckets);

        if ($firstBucket === null) {
            return null;
        }

        return gmdate('c', (int) $firstBucket);
    }

    private static function extractHost(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return null;
        }

        return strtolower($host);
    }

    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (strpos($haystack, strtolower($needle)) !== false) {
                return true;
            }
        }

        return false;
    }
}
